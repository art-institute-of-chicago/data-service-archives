<?php

namespace Tests\Unit\Api;

use Tests\TestCase;
use App\Api\Agent;
use Illuminate\Support\Facades\Http;

class AgentTest extends TestCase
{
    public function test_sends_bearer_token_when_configured(): void
    {
        config(['api.token' => 'secret-token']);
        Http::fake(['*/agents/search' => Http::response(['data' => []])]);

        (new Agent())->search(['query' => ['term' => ['is_artist' => true]]]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer secret-token');
        });
    }

    public function test_omits_authorization_header_when_no_token_configured(): void
    {
        config(['api.token' => null]);
        Http::fake(['*/agents/search' => Http::response(['data' => []])]);

        (new Agent())->search(['query' => ['term' => ['is_artist' => true]]]);

        Http::assertSent(function ($request) {
            return !$request->hasHeader('Authorization');
        });
    }
}
