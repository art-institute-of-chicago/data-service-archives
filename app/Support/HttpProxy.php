<?php

namespace App\Support;

final class HttpProxy
{
    public static function options(): array
    {
        $proxy = config('archives.curl_proxy');

        return $proxy ? ['proxy' => $proxy] : [];
    }
}
