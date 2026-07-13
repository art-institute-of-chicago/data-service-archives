<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Api\Agent as AgentApi;
use App\Services\ArchiveOrchestrator;

class SyncArtistsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ArchiveOrchestrator $orchestrator): void
    {
        $api = new AgentApi();
        $lccnMap = [];
        $nameMap = [];
        $page = 1;

        do {
            $data = $api->search([
                'fields' => 'id,title,vocab_ids',
                'limit' => 100,
                'page' => $page,
                'query' => ['term' => ['is_artist' => true]],
            ]);

            foreach ($data as $agent) {
                $lccn = $agent['vocab_ids']['lccn'] ?? null;
                if (!empty($lccn)) {
                    $lccnMap[$lccn][] = $agent['id'];
                } elseif (!empty($agent['title'])) {
                    $nameMap[$agent['id']] = $agent['title'];
                }
            }

            $page++;
        } while (!empty($data) && $page <= 10);

        if (!empty($lccnMap)) {
            $orchestrator->syncByLccn($lccnMap);
        }

        if (!empty($nameMap)) {
            $orchestrator->syncByName($nameMap);
        }
    }
}
