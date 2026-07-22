<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\AlmaSruClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Mockery;

class AlmaSruClientTest extends TestCase
{
    protected function sruResponse(int $numberOfRecords, array $mmsIds): string
    {
        $records = '';
        foreach ($mmsIds as $mmsId) {
            $records .= '<record><recordSchema>marcxml</recordSchema><recordData>'
                . '<record xmlns="http://www.loc.gov/MARC21/slim">'
                . '<leader>01946cam a2200421 a 4500</leader>'
                . '<controlfield tag="001">' . $mmsId . '</controlfield>'
                . '</record></recordData></record>';
        }

        return '<?xml version="1.0"?><searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/">'
            . '<version>1.2</version>'
            . '<numberOfRecords>' . $numberOfRecords . '</numberOfRecords>'
            . '<records>' . $records . '</records>'
            . '</searchRetrieveResponse>';
    }

    protected function clientWithHistory(array $responses, array &$history): Client
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    public function test_sanitizes_lccn_before_building_query_and_logs_warning(): void
    {
        Log::shouldReceive('warning')->once()->with(
            'AlmaSruClient: LCCN contained unexpected characters and was sanitized',
            Mockery::any()
        );

        $history = [];
        $http = $this->clientWithHistory([new Response(200, [], $this->sruResponse(0, []))], $history);

        (new AlmaSruClient($http))->searchByLccn("n79055511' OR 1=1");

        $sentQuery = [];
        parse_str(parse_url((string) $history[0]['request']->getUri(), PHP_URL_QUERY) ?? '', $sentQuery);
        $this->assertSame('alma.authority_id=n79055511OR11', $sentQuery['query']);
    }

    public function test_no_warning_when_lccn_is_clean(): void
    {
        Log::shouldReceive('warning')->never();

        $history = [];
        $http = $this->clientWithHistory([new Response(200, [], $this->sruResponse(0, []))], $history);

        (new AlmaSruClient($http))->searchByLccn('n79055511');
    }

    public function test_logs_warning_when_more_records_matched_than_fetched(): void
    {
        config(['archives.batch_size' => 1]);

        Log::shouldReceive('warning')->once()->with(
            'AlmaSruClient: result truncated — more records matched than were fetched',
            Mockery::subset(['total_matched' => 3, 'fetched' => 1])
        );

        $history = [];
        $http = $this->clientWithHistory(
            [new Response(200, [], $this->sruResponse(3, ['991000000000001']))],
            $history
        );

        $results = (new AlmaSruClient($http))->searchByLccn('n79055511');

        $this->assertArrayHasKey('991000000000001', $results);
    }

    public function test_no_truncation_warning_when_all_records_fetched(): void
    {
        config(['archives.batch_size' => 50]);

        Log::shouldReceive('warning')->never();

        $history = [];
        $http = $this->clientWithHistory(
            [new Response(200, [], $this->sruResponse(1, ['991000000000002']))],
            $history
        );

        (new AlmaSruClient($http))->searchByLccn('n79055511');
    }

    /**
     * End-to-end through the real HTTP + parsing pipeline (not a hand-rolled
     * reimplementation): title/creator cleanup, collection_type, record_type,
     * and has_media all have to agree with each other on one record.
     */
    public function test_search_by_lccn_produces_fully_wired_record(): void
    {
        $recordXml = '<record xmlns="http://www.loc.gov/MARC21/slim">'
            . '<leader>02002nam a2200409 i 4500</leader>'
            . '<controlfield tag="001">991000000000009</controlfield>'
            . '<datafield tag="010"><subfield code="a">n79055511</subfield></datafield>'
            . '<datafield tag="100"><subfield code="a">Doe, Jane,</subfield></datafield>'
            . '<datafield tag="245"><subfield code="a">Sketchbook of studies /</subfield></datafield>'
            . '<datafield tag="655"><subfield code="a">Sketchbooks</subfield></datafield>'
            . '<datafield tag="852"><subfield code="b">o</subfield></datafield>'
            . '<datafield tag="856"><subfield code="u">https://cdm16735.contentdm.oclc.org/digital/collection/x/id/1</subfield></datafield>'
            . '</record>';

        $sru = '<?xml version="1.0"?><searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/">'
            . '<version>1.2</version><numberOfRecords>1</numberOfRecords>'
            . '<records><record><recordSchema>marcxml</recordSchema><recordData>'
            . $recordXml
            . '</recordData></record></records></searchRetrieveResponse>';

        $history = [];
        $http = $this->clientWithHistory([new Response(200, [], $sru)], $history);

        $results = (new AlmaSruClient($http))->searchByLccn('n79055511');
        $record = $results['991000000000009'];

        // Title's trailing ISBD "/" separator stripped.
        $this->assertSame('Sketchbook of studies', $record['title']);
        // Creator's trailing MARC comma (no $d subfield follows) stripped.
        $this->assertSame('Doe, Jane', $record['creator']);
        $this->assertSame('archives', $record['collection_type']);
        $this->assertSame('sketchbook', $record['record_type']);
        $this->assertSame('a', $record['leader']['type_of_record']);
        $this->assertTrue($record['has_media']);
        $this->assertNotEmpty($record['normalized']);
        $this->assertSame('archives', $record['normalized']['collection_type']);
    }
}
