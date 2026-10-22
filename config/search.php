<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Search Driver
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "smart", "database", "meilisearch", "elasticsearch"
    |
    */
    'default' => env('SEARCH_DRIVER', 'smart'),
    'sync_secret' => env('AMENITY_SEARCH_SYNC_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Drivers Configuration
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'algolia' => [
            'app_id' => env('ALGOLIA_APP_ID', ''),
            'key' => env('ALGOLIA_API_KEY', ''),
            'amenities_index' => env('ALGOLIA_AMENITIES_INDEX', ''),
            'sync_enabled' => env('ALGOLIA_SYNC_ENABLED', false),
            'allow_flush' => false,
        ],
        'smart' => [
            'min_token_length' => 2,
            'fuzzy_enabled' => true,
            'max_fuzzy_distance' => 2,
            'cache_ttl_seconds' => 300,
        ],

        'database' => [
            'min_token_length' => 2,
            'fuzzy_enabled' => true,
            'max_fuzzy_distance' => 2,
            'cache_ttl_seconds' => 300,
        ],

        'meilisearch' => [
            'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
            'key' => env('MEILISEARCH_KEY', null),
        ],

        'elasticsearch' => [
            'hosts' => explode(',', env('ELASTICSEARCH_HOSTS', 'http://127.0.0.1:9200')),
            'username' => env('ELASTICSEARCH_USER', null),
            'password' => env('ELASTICSEARCH_PASS', null),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Knowledge / Vector Hybrid Search
    |--------------------------------------------------------------------------
    */
    'ai_knowledge' => [
        'hybrid_weight_keyword' => 0.4,
        'hybrid_weight_vector' => 0.6,
        'min_similarity_threshold' => 0.65,
        'top_k' => 5,
    ],
];
