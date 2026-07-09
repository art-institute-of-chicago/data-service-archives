<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\ArchiveOrchestrator;

class SyncLccnBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array $agentMap Associative array of lccn => [agent_citi_ids]
     */
    public function __construct(protected array $agentMap) {}

    public function handle(ArchiveOrchestrator $orchestrator): void
    {
        $orchestrator->syncByLccn($this->agentMap);
    }
}
