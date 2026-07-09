<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Consumer Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the GuzzleApiConsumer used to query remote data
    | services (e.g., data-aggregator).
    |
    */

    'url' => env('DATA_AGGREGATOR_URL', 'http://nginx/api/v1'),

    'token' => env('API_TOKEN'),

];
