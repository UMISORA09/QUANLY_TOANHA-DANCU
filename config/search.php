<?php

$driver = env('SEARCH_DRIVER', 'smart');

return [
    /*
    |--------------------------------------------------------------------------
    | Default Search Driver
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "smart", "database", "elasticsearch"
    |
    */
    'default' => $driver === 'algolia' ? 'smart' : $driver,
    'sync_secret' => env('AMENITY_SEARCH_SYNC_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Drivers Configuration
    |--------------------------------------------------------------------------
    */
    'drivers' => [
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
            'surveys_index' => env('ELASTICSEARCH_SURVEYS_INDEX', 'cassavas_surveys_v1'),
            'suggestions_index' => env('ELASTICSEARCH_SUGGESTIONS_INDEX', 'cassavas_suggestions_v1'),
            'announcements_index' => env('ELASTICSEARCH_ANNOUNCEMENTS_INDEX', 'cassavas_announcements_v1'),
            'tickets_index' => env('ELASTICSEARCH_TICKETS_INDEX', 'cassavas_tickets_v1'),
            'booking_codes_index' => env('ELASTICSEARCH_BOOKING_CODES_INDEX', 'cassavas_booking_codes_v1'),
            'amenities_index' => env('ELASTICSEARCH_AMENITIES_INDEX', 'cassavas_amenities_v1'),
            'residents_index' => env('ELASTICSEARCH_RESIDENTS_INDEX', 'cassavas_residents_v1'),
            'temporary_registrations_index' => env('ELASTICSEARCH_TEMPORARY_REGISTRATIONS_INDEX', 'cassavas_temporary_registrations_v1'),
            'vehicles_index' => env('ELASTICSEARCH_VEHICLES_INDEX', 'cassavas_vehicles_v1'),
            'access_cards_index' => env('ELASTICSEARCH_ACCESS_CARDS_INDEX', 'cassavas_access_cards_v1'),
            'visitors_index' => env('ELASTICSEARCH_VISITORS_INDEX', 'cassavas_visitors_v1'),
            'parcels_index' => env('ELASTICSEARCH_PARCELS_INDEX', 'cassavas_parcels_v1'),
            'hosts' => explode(',', env('ELASTICSEARCH_HOSTS', 'http://127.0.0.1:9200')),
            'api_key' => env('ELASTICSEARCH_API_KEY'),
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
