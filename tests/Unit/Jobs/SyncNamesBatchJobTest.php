<?php

namespace Tests\Unit\Jobs;

use Tests\TestCase;
use App\Jobs\SyncNamesBatchJob;
use App\Services\ArchiveOrchestrator;
use Illuminate\Support\Facades\Http;
use Mockery;

class SyncNamesBatchJobTest extends TestCase
{
    public function test_queries_artists_without_vocab_ids_lccn(): void
    {
        Http::fake([
            '*/agents/search' => Http::response([
                'data' => [
                    [
                        'id' => 10,
                        'title' => 'Unknown Artist',
                        'vocab_ids' => ['ulan' => '500000010'],
                    ],
                ],
            ]),
        ]);

        $orchestrator = Mockery::mock(ArchiveOrchestrator::class);
        $orchestrator->shouldReceive('syncByName')
            ->once()
            ->with([10 => 'Unknown Artist']);

        (new SyncNamesBatchJob())->handle($orchestrator);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $bool = $body['query']['bool'] ?? [];
            $mustNot = $bool['must_not'] ?? [];
            return ($mustNot[0]['exists']['field'] ?? null) === 'vocab_ids.lccn';
        });
    }

    public function test_skips_agents_with_empty_title(): void
    {
        Http::fake([
            '*/agents/search' => Http::response([
                'data' => [
                    ['id' => 11, 'title' => '', 'vocab_ids' => []],
                    ['id' => 12, 'title' => 'Valid Name', 'vocab_ids' => ['viaf' => '123']],
                ],
            ]),
        ]);

        $orchestrator = Mockery::mock(ArchiveOrchestrator::class);
        $orchestrator->shouldReceive('syncByName')
            ->once()
            ->with([12 => 'Valid Name']);

        (new SyncNamesBatchJob())->handle($orchestrator);
    }
}
