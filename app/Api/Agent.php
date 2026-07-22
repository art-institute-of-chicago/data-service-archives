<?php

namespace App\Api;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * API consumer for querying agents (artists) from data-aggregator.
 */
class Agent
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('api.url', 'http://nginx/api/v1');
    }

    public function search(array $params = []): array
    {
        $query = $params['query'] ?? [];
        unset($params['query']);

        $response = $this->client()->asJson()->post($this->baseUrl . '/agents/search', [
            'query' => $query,
            'fields' => $params['fields'] ?? '',
            'limit' => $params['limit'] ?? 12,
            'page' => $params['page'] ?? 1,
        ]);

        return $response->json('data', []);
    }

    public function find(int|string $id): ?array
    {
        $response = $this->client()->get($this->baseUrl . '/agents/' . $id);
        return $response->json('data');
    }

    protected function client(): PendingRequest
    {
        $token = config('api.token');

        return $token ? Http::withToken($token) : Http::withHeaders([]);
    }
}
