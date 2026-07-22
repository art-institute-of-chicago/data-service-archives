<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Api\Agent as AgentApi;
use App\Services\ArchiveOrchestrator;

class ArchiveSyncAll extends Command
{
    protected $signature = 'archives:sync-all';
    protected $description = 'Sync all archive records: query aggregator for artists, search Alma/Primo, store results';

    /** Safety cap on aggregator pagination — 100/page × 10 pages = 1,000 artists per run. */
    protected const MAX_PAGES = 10;

    public function handle(ArchiveOrchestrator $orchestrator): void
    {
        $runId = (string) Str::uuid();
        $startedAt = now();

        $api = new AgentApi();
        $lccnMap = [];
        $nameMap = [];
        $page = 1;

        $this->info('Fetching artists from aggregator...');

        do {
            $data = $api->search([
                'fields' => 'id,title,vocab_ids',
                'limit' => 100,
                'page' => $page,
                'query' => ['term' => ['is_artist' => true]],
            ]);

            $this->line("Page {$page}: " . count($data) . ' agents');

            foreach ($data as $agent) {
                $lccn = $agent['vocab_ids']['lccn'] ?? null;
                if (!empty($lccn)) {
                    $lccnMap[$lccn][] = $agent['id'];
                } elseif (!empty($agent['title'])) {
                    $nameMap[$agent['id']] = $agent['title'];
                }
            }

            $page++;
        } while (!empty($data) && $page <= self::MAX_PAGES);

        $this->info('Found ' . count($lccnMap) . ' LCCN matches, ' . count($nameMap) . ' name matches');

        if (!empty($lccnMap)) {
            $this->info('Syncing by LCCN...');
            $orchestrator->syncByLccn($lccnMap);
        }

        if (!empty($nameMap)) {
            $this->info('Syncing by name...');
            $orchestrator->syncByName($nameMap);
        }

        $summary = $orchestrator->stats() + [
            'run_id' => $runId,
            'duration_seconds' => now()->diffInSeconds($startedAt),
        ];

        Log::info('archives:sync-all completed', $summary);
        $this->info('Done. ' . json_encode($summary));
    }
}
