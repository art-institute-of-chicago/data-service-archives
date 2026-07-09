<?php

namespace App\Services;

use GuzzleHttp\Client;

class PrimoClient
{
    protected Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => config('archives.primo_base'),
            'timeout' => 30,
        ]);
    }

    /**
     * Search Primo by artist name. Returns records that have both LCCN and files.
     * Paginates through all results.
     */
    public function searchByName(string $name, int $limit = 50): array
    {
        $results = [];
        $offset = 0;

        do {
            $response = $this->http->get('', [
                'query' => [
                    'vid' => config('archives.primo_vid'),
                    'tab' => 'ARCHIVES',
                    'scope' => 'ARCHIVES',
                    'q' => 'any,contains,' . $name,
                    'skipDelivery' => 'false',
                    'limit' => $limit,
                    'offset' => $offset,
                    'apikey' => config('archives.primo_key'),
                ],
            ]);

            $body = json_decode($response->getBody()->getContents());
            $docs = $body->docs ?? [];
            $total = $body->info->total ?? 0;

            foreach ($docs as $doc) {
                $lccn = $doc->pnx->addata->lccn ?? [];
                $links = $doc->delivery->link ?? [];
                $files = [];
                foreach ($links as $link) {
                    if (($link->linkType ?? '') === 'linktorsrc') {
                        $files[] = $link->linkURL;
                    }
                }

                if (!empty($lccn) && !empty($files)) {
                    $results[] = [
                        'mms_id' => $doc->pnx->display->mms[0] ?? '',
                        'title' => $doc->pnx->display->title[0] ?? '',
                        'lccn' => $lccn[0],
                        'files' => $files,
                    ];
                }
            }

            $offset += $limit;
        } while ($offset < $total && $offset < 2000);

        return $results;
    }
}
