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
            'lccn' => $archive->lccn,
            'mms_id' => $archive->mms_id,
            'contentdm_collection' => $archive->contentdm_collection,
            'contentdm_id' => $archive->contentdm_id,
            'contentdm_url' => $archive->contentdm_url,
            'web_url' => $archive->web_url,
            'match_type' => $archive->match_type,
            'match_confidence' => $archive->match_confidence,
            'metadata' => $archive->metadata,
            'agent_citi_ids' => $archive->agentLinks->pluck('agent_citi_id')->toArray(),
            'source_updated_at' => $archive->updated_at?->toIso8601String(),
        ];
    }
}
