<?php

namespace App\Services;

use App\Models\Archive;
use App\Models\AgentArchiveLink;

class ArchiveOrchestrator
{
    public function __construct(
        protected AlmaSruClient $alma,
        protected PrimoClient $primo,
        protected ContentDmClient $contentdm,
    ) {}

    public function syncByLccn(array $agentMap): void
    {
        foreach ($agentMap as $lccn => $agentIds) {
            try {
                $records = $this->alma->searchByLccn($lccn);
            } catch (\Exception $e) {
                report($e);
                continue;
            }

            foreach ($records as $record) {
                $lccns = !empty($record['lccns']) ? $record['lccns'] : [$lccn];

                $this->storeRecord(
                    title: $record['title'],
                    lccn: $lccns,
                    mmsId: $record['mms_id'],
                    fileUrls: array_column($record['file_urls'], 'url'),
                    matchType: 'lccn',
                    matchConfidence: 'positive',
                    sourceMeta: ['alma' => $record],
                    agentCitiIds: $agentIds,
                );
            }

            sleep(1);
        }
    }

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
                if (stripos($result['title'], $title) === false) {
                    continue;
                }

                $this->storeRecord(
                    title: $result['title'],
                    lccn: [$result['lccn']],
                    mmsId: $result['mms_id'],
                    fileUrls: $result['files'] ?? [],
                    matchType: 'name',
                    matchConfidence: 'tentative',
                    sourceMeta: ['primo' => $result],
                    agentCitiIds: [$citiId],
                );
            }

            sleep(1);
        }
    }

    protected function storeRecord(
        string $title,
        array $lccn,
        string $mmsId,
        array $fileUrls,
        string $matchType,
        string $matchConfidence,
        array $sourceMeta,
        array $agentCitiIds,
    ): void {
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

        $archive = Archive::updateOrCreate(
            ['mms_id' => $mmsId],
            [
                'title' => $title,
                'lccn' => $lccn,
                'mms_id' => $mmsId,
                'contentdm_collection' => $contentdmMeta['contentdm_collection'] ?? null,
                'contentdm_id' => $contentdmMeta['contentdm_id'] ?? null,
                'contentdm_url' => $contentdmMeta['contentdm_url'] ?? ($fileUrls[0] ?? null),
                'web_url' => null,
                'match_type' => $matchType,
                'match_confidence' => $matchConfidence,
                'metadata' => $sourceMeta + ['contentdm' => $contentdmMeta],
            ]
        );

        foreach ($agentCitiIds as $citiId) {
            AgentArchiveLink::updateOrCreate(
                ['agent_citi_id' => $citiId, 'archive_id' => $archive->id],
                ['match_type' => $matchType, 'match_confidence' => $matchConfidence],
            );
        }

        sleep(1);
    }
}
