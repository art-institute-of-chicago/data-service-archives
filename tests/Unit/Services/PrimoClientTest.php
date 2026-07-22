<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\PrimoClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Mockery;

class PrimoClientTest extends TestCase
{
    protected function clientReturning(int $total): Client
    {
        $body = json_encode(['info' => ['total' => $total], 'docs' => []]);
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], $body)]));

        return new Client(['handler' => $stack]);
    }

    public function test_logs_warning_when_more_results_matched_than_the_offset_cap_allows(): void
    {
        Log::shouldReceive('warning')->once()->with(
            'PrimoClient: result truncated — more records matched than were fetched',
            Mockery::subset(['creator' => 'Monet, Claude', 'total_matched' => 5000, 'fetched' => 2000])
        );

        $client = new PrimoClient($this->clientReturning(5000));
        $client->searchByName('Monet, Claude', 2000);
    }

    public function test_no_warning_when_results_are_within_the_offset_cap(): void
    {
        Log::shouldReceive('warning')->never();

        $client = new PrimoClient($this->clientReturning(10));
        $client->searchByName('Monet, Claude', 2000);
    }
}
