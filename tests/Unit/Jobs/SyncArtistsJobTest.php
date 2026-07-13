<?php

namespace Tests\Unit\Jobs;

use Tests\TestCase;
use App\Jobs\SyncArtistsJob;
use App\Services\ArchiveOrchestrator;
use Illuminate\Support\Facades\Http;
use Mockery;

class SyncArtistsJobTest extends TestCase
{
    public function test_splits_artists_into_lccn_and_name_maps(): void
    {
        Http::fake([
            '*/agents/search' => Http::response([
                'data' => [
                    ['id' => 1, 'title' => 'Monet, Claude', 'vocab_ids' => ['lccn' => 'n79055511']],
                    ['id' => 2, 'title' => 'Unknown Artist', 'vocab_ids' => []],
                    ['id' => 3, 'title' => 'No LCCN', 'vocab_ids' => null],
                ],
            ]),
        ]);

        $orchestrator = Mockery::mock(ArchiveOrchestrator::class);
        $orchestrator->shouldReceive('syncByLccn')->once();
        $orchestrator->shouldReceive('syncByName')->once();

        (new SyncArtistsJob())->handle($orchestrator);
    }

    public function test_skips_empty_titles_in_name_map(): void
    {
        Http::fake([
            '*/agents/search' => Http::response([
                'data' => [
                    ['id' => 4, 'title' => '', 'vocab_ids' => []],
                    ['id' => 5, 'title' => 'Valid', 'vocab_ids' => null],
                ],
            ]),
        ]);

        $orchestrator = Mockery::mock(ArchiveOrchestrator::class);
        $orchestrator->shouldReceive('syncByLccn')->never();
        $orchestrator->shouldReceive('syncByName')->once()->with([5 => 'Valid']);

        (new SyncArtistsJob())->handle($orchestrator);
    }

    public function test_queries_is_artist_true(): void
    {
        Http::fake([
            '*/agents/search' => Http::response(['data' => []]),
        ]);

        $orchestrator = Mockery::mock(ArchiveOrchestrator::class);
        $orchestrator->shouldReceive('syncByLccn')->never();
        $orchestrator->shouldReceive('syncByName')->never();

        (new SyncArtistsJob())->handle($orchestrator);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            return ($body['query']['term']['is_artist'] ?? null) === true;
        });
    }
}
