<?php

namespace App\Services;

use App\Models\Archive;
use App\Models\AgentArchiveLink;
use Illuminate\Support\Facades\DB;

class ArchiveOrchestrator
{
    public function __construct(
        protected AlmaSruClient $alma,
        protected PrimoClient $primo,
        protected ContentDmClient $contentdm,
    ) {}

    /**
     * Sync archive records by LCCN.
     * $agentMap: associative array of lccn => [agent_citi_ids].
     */
    public function syncByLccn(array $agentMap): void
    {
        $allLccns = array_keys($agentMap);
        $batches = array_chunk($allLccns, config('archives.batch_size', 50));

        foreach ($batches as $batch) {
            try {
                $records = $this->alma->searchByLccnBatch($batch);
            } catch (\Exception $e) {
                report($e);
                continue;
            }

            foreach ($records as $record) {
                $this->processAlmaRecord($record, $agentMap);
            }
        }
    }

    /**
     * Sync archive records by artist name.
     * $nameMap: associative array of agent_citi_id => agent_title.
     */
    public function syncByName(array $nameMap): void
    {
        foreach ($nameMap as $citiId => $title) {
            try {
                $results = $this->primo->searchByName($title);
            } catch (\Exception $e) {
                report($e);
                continue;
            }

            foreach ($results as $result) {
                $this->processPrimoResult($result, [$citiId], 'tentative');
            }

            // Rate limit: space requests by 1 second
            sleep(1);
        }
    }

    /**
     * Process a record from Alma SRU response.
     */
    protected function processAlmaRecord(array $record, array $agentMap): void
    {
        // Find which agent CITI IDs map to the LCCNs in this record
        $agentCitiIds = [];
        foreach ($record['lccns'] as $lccn) {
            if (isset($agentMap[$lccn])) {
                $agentCitiIds = array_merge($agentCitiIds, $agentMap[$lccn]);
            }
        }
        $agentCitiIds = array_unique($agentCitiIds);

        // Resolve ContentDM metadata for the first file URL
        $contentdmMeta = null;
        foreach ($record['file_urls'] as $fileUrl) {
            $parsed = $this->contentdm->parseUrl($fileUrl['url']);
            if ($parsed) {
                try {
                    $contentdmMeta = $this->contentdm->getItem($parsed['collection'], $parsed['id']);
                } catch (\Exception $e) {
                    report($e);
                }
                break;
            }
        }

        $this->upsertArchive([
            'title' => $record['title'],
            'lccn' => $record['lccns'],
            'mms_id' => $record['mms_id'],
            'contentdm_collection' => $contentdmMeta['contentdm_collection'] ?? null,
            'contentdm_id' => $contentdmMeta['contentdm_id'] ?? null,
            'contentdm_url' => $contentdmMeta['contentdm_url'] ?? ($record['file_urls'][0]['url'] ?? null),
            'web_url' => null,
            'match_type' => 'lccn',
            'match_confidence' => 'positive',
            'metadata' => [
                'alma' => $record,
                'contentdm' => $contentdmMeta,
            ],
        ], $agentCitiIds);

        // Rate limit ContentDM calls
        sleep(1);
    }

    /**
     * Process a result from Primo search.
     */
    protected function processPrimoResult(array $result, array $agentCitiIds, string $confidence): void
    {
        // Resolve ContentDM metadata from the primo file URL
        $contentdmMeta = null;
        foreach ($result['files'] as $fileUrl) {
            $parsed = $this->contentdm->parseUrl($fileUrl);
            if ($parsed) {
                try {
                    $contentdmMeta = $this->contentdm->getItem($parsed['collection'], $parsed['id']);
                } catch (\Exception $e) {
                    report($e);
                }
                break;
            }
        }

        $this->upsertArchive([
            'title' => $result['title'],
            'lccn' => [$result['lccn']],
            'mms_id' => $result['mms_id'],
            'contentdm_collection' => $contentdmMeta['contentdm_collection'] ?? null,
            'contentdm_id' => $contentdmMeta['contentdm_id'] ?? null,
            'contentdm_url' => $contentdmMeta['contentdm_url'] ?? ($result['files'][0] ?? null),
            'web_url' => null,
            'match_type' => 'name',
            'match_confidence' => $confidence,
            'metadata' => [
                'primo' => $result,
                'contentdm' => $contentdmMeta,
            ],
        ], $agentCitiIds);

        // Rate limit ContentDM calls
        sleep(1);
    }

    /**
     * Upsert an archive record by MMS ID and create agent links.
     */
    protected function upsertArchive(array $data, array $agentCitiIds): void
    {
        if (empty($data['mms_id'])) {
            return;
        }

        $archive = Archive::updateOrCreate(
            ['mms_id' => $data['mms_id']],
            $data
        );

        foreach ($agentCitiIds as $citiId) {
            AgentArchiveLink::updateOrCreate(
                [
                    'agent_citi_id' => $citiId,
                    'archive_id' => $archive->id,
                ],
                [
                    'match_type' => $data['match_type'],
                    'match_confidence' => $data['match_confidence'],
                ]
            );
        }
    }
}
