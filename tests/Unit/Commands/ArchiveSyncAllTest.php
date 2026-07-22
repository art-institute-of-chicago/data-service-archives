<?php

namespace Tests\Unit\Commands;

use Tests\TestCase;
use App\Services\ArchiveOrchestrator;
use Illuminate\Support\Facades\Http;
use Mockery;

class ArchiveSyncAllTest extends TestCase
{
    protected function fakeOrchestrator(): Mockery\MockInterface
    {
        $orchestrator = Mockery::mock(ArchiveOrchestrator::class);
        $orchestrator->shouldReceive('stats')->andReturn([
            'lccn_processed' => 0,
            'name_processed' => 0,
            'records_created' => 0,
            'records_updated' => 0,
            'records_preserved' => 0,
            'errors' => 0,
        ]);

        $this->app->instance(ArchiveOrchestrator::class, $orchestrator);

        return $orchestrator;
    }

    public function test_splits_artists_into_lccn_and_name_maps(): void
    {
        Http::fake([
            '*/agents/search' => Http::sequence()
                ->push([
                    'data' => [
                        ['id' => 1, 'title' => 'Monet, Claude', 'vocab_ids' => ['lccn' => 'n79055511']],
                        ['id' => 2, 'title' => 'Unknown Artist', 'vocab_ids' => []],
                        ['id' => 3, 'title' => 'No LCCN', 'vocab_ids' => null],
                    ],
                ])
                ->push(['data' => []]),
        ]);

        $orchestrator = $this->fakeOrchestrator();
        $orchestrator->shouldReceive('syncByLccn')->once()->with(['n79055511' => [1]]);
        $orchestrator->shouldReceive('syncByName')->once()->with([2 => 'Unknown Artist', 3 => 'No LCCN']);

        $this->artisan('archives:sync-all')->assertExitCode(0);
    }

    public function test_skips_empty_titles_in_name_map(): void
    {
        Http::fake([
            '*/agents/search' => Http::sequence()
                ->push([
                    'data' => [
                        ['id' => 4, 'title' => '', 'vocab_ids' => []],
                        ['id' => 5, 'title' => 'Valid', 'vocab_ids' => null],
                    ],
                ])
                ->push(['data' => []]),
        ]);

        $orchestrator = $this->fakeOrchestrator();
        $orchestrator->shouldReceive('syncByLccn')->never();
        $orchestrator->shouldReceive('syncByName')->once()->with([5 => 'Valid']);

        $this->artisan('archives:sync-all')->assertExitCode(0);
    }

    public function test_queries_is_artist_true(): void
    {
        Http::fake(['*/agents/search' => Http::response(['data' => []])]);

        $orchestrator = $this->fakeOrchestrator();
        $orchestrator->shouldReceive('syncByLccn')->never();
        $orchestrator->shouldReceive('syncByName')->never();

        $this->artisan('archives:sync-all')->assertExitCode(0);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            return ($body['query']['term']['is_artist'] ?? null) === true;
        });
    }

    public function test_pagination_is_capped_so_a_runaway_aggregator_cannot_loop_forever(): void
    {
        // Every page returns a non-empty result — without a cap this would loop forever.
        Http::fake([
            '*/agents/search' => Http::response([
                'data' => [
                    ['id' => 1, 'title' => 'Someone', 'vocab_ids' => []],
                ],
            ]),
        ]);

        $orchestrator = $this->fakeOrchestrator();
        $orchestrator->shouldReceive('syncByLccn')->never();
        $orchestrator->shouldReceive('syncByName')->once();

        $this->artisan('archives:sync-all')->assertExitCode(0);

        Http::assertSentCount(10);
    }
}
