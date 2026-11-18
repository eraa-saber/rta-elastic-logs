<?php
// config/elasticsearch.php

return [
    'hosts' => [
        env('ELASTIC_HOST', 'https://localhost:9200'),
    ],

    'username' => env('ELASTIC_USERNAME'),
    'password' => env('ELASTIC_PASSWORD'),

    // The server ships with a self-signed certificate, so verification is off by
    // default. Point ELASTIC_CA_CERT at the cluster's http_ca.crt to turn it on.
    'ssl_verify' => env('ELASTIC_SSL_VERIFY', false),
    'ca_cert' => env('ELASTIC_CA_CERT'),

    // How each store's indices are split: daily, monthly, yearly or single.
    //   daily -> "{prefix}-2026.11.18", monthly -> "{prefix}-2026.11",
    //   yearly -> "{prefix}-2026",      single -> "{prefix}"
    'rotation' => env('ELASTIC_LOGS_ROTATION', 'monthly'),

    // Optional per-store override, keyed like the prefixes below. Empty = use 'rotation'.
    'rotations' => [
        // 'external_api_logs_index' => env('ELASTIC_EXTERNAL_API_LOGS_ROTATION'),
    ],

    // Index name prefixes, one per log store. The key is what a service returns
    // from indexConfigKey().
    // 'external_api_logs_index' => env('ELASTIC_EXTERNAL_API_LOGS_INDEX', 'external_api_logs'),

    // Log types the console commands accept via --type. "tables" lists the SQL tables
    // elasticsearch:backfill-logs reads from (optional).
    'log_types' => [
        // 'external' => [
        //     'service' => \App\Services\Elasticsearch\ExternalApiLogsSearchService::class,
        //     'tables' => [
        //         'live' => 'external_api_logs',
        //     ],
        // ],
    ],
];
