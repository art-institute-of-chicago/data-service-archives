<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Archive;
use App\Models\AgentArchiveLink;

class ArchiveApiTest extends TestCase
{
    public function test_can_list_archives(): void
    {
        Archive::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/archives');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'title', 'lccn', 'mms_id',
                    'contentdm_collection', 'contentdm_id', 'contentdm_url',
                    'web_url', 'match_type', 'match_confidence',
                    'metadata', 'agent_citi_ids',
                    'collection_type', 'record_type', 'has_media',
                ],
            ],
        ]);
    }

    public function test_can_show_archive(): void
    {
        $archive = Archive::factory()->create([
            'title' => 'Test Exhibition Catalog',
            'mms_id' => '991003181079703801',
            'match_type' => 'lccn',
            'match_confidence' => 'positive',
        ]);

        AgentArchiveLink::factory()->create([
            'archive_id' => $archive->id,
            'agent_citi_id' => 12345,
        ]);

        $response = $this->getJson('/api/v1/archives/' . $archive->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Test Exhibition Catalog');
        $response->assertJsonPath('data.match_type', 'lccn');
        $response->assertJsonPath('data.agent_citi_ids', [12345]);
    }

    public function test_returns_404_for_missing_archive(): void
    {
        $response = $this->getJson('/api/v1/archives/99999');
        $response->assertStatus(404);
    }

    public function test_archive_model_has_casts(): void
    {
        $archive = Archive::factory()->create([
            'lccn' => ['66015550', '72175538'],
            'metadata' => ['key' => 'value'],
        ]);

        $this->assertIsArray($archive->lccn);
        $this->assertContains('66015550', $archive->lccn);
        $this->assertIsArray($archive->metadata);
    }

    public function test_agent_archive_link_relation(): void
    {
        $archive = Archive::factory()->create();
        $link = AgentArchiveLink::factory()->create([
            'archive_id' => $archive->id,
            'agent_citi_id' => 54321,
            'match_type' => 'name',
            'match_confidence' => 'tentative',
        ]);

        $this->assertEquals(54321, $link->agent_citi_id);
        $this->assertEquals('tentative', $link->match_confidence);
        $this->assertTrue($archive->agentLinks->contains($link));
    }
}
