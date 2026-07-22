<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class PrimoClient
{
    protected Client $http;

    protected const MAX_OFFSET = 2000;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
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
                    'q' => 'creator,contains,' . $name,
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
                    $creator = $doc->pnx->display->creator[0] ?? null;
                    $dateDisplay = $doc->pnx->display->creationdate[0] ?? null;
                    $format = $doc->pnx->display->format[0] ?? null;
                    $subjects = $doc->pnx->display->subject ?? [];
                    $language = $doc->pnx->display->language[0] ?? null;
                    $description = $doc->pnx->display->description[0] ?? null;

                    $results[] = [
                        'mms_id' => $doc->pnx->display->mms[0] ?? '',
                        'title' => $doc->pnx->display->title[0] ?? '',
                        'creator' => $creator,
                        'date_display' => $dateDisplay,
                        'date_start' => $this->parseDateStart($dateDisplay),
                        'date_end' => $this->parseDateEnd($dateDisplay),
                        'format' => $format,
                        'subjects' => is_array($subjects) ? $subjects : [],
                        'language' => $language,
                        'description' => $description,
                        'lccn' => $lccn[0],
                        'files' => $files,
                        'has_media' => true,  // Primo only returns records with files
                    ];
                }
            }

            $offset += $limit;
        } while ($offset < $total && $offset < self::MAX_OFFSET);

        if ($total > self::MAX_OFFSET) {
            Log::warning('PrimoClient: result truncated — more records matched than were fetched', [
                'creator' => $name,
                'total_matched' => $total,
                'fetched' => min($offset, self::MAX_OFFSET),
            ]);
        }

        return $results;
    }

    protected function parseDateStart(?string $dateDisplay): ?int
    {
        if (!$dateDisplay) {
            return null;
        }
        if (preg_match('/^(\d{4})/', trim($dateDisplay), $m)) {
            return (int) $m[1];
        }
        return null;
    }

    protected function parseDateEnd(?string $dateDisplay): ?int
    {
        if (!$dateDisplay) {
            return null;
        }
        if (preg_match('/[-\/](\d{4})/', trim($dateDisplay), $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^(\d{4})$/', trim($dateDisplay), $m)) {
            return (int) $m[1];
        }
        return null;
    }
}
