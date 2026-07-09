<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Api\Agent as AgentApi;

class SyncArtistsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $api = new AgentApi();
        $agentMap = []; // lccn => [agent_ids]
        $page = 1;

        do {
            $data = $api->search([
                'fields' => 'id,title,lccn',
                'limit' => 100,
                'page' => $page,
                'query' => ['exists' => ['field' => 'lccn']],
            ]);

            if (empty($data)) {
                break;
            }

            foreach ($data as $agent) {
                $lccnList = $agent['lccn'] ?? [];
                if (empty($lccnList)) {
                    continue;
                }
                foreach ((array) $lccnList as $lccn) {
                    $agentMap[$lccn][] = $agent['id'];
                }
            }

            $page++;
        } while ($page <= 10);

        $lccns = array_keys($agentMap);
        $batches = array_chunk($lccns, config('archives.batch_size', 50));

        foreach ($batches as $batch) {
            $batchMap = array_intersect_key($agentMap, array_flip($batch));
            SyncLccnBatchJob::dispatch($batchMap);
        }

        SyncNamesBatchJob::dispatch();
    }
}
