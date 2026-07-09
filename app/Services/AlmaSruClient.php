<?php

namespace App\Services;

use GuzzleHttp\Client;
use SimpleXMLElement;

class AlmaSruClient
{
    protected Client $http;
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('archives.alma_sru_base');
        $this->http = new Client(['timeout' => 30]);
    }

    /**
     * Search by single LCCN. Returns array of {mms_id, title, lccns[], file_urls[]}.
     */
    public function searchByLccn(string $lccn): array
    {
        return $this->searchByLccnBatch([$lccn]);
    }

    /**
     * Search by multiple LCCNs (up to 50 OR'd together).
     */
    public function searchByLccnBatch(array $lccns): array
    {
        $clauses = array_map(fn ($l) => 'alma.lccn="' . $l . '"', $lccns);
        $query = implode(' or ', $clauses);

        $response = $this->http->get($this->baseUrl, [
            'query' => [
                'version' => '1.2',
                'operation' => 'searchRetrieve',
                'recordSchema' => 'marcxml',
                'query' => $query,
                'maximumRecords' => config('archives.batch_size', 50),
            ],
        ]);

        $xml = new SimpleXMLElement($response->getBody()->getContents());
        $xml->registerXPathNamespace('srw', 'http://www.loc.gov/zing/srw/');
        $xml->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');

        $results = [];
        foreach ($xml->xpath('//srw:recordData/marc:record') as $record) {
            $record->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');
            $mmsId = (string) ($record->xpath('marc:controlfield[@tag="001"]')[0] ?? '');
            if (empty($mmsId)) {
                continue;
            }

            $title = (string) ($record->xpath('marc:datafield[@tag="245"]/marc:subfield[@code="a"]')[0] ?? '');

            $lccnFields = [];
            foreach ($record->xpath('marc:datafield[@tag="010"]/marc:subfield[@code="a"]') as $subfield) {
                $lccnFields[] = (string) $subfield;
            }

            $fileUrls = [];
            foreach ($record->xpath('marc:datafield[@tag="856"]') as $datafield) {
                $datafield->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');
                $u = (string) ($datafield->xpath('marc:subfield[@code="u"]')[0] ?? '');
                $z = (string) ($datafield->xpath('marc:subfield[@code="z"]')[0] ?? '');
                if ($u) {
                    $fileUrls[] = ['url' => $u, 'label' => $z];
                }
            }

            $results[$mmsId] = [
                'mms_id' => $mmsId,
                'title' => $title,
                'lccns' => $lccnFields,
                'file_urls' => $fileUrls,
            ];
        }

        return $results;
    }
}
