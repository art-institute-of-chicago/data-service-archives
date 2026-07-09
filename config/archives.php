<?php

return [
    'aggregator_url' => env('DATA_AGGREGATOR_URL', 'http://nginx/api/v1'),

    'alma_sru_base' => 'https://na01.alma.exlibrisgroup.com/view/sru/01ARTIC_INST',

    'primo_base' => 'https://api-na.hosted.exlibrisgroup.com/primo/v1/search',
    'primo_vid' => '01ARTIC_INST:01ARTIC',
    'primo_key' => env('PRIMO_API_KEY'),
    'alma_key' => env('ALMA_API_KEY'),

    'contentdm_base' => 'https://cdm16735.contentdm.oclc.org',

    'batch_size' => 50,
];
