<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Freshness Monitoring Configuration
    |--------------------------------------------------------------------------
    | Defines warning and critical thresholds (in seconds) for business data
    | tables and monitoring signal observations.
    |
    | Formula: freshness_age_seconds = current_time - source_timestamp
    |
    | States:
    | - FRESH:       age < warning_threshold
    | - STALE:       warning_threshold <= age < critical_threshold
    | - CRITICAL:    age >= critical_threshold
    | - UNKNOWN:     source has no timestamps / records
    | - UNAVAILABLE: source is unreachable / timeout / error
    |--------------------------------------------------------------------------
    */

    'cache' => [
        // Cache duration for business data max timestamps to avoid heavy DB queries (in seconds)
        'data_cache_seconds' => (int) env('FRESHNESS_DATA_CACHE_SECONDS', 30),
        // Cache duration for monitoring observation states (in seconds)
        'monitoring_cache_seconds' => (int) env('FRESHNESS_MONITORING_CACHE_SECONDS', 5),
    ],

    // Collector Health Thresholds (Monitoring of Monitoring)
    'collector' => [
        'warning_seconds' => (int) env('FRESHNESS_COLLECTOR_WARNING_SECONDS', 60),
        'critical_seconds' => (int) env('FRESHNESS_COLLECTOR_CRITICAL_SECONDS', 300),
    ],

    // Monitoring Signal Sources
    'monitoring_sources' => [
        'github_actions' => [
            'name' => 'GitHub Actions CI/CD Runs',
            'type' => 'monitoring',
            'warning_seconds' => (int) env('FRESHNESS_GITHUB_WARNING_SECONDS', 3600), // 1 hour
            'critical_seconds' => (int) env('FRESHNESS_GITHUB_CRITICAL_SECONDS', 86400), // 24 hours
        ],
        'deployment' => [
            'name' => 'Deployment & Releases',
            'type' => 'monitoring',
            'warning_seconds' => (int) env('FRESHNESS_DEPLOYMENT_WARNING_SECONDS', 604800), // 7 days
            'critical_seconds' => (int) env('FRESHNESS_DEPLOYMENT_CRITICAL_SECONDS', 2592000), // 30 days
        ],
        'application_health' => [
            'name' => 'Application Health Probes',
            'type' => 'monitoring',
            'warning_seconds' => (int) env('FRESHNESS_HEALTH_WARNING_SECONDS', 60), // 1 minute
            'critical_seconds' => (int) env('FRESHNESS_HEALTH_CRITICAL_SECONDS', 180), // 3 minutes
        ],
    ],

    // Business Data Sources (Real tables in database schema)
    'data_sources' => [
        'audit_logs' => [
            'table' => 'audit_logs',
            'timestamp_field' => 'created_at',
            'name' => 'Audit Trail Logs',
            'type' => 'data',
            'warning_seconds' => (int) env('FRESHNESS_AUDIT_WARNING_SECONDS', 7200), // 2 hours
            'critical_seconds' => (int) env('FRESHNESS_AUDIT_CRITICAL_SECONDS', 86400), // 24 hours
        ],
        'tickets' => [
            'table' => 'tickets',
            'timestamp_field' => 'updated_at',
            'fallback_field' => 'created_at',
            'name' => 'Resident Service Tickets',
            'type' => 'data',
            'warning_seconds' => (int) env('FRESHNESS_TICKETS_WARNING_SECONDS', 86400), // 24 hours
            'critical_seconds' => (int) env('FRESHNESS_TICKETS_CRITICAL_SECONDS', 259200), // 3 days
        ],
        'residents' => [
            'table' => 'residents',
            'timestamp_field' => 'updated_at',
            'fallback_field' => 'created_at',
            'name' => 'Residents & Occupants',
            'type' => 'data',
            'warning_seconds' => (int) env('FRESHNESS_RESIDENTS_WARNING_SECONDS', 604800), // 7 days
            'critical_seconds' => (int) env('FRESHNESS_RESIDENTS_CRITICAL_SECONDS', 2592000), // 30 days
        ],
        'apartments' => [
            'table' => 'apartments',
            'timestamp_field' => 'updated_at',
            'fallback_field' => 'created_at',
            'name' => 'Apartment Units & Floors',
            'type' => 'data',
            'warning_seconds' => (int) env('FRESHNESS_APARTMENTS_WARNING_SECONDS', 2592000), // 30 days
            'critical_seconds' => (int) env('FRESHNESS_APARTMENTS_CRITICAL_SECONDS', 7776000), // 90 days
        ],
        'amenities' => [
            'table' => 'amenities',
            'timestamp_field' => 'updated_at',
            'fallback_field' => 'created_at',
            'name' => 'Amenities & Bookings',
            'type' => 'data',
            'warning_seconds' => (int) env('FRESHNESS_AMENITIES_WARNING_SECONDS', 2592000), // 30 days
            'critical_seconds' => (int) env('FRESHNESS_AMENITIES_CRITICAL_SECONDS', 7776000), // 90 days
        ],
        'contracts' => [
            'table' => 'contracts',
            'timestamp_field' => 'updated_at',
            'fallback_field' => 'created_at',
            'name' => 'Lease & Service Contracts',
            'type' => 'data',
            'warning_seconds' => (int) env('FRESHNESS_CONTRACTS_WARNING_SECONDS', 604800), // 7 days
            'critical_seconds' => (int) env('FRESHNESS_CONTRACTS_CRITICAL_SECONDS', 2592000), // 30 days
        ],
        'invoices' => [
            'table' => 'invoices',
            'timestamp_field' => 'updated_at',
            'fallback_field' => 'created_at',
            'name' => 'Utility & Management Invoices',
            'type' => 'data',
            'warning_seconds' => (int) env('FRESHNESS_INVOICES_WARNING_SECONDS', 2592000), // 30 days
            'critical_seconds' => (int) env('FRESHNESS_INVOICES_CRITICAL_SECONDS', 7776000), // 90 days
        ],
        'users' => [
            'table' => 'users',
            'timestamp_field' => 'updated_at',
            'fallback_field' => 'created_at',
            'name' => 'User Accounts & Roles',
            'type' => 'data',
            'warning_seconds' => (int) env('FRESHNESS_USERS_WARNING_SECONDS', 604800), // 7 days
            'critical_seconds' => (int) env('FRESHNESS_USERS_CRITICAL_SECONDS', 2592000), // 30 days
        ],
    ],
];
