<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\Archive;
use App\Models\AgentArchiveLink;
use App\Services\AlmaSruClient;
use App\Services\ArchiveOrchestrator;
use App\Services\ContentDmClient;
use App\Services\PrimoClient;
use Mockery;

class ArchiveOrchestratorTest extends TestCase
{
    protected function makeOrchestrator($alma = null, $primo = null, $contentdm = null): ArchiveOrchestrator
    {
        return new ArchiveOrchestrator(
            $alma ?? Mockery::mock(AlmaSruClient::class),
            $primo ?? Mockery::mock(PrimoClient::class),
            $contentdm ?? Mockery::mock(ContentDmClient::class),
        );
    }

    public function test_lccn_positive_match_is_not_downgraded_by_later_name_match(): void
    {
        $alma = Mockery::mock(AlmaSruClient::class);
        $alma->shouldReceive('searchByLccn')->with('n79055511')->andReturn([
            [
                'mms_id' => '99100000000',
                'title' => 'Monet Correspondence',
                'creator' => 'Monet, Claude',
                'lccns' => ['n79055511'],
                'file_urls' => [],
                'collection_type' => 'archives',
                'record_type' => 'correspondence',
                'has_media' => false,
            ],
        ]);

        $primo = Mockery::mock(PrimoClient::class);
        $primo->shouldReceive('searchByName')->with('Monet, Claude')->andReturn([
            [
                'mms_id' => '99100000000',
                'title' => 'Monet Correspondence (Primo copy)',
                'creator' => 'Monet, Claude',
                'lccn' => 'n79055511',
                'files' => [],
            ],
        ]);

        $orchestrator = $this->makeOrchestrator($alma, $primo);

        $orchestrator->syncByLccn(['n79055511' => [111]]);
        $orchestrator->syncByName([222 => 'Monet, Claude']);

        $archive = Archive::where('mms_id', '99100000000')->firstOrFail();

        $this->assertSame('positive', $archive->match_confidence);
        $this->assertSame('lccn', $archive->match_type);
        $this->assertSame('Monet Correspondence', $archive->title);

        // Agent 222's tentative relationship to the same archive is still recorded.
        $this->assertTrue(
            AgentArchiveLink::where('archive_id', $archive->id)->where('agent_citi_id', 222)->exists()
        );

        $stats = $orchestrator->stats();
        $this->assertSame(1, $stats['records_created']);
        $this->assertSame(1, $stats['records_preserved']);
    }

    public function test_name_sync_skips_results_with_empty_mms_id(): void
    {
        $primo = Mockery::mock(PrimoClient::class);
        $primo->shouldReceive('searchByName')->andReturn([
            [
                'mms_id' => '',
                'title' => 'Untitled',
                'creator' => 'Someone, Artist',
                'lccn' => 'n00000000',
                'files' => [],
            ],
        ]);

        $orchestrator = $this->makeOrchestrator(null, $primo);
        $orchestrator->syncByName([333 => 'Someone, Artist']);

        $this->assertSame(0, Archive::count());
        $this->assertSame(0, $orchestrator->stats()['records_created']);
    }

    public function test_name_sync_validates_match_against_creator_not_title(): void
    {
        $primo = Mockery::mock(PrimoClient::class);
        $primo->shouldReceive('searchByName')->andReturn([
            // Title happens to mention the artist, but creator is someone else — must be skipped.
            [
                'mms_id' => '99200000001',
                'title' => 'A Tribute to Ansel Adams',
                'creator' => 'Someone Else',
                'lccn' => 'n11111111',
                'files' => [],
            ],
            // Creator matches even though title doesn't repeat the name — must be stored.
            [
                'mms_id' => '99200000002',
                'title' => 'Untitled Landscape Series',
                'creator' => 'Adams, Ansel',
                'lccn' => 'n22222222',
                'files' => [],
            ],
        ]);

        $orchestrator = $this->makeOrchestrator(null, $primo);
        $orchestrator->syncByName([444 => 'Adams, Ansel']);

        $this->assertSame(1, Archive::count());
        $this->assertFalse(Archive::where('mms_id', '99200000001')->exists());
        $this->assertTrue(Archive::where('mms_id', '99200000002')->exists());
    }

    public function test_syncByLccn_error_is_counted_and_does_not_stop_other_lccns(): void
    {
        $alma = Mockery::mock(AlmaSruClient::class);
        $alma->shouldReceive('searchByLccn')->with('bad')->andThrow(new \Exception('Alma unavailable'));
        $alma->shouldReceive('searchByLccn')->with('good')->andReturn([
            [
                'mms_id' => '99300000000',
                'title' => 'Fine',
                'creator' => 'Someone',
                'lccns' => ['good'],
                'file_urls' => [],
                'collection_type' => 'library',
                'record_type' => 'book',
                'has_media' => false,
            ],
        ]);

        $orchestrator = $this->makeOrchestrator($alma);
        $orchestrator->syncByLccn(['bad' => [1], 'good' => [2]]);

        $stats = $orchestrator->stats();
        $this->assertSame(1, $stats['errors']);
        $this->assertSame(1, $stats['records_created']);
        $this->assertSame(2, $stats['lccn_processed']);
    }
}
