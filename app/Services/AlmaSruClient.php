<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class AlmaSruClient
{
    protected Client $http;
    protected string $baseUrl;
    protected MarcNormalizer $normalizer;
    protected MarcClassifier $classifier;

    public function __construct(?Client $http = null)
    {
        $this->baseUrl = config('archives.alma_sru_base');
        $this->http = $http ?? new Client(['timeout' => 30]);
        $this->normalizer = new MarcNormalizer();
        $this->classifier = new MarcClassifier();
    }

    public function searchByLccn(string $lccn): array
    {
        // Sanitize LCCN to prevent CQL injection
        $sanitized = preg_replace('/[^a-zA-Z0-9]/', '', $lccn);
        if ($sanitized !== $lccn) {
            Log::warning('AlmaSruClient: LCCN contained unexpected characters and was sanitized', [
                'original' => $lccn,
                'sanitized' => $sanitized,
            ]);
        }

        // Uses alma.authority_id (NOT alma.lccn) for Wikidata-formatted LCCNs
        return $this->executeSearch('alma.authority_id=' . $sanitized);
    }

    protected function executeSearch(string $query): array
    {
        $batchSize = config('archives.batch_size', 50);

        $response = $this->http->get($this->baseUrl, [
            'query' => [
                'version' => '1.2',
                'operation' => 'searchRetrieve',
                'recordSchema' => 'marcxml',
                'query' => $query,
                'maximumRecords' => $batchSize,
            ],
        ]);

        $xml = new SimpleXMLElement($response->getBody()->getContents());
        $xml->registerXPathNamespace('srw', 'http://www.loc.gov/zing/srw/');
        $xml->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');

        $totalNode = $xml->xpath('//srw:numberOfRecords');
        $total = !empty($totalNode) ? (int) $totalNode[0] : 0;
        if ($total > $batchSize) {
            Log::warning('AlmaSruClient: result truncated — more records matched than were fetched', [
                'query' => $query,
                'total_matched' => $total,
                'fetched' => $batchSize,
            ]);
        }

        $results = [];
        foreach ($xml->xpath('//srw:recordData/marc:record') as $record) {
            $record->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');
            $mmsId = (string) ($record->xpath('marc:controlfield[@tag="001"]')[0] ?? '');
            if (empty($mmsId)) {
                continue;
            }

            $title = (string) ($record->xpath('marc:datafield[@tag="245"]/marc:subfield[@code="a"]')[0] ?? '');
            $title = $this->sanitizeTitle($title);

            $lccnFields = [];
            foreach ($record->xpath('marc:datafield[@tag="010"]/marc:subfield[@code="a"]') as $subfield) {
                $lccnFields[] = (string) $subfield;
            }

            // Extract file URLs first — needed for ContentDM-aware collection_type
            $fileUrls = [];
            foreach ($record->xpath('marc:datafield[@tag="856"]') as $datafield) {
                $datafield->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');
                $u = (string) ($datafield->xpath('marc:subfield[@code="u"]')[0] ?? '');
                $z = (string) ($datafield->xpath('marc:subfield[@code="z"]')[0] ?? '');
                if ($u) {
                    $fileUrls[] = ['url' => $u, 'label' => $z];
                }
            }

            $collectionType = $this->extractCollectionType($record, $fileUrls);

            // Strip trailing commas and MARC subfield delimiter artifacts
            $creator = $this->extractMarcField($record, '100', 'a')
                ?: $this->extractMarcField($record, '700', 'a');
            if ($creator !== null) {
                $creator = $this->stripMarcDelimiters($creator);
            }

            $dateDisplay = $this->extractMarcField($record, '260', 'c')
                ?: $this->extractControlfieldDate($record);
            $dateDisplay = $this->sanitizeDateDisplay($dateDisplay);

            $dateStart = $this->parseDateStart($dateDisplay);
            $dateEnd = $this->parseDateEnd($dateDisplay);

            $format = $this->extractMarcField($record, '655', 'a');

            $subjects = array_merge(
                $this->extractMarcFields($record, '600', 'a'),
                $this->extractMarcFields($record, '610', 'a'),
                $this->extractMarcFields($record, '650', 'a'),
                $this->extractMarcFields($record, '651', 'a')
            );

            $language = $this->extractControlfieldLanguage($record)
                ?: $this->extractMarcField($record, '041', 'a');

            $description = $this->extractMarcField($record, '300', 'a')
                ?: $this->extractMarcField($record, '520', 'a');

            $leader = $this->parseLeader($record);

            $results[$mmsId] = [
                'mms_id' => $mmsId,
                'title' => $title,
                'creator' => $creator,
                'date_display' => $dateDisplay,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'format' => $format,
                'subjects' => array_values(array_unique($subjects)),
                'language' => $language,
                'description' => $description,
                'lccns' => $lccnFields,
                'file_urls' => $fileUrls,
                'collection_type' => $collectionType,
                'record_type' => $this->extractRecordType($record, $collectionType),
                'leader' => $leader,
                'raw_marcxml' => $record->asXML(),
                'normalized' => $this->normalizer->normalize($record),
                'has_media' => !empty($fileUrls),
            ];
        }

        return $results;
    }

    protected function extractMarcField(SimpleXMLElement $record, string $tag, string $code): ?string
    {
        $field = $record->xpath("marc:datafield[@tag='{$tag}']/marc:subfield[@code='{$code}']");
        return !empty($field) ? trim((string) $field[0]) : null;
    }

    protected function extractMarcFields(SimpleXMLElement $record, string $tag, string $code): array
    {
        $fields = $record->xpath("marc:datafield[@tag='{$tag}']/marc:subfield[@code='{$code}']");
        return array_map(fn ($f) => trim((string) $f), $fields);
    }

    protected function extractControlfieldDate(SimpleXMLElement $record): ?string
    {
        $field008 = $record->xpath("marc:controlfield[@tag='008']");
        if (empty($field008)) {
            return null;
        }
        $val = (string) $field008[0];
        if (strlen($val) < 14) {
            return null;
        }
        $date = trim(substr($val, 7, 4));
        // Date2 (11-14) for range end
        $date2 = trim(substr($val, 11, 4));
        if ($date2 && $date2 !== '    ' && $date2 !== 'uuuu') {
            return $date . '-' . $date2;
        }
        return $date ?: null;
    }

    protected function extractControlfieldLanguage(SimpleXMLElement $record): ?string
    {
        $field008 = $record->xpath("marc:controlfield[@tag='008']");
        if (empty($field008)) {
            return null;
        }
        $val = (string) $field008[0];
        if (strlen($val) < 38) {
            return null;
        }
        $lang = trim(substr($val, 35, 3));
        return $lang !== '' && $lang !== '   ' ? $lang : null;
    }

    // Thin wrappers delegating to MarcClassifier for shared parsing logic
    protected function extractCollectionType(SimpleXMLElement $record, array $fileUrls = []): ?string
    {
        return $this->classifier->collectionType($record);
    }

    protected function extractRecordType(SimpleXMLElement $record, ?string $collectionType): ?string
    {
        return $this->classifier->recordType($record, $collectionType);
    }

    protected function parseLeader(SimpleXMLElement $record): ?array
    {
        return $this->classifier->parseLeader($record);
    }

    // Strip MARC subfield delimiter artifacts and trailing punctuation
    private function stripMarcDelimiters(string $value): string
    {
        // Strip $$ followed by uppercase letter subfield code and everything after
        $value = preg_replace('/\$\$[A-Z].*/s', '', $value);

        // Strip trailing commas and whitespace
        return rtrim($value, " \t\n\r\0\x0B,");
    }

    protected function sanitizeDateDisplay(?string $dateDisplay): ?string
    {
        if ($dateDisplay === null) {
            return null;
        }

        // MARC fill characters (|, #) and unknown digit markers (u) → unusable
        if (preg_match('/[#|u]/', $dateDisplay)) {
            return null;
        }

        // 9999 is MARC open-ended; meaningless as a display value
        if (str_contains($dateDisplay, '9999')) {
            return null;
        }

        // Strip cataloging brackets e.g. [1994] → 1994
        $dateDisplay = str_replace(['[', ']'], '', $dateDisplay);

        // Strip copyright marker: ", ©1994." or "©1994"
        $dateDisplay = preg_replace('/,\s*©[^,]*/', '', $dateDisplay);
        $dateDisplay = preg_replace('/©[^,]*/', '', $dateDisplay);

        // Strip leading "c" (MARC copyright prefix) when followed by digits
        $dateDisplay = preg_replace('/^c(?=\d)/', '', $dateDisplay);

        // Strip trailing punctuation
        $dateDisplay = rtrim($dateDisplay, " .\t\n\r\0\x0B,");

        // If nothing useful remains after cleanup
        $trimmed = trim($dateDisplay);
        if ($trimmed === '' || $trimmed === '?' || $trimmed === '-') {
            return null;
        }

        return $trimmed;
    }

    // Strip MARC ISBD punctuation from title subfield $a
    private function sanitizeTitle(string $title): string
    {
        // Strip trailing ISBD separators
        $title = rtrim($title);
        $title = rtrim($title, ':/=');

        // Also strip trailing slash with preceding space: "title /" → "title"
        $title = preg_replace('/\s+\/$/', '', $title);

        // Strip trailing period unless it looks like an abbreviation (single letter. or initials)
        if (preg_match('/\.$/', $title) && !preg_match('/\b[A-Z]\.$/', $title)) {
            $title = rtrim($title, '.');
        }

        return trim($title);
    }

    protected function parseDateStart(?string $dateDisplay): ?int
    {
        if (!$dateDisplay) {
            return null;
        }
        // Handle ranges like "1900-1950" or single years "1920"
        if (preg_match('/^(\d{4})/', trim($dateDisplay), $m)) {
            return (int) $m[1];
        }
        return null;
    }

    protected function parseDateEnd(?string $dateDisplay): ?int
    {
        if (!$dateDisplay) {
            return null;
        }
        if (preg_match('/[-\/](\d{4})/', trim($dateDisplay), $m)) {
            return (int) $m[1];
        }
        // Single year: start = end
        if (preg_match('/^(\d{4})$/', trim($dateDisplay), $m)) {
            return (int) $m[1];
        }
        return null;
    }
}
