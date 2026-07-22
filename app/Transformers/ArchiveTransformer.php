<?php

namespace App\Transformers;

use League\Fractal\TransformerAbstract;
use App\Models\Archive;

class ArchiveTransformer extends TransformerAbstract
{
    public function transform(Archive $archive): array
    {
        return [
            'id' => $archive->id,
            'title' => $archive->title,
            'creator' => $archive->creator,
            'date_display' => $archive->date_display,
            'date_start' => $archive->date_start,
            'date_end' => $archive->date_end,
            'format' => $archive->format,
            'collection_type' => $archive->collection_type,
            'record_type' => $archive->record_type,
            'has_media' => $archive->has_media,
            'subjects' => $archive->subjects,
            'language' => $archive->language,
            'description' => $archive->description,
            'lccn' => $archive->lccn,
            'mms_id' => $archive->mms_id,
            'contentdm_collection' => $archive->contentdm_collection,
            'contentdm_id' => $archive->contentdm_id,
            'contentdm_url' => $archive->contentdm_url,
            'web_url' => $archive->web_url,
            'match_type' => $archive->match_type,
            'match_confidence' => $archive->match_confidence,
            'metadata' => $this->stripRawMarcxml($archive->metadata),
            'agent_citi_ids' => $archive->agentLinks->pluck('agent_citi_id')->toArray(),
            'source_updated_at' => $archive->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Remove raw MARC XML from metadata before sending to the aggregator.
     * The normalized output covers everything; raw_marcxml is large and redundant for downstream consumers.
     * Kept in the local DB for debugging.
     */
    private function stripRawMarcxml(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        if (isset($metadata['alma']['raw_marcxml'])) {
            unset($metadata['alma']['raw_marcxml']);
        }

        return $metadata;
    }
}
