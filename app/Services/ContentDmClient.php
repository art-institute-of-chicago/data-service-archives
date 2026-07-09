<?php

namespace App\Services;

use GuzzleHttp\Client;

class ContentDmClient
{
    protected Client $http;
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('archives.contentdm_base');
        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
        ]);
    }

    /**
     * Parse a ContentDM download URL into collection alias and item ID.
     * URL pattern: /digital/api/collection/{coll}/id/{id}/download
     */
    public function parseUrl(string $url): ?array
    {
        if (preg_match('#/collection/([^/]+)/id/(\d+)#', $url, $m)) {
            return ['collection' => $m[1], 'id' => $m[2]];
        }
        return null;
    }

    /**
     * Get item metadata from the ContentDM internal API.
     * Returns null if the item doesn't exist.
     */
    public function getItem(string $collection, string $id): ?array
    {
        $response = $this->http->get("/digital/api/singleitem/collection/{$collection}/id/{$id}");

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if (empty($body)) {
            return null;
        }

        $item = [
            'contentdm_collection' => $collection,
            'contentdm_id' => $id,
            'contentdm_url' => $body['downloadUri'] ?? null,
            'content_type' => $body['contentType'] ?? null,
            'title' => $body['fields'][0]['value'] ?? null,
            'parent_id' => $body['parentId'] ?? null,
            'is_compound' => ($body['objectInfo']['code'] ?? '-2') !== '-2',
            'metadata' => $body,
        ];

        // Make download URI absolute
        if ($item['contentdm_url'] && !str_starts_with($item['contentdm_url'], 'http')) {
            $item['contentdm_url'] = $this->baseUrl . $item['contentdm_url'];
        }

        return $item;
    }

    /**
     * If the item is a compound object parent, resolve all child items.
     */
    public function resolveCompound(string $collection, string $parentId): array
    {
        $children = [];
        // Search for all items with this parentId in the same collection
        $response = $this->http->get('/digital/api/search', [
            'query' => [
                'collection' => $collection,
                'format' => 'json',
                'q' => '',
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        $items = $body['items'] ?? [];

        foreach ($items as $item) {
            if (($item['parentId'] ?? null) == $parentId) {
                $children[] = [
                    'contentdm_id' => $item['itemId'],
                    'title' => $item['title'] ?? '',
                    'download_uri' => $item['downloadUri'] ?? null,
                ];
            }
        }

        return $children;
    }
}
