<?php

namespace App\Services;

use App\Models\Archive;
use App\Models\AgentArchiveLink;
use Illuminate\Support\Facades\Log;

class ArchiveOrchestrator
{
    /** Rank used to prevent a lower-confidence source from overwriting a higher-confidence match. */
    protected const CONFIDENCE_RANK = ['tentative' => 1, 'positive' => 2];

    protected array $stats = [
        'lccn_processed' => 0,
        'name_processed' => 0,
        'records_created' => 0,
        'records_updated' => 0,
        'records_preserved' => 0,
        'errors' => 0,
    ];

    public function __construct(
        protected AlmaSruClient $alma,
        protected PrimoClient $primo,
        protected ContentDmClient $contentdm,
    ) {
    }

    /** Counts for the current process's sync activity: created/updated/preserved records, errors. */
    public function stats(): array
    {
        return $this->stats;
    }

    public function syncByLccn(array $agentMap): void
    {
        foreach ($agentMap as $lccn => $agentIds) {
            $this->stats['lccn_processed']++;

            try {
                $records = $this->alma->searchByLccn($lccn);
            } catch (\Exception $e) {
                report($e);
                $this->stats['errors']++;
                continue;
            }

            foreach ($records as $record) {
                $lccns = !empty($record['lccns']) ? $record['lccns'] : [$lccn];

                $this->storeRecord(
                    title: $record['title'],
                    creator: $record['creator'] ?? null,
                    dateDisplay: $record['date_display'] ?? null,
                    dateStart: $record['date_start'] ?? null,
                    dateEnd: $record['date_end'] ?? null,
                    format: $record['format'] ?? null,
                    subjects: $record['subjects'] ?? [],
                    language: $record['language'] ?? null,
                    description: $record['description'] ?? null,
                    lccn: $lccns,
                    mmsId: $record['mms_id'],
                    fileUrls: array_column($record['file_urls'], 'url'),
                    collectionType: $record['collection_type'] ?? null,
                    recordType: $record['record_type'] ?? null,
                    matchType: 'lccn',
                    matchConfidence: 'positive',
                    sourceMeta: ['alma' => $record],
                    agentCitiIds: $agentIds,
                    hasMedia: $record['has_media'] ?? false,
                );
            }

            sleep(1);
        }
    }

    public function syncByName(array $nameMap): void
    {
        foreach ($nameMap as $citiId => $title) {
            $this->stats['name_processed']++;

            try {
                $results = $this->primo->searchByName($title);
            } catch (\Exception $e) {
                report($e);
                $this->stats['errors']++;
                continue;
            }

            foreach ($results as $result) {
                // Primo can omit mms_id — skip to avoid dedup collisions
                if (empty($result['mms_id'])) {
                    continue;
                }

                // Validate against creator (the field Primo searched on), not title
                if (stripos($result['creator'] ?? '', $title) === false) {
                    continue;
                }

                $this->storeRecord(
                    title: $result['title'],
                    creator: $result['creator'] ?? null,
                    dateDisplay: $result['date_display'] ?? null,
                    dateStart: $result['date_start'] ?? null,
                    dateEnd: $result['date_end'] ?? null,
                    format: $result['format'] ?? null,
                    subjects: $result['subjects'] ?? [],
                    language: $result['language'] ?? null,
                    description: $result['description'] ?? null,
                    lccn: [$result['lccn']],
                    mmsId: $result['mms_id'],
                    fileUrls: $result['files'] ?? [],
                    collectionType: null,
                    recordType: null,
                    matchType: 'name',
                    matchConfidence: 'tentative',
                    sourceMeta: ['primo' => $result],
                    agentCitiIds: [$citiId],
                    hasMedia: $result['has_media'] ?? true,
                );
            }

            sleep(1);
        }
    }

