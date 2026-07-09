<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Api\Agent as AgentApi;
use App\Services\ArchiveOrchestrator;

class SyncNamesBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ArchiveOrchestrator $orchestrator): void
    {
        $api = new AgentApi();
        $nameMap = []; // agent_id => title
        $page = 1;

        do {
            $data = $api->search([
                'fields' => 'id,title,lccn',
                'limit' => 100,
                'page' => $page,
                'query' => [
                    'bool' => [
                        'must_not' => [['exists' => ['field' => 'lccn']]],
                        'must' => [['term' => ['is_artist' => true]]],
                    ],
                ],
            ]);

            if (empty($data)) {
                break;
            }

            foreach ($data as $agent) {
                if (!empty($agent['title'])) {
                    $nameMap[$agent['id']] = $agent['title'];
                }
            }

            $page++;
        } while ($page <= 10);

        if (!empty($nameMap)) {
            $orchestrator->syncByName($nameMap);
        }
    }
}