    protected function storeRecord(
        string $title,
        ?string $creator,
        ?string $dateDisplay,
        ?int $dateStart,
        ?int $dateEnd,
        ?string $format,
        array $subjects,
        ?string $language,
        ?string $description,
        array $lccn,
        string $mmsId,
        array $fileUrls,
        string $matchType,
        string $matchConfidence,
        array $sourceMeta,
        array $agentCitiIds,
        ?string $collectionType = null,
        ?string $recordType = null,
        bool $hasMedia = false,
    ): void {
        // Strip MARC delimiter artifacts (Alma already does this, Primo doesn't)
        if ($creator !== null) {
            $creator = preg_replace('/\$\$[A-Z].*/s', '', $creator);
            $creator = rtrim($creator, " \t\n\r\0\x0B,");
        }

        // Strip ISBD punctuation from title
        $title = rtrim($title, ':/=');
        if (preg_match('/\.$/', $title) && !preg_match('/\b[A-Z]\.$/', $title)) {
            $title = rtrim($title, '.');
        }
        $title = trim($title);
        $contentdmMeta = null;
        foreach ($fileUrls as $url) {
            $parsed = $this->contentdm->parseUrl($url);
            if ($parsed) {
                try {
                    $contentdmMeta = $this->contentdm->getItem($parsed['collection'], $parsed['id']);
                } catch (\Exception $e) {
                    report($e);
                }
                break;
            }
        }

        // Belt-and-suspenders: ContentDM found → force archives
        if ($contentdmMeta !== null && $collectionType !== 'archives') {
            $collectionType = 'archives';
        }

        $existing = Archive::where('mms_id', $mmsId)->first();

        if ($existing && $this->isConfidenceDowngrade($existing->match_confidence, $matchConfidence)) {
            // Never downgrade match confidence
            $this->stats['records_preserved']++;
            Log::info('ArchiveOrchestrator: preserving existing higher-confidence archive record', [
                'mms_id' => $mmsId,
                'existing_match_confidence' => $existing->match_confidence,
                'incoming_match_confidence' => $matchConfidence,
            ]);
            $archive = $existing;
        } else {
            $archive = Archive::updateOrCreate(
                ['mms_id' => $mmsId],
                [
                    'title' => $title,
                    'creator' => $creator,
                    'date_display' => $dateDisplay,
                    'date_start' => $dateStart,
                    'date_end' => $dateEnd,
                    'format' => $format,
                    'subjects' => $subjects,
                    'language' => $language,
                    'description' => $description,
                    'lccn' => $lccn,
                    'mms_id' => $mmsId,
                    'contentdm_collection' => $contentdmMeta['contentdm_collection'] ?? null,
                    'contentdm_id' => $contentdmMeta['contentdm_id'] ?? null,
                    'contentdm_url' => $contentdmMeta['contentdm_url'] ?? null,
                    'web_url' => $contentdmMeta['contentdm_url'] ?? ($fileUrls[0] ?? null),
                    'collection_type' => $collectionType,
                    'record_type' => $recordType,
                    'match_type' => $matchType,
                    'match_confidence' => $matchConfidence,
                    'has_media' => $hasMedia,
                    'metadata' => $sourceMeta + ['contentdm' => $contentdmMeta],
                ]
            );

            $archive->wasRecentlyCreated
                ? $this->stats['records_created']++
                : $this->stats['records_updated']++;
        }

        foreach ($agentCitiIds as $citiId) {
            AgentArchiveLink::updateOrCreate(
                ['agent_citi_id' => $citiId, 'archive_id' => $archive->id],
                ['match_type' => $matchType, 'match_confidence' => $matchConfidence],
            );
        }

        sleep(1);
    }

    protected function isConfidenceDowngrade(?string $existingConfidence, string $incomingConfidence): bool
    {
        $existingRank = self::CONFIDENCE_RANK[$existingConfidence] ?? 0;
        $incomingRank = self::CONFIDENCE_RANK[$incomingConfidence] ?? 0;

        return $existingRank > $incomingRank;
    }
}
