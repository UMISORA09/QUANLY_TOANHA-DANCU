<?php

use App\Models\AccessCard;
use App\Models\Parcel;
use App\Models\Resident;
use App\Models\TemporaryRegistration;
use App\Models\Vehicle;
use App\Models\VisitorRegistration;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    |
    | Supported: "algolia", "meilisearch", "typesense", "database", "collection", "null"
    |
    */

    'driver' => env('SCOUT_DRIVER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Data Syncing
    |--------------------------------------------------------------------------
    */

    'queue' => env('SCOUT_QUEUE', false),

    /*
    |--------------------------------------------------------------------------
    | Database Transactions
    |--------------------------------------------------------------------------
    */

    'after_commit' => false,

    /*
    |--------------------------------------------------------------------------
    | Chunk Sizes
    |--------------------------------------------------------------------------
    */

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    */

    'soft_delete' => true,

    /*
    |--------------------------------------------------------------------------
    | Identify User
    |--------------------------------------------------------------------------
    */

    'identify' => false,

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration & Index Settings
    |--------------------------------------------------------------------------
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            Resident::class => [
                'filterableAttributes' => [
                    'id',
                    'user_id',
                    'apartment_id',
                    'resident_type',
                    'is_head_of_household',
                    'is_active',
                    'gender',
                ],
                'sortableAttributes' => [
                    'created_at',
                    'stay_start_date',
                    'full_name',
                ],
                'searchableAttributes' => [
                    'full_name',
                    'phone_number',
                    'national_id_number',
                    'apartment_number',
                    'block_name',
                    'block_code',
                    'occupation',
                ],
            ],
            TemporaryRegistration::class => [
                'filterableAttributes' => [
                    'id',
                    'resident_id',
                    'apartment_id',
                    'registration_type',
                    'police_status',
                ],
                'sortableAttributes' => [
                    'created_at',
                    'start_date',
                    'end_date',
                ],
                'searchableAttributes' => [
                    'resident_name',
                    'resident_phone',
                    'apartment_number',
                    'block_name',
                    'police_reference_code',
                    'reason',
                ],
                'nonSeparatorTokens' => ['-', '_', '.'],
            ],
            Vehicle::class => [
                'filterableAttributes' => [
                    'id',
                    'apartment_id',
                    'owner_user_id',
                    'vehicle_category',
                    'is_active',
                ],
                'sortableAttributes' => [
                    'created_at',
                    'license_plate',
                ],
                'searchableAttributes' => [
                    'license_plate',
                    'brand',
                    'model',
                    'color',
                    'owner_name',
                    'apartment_number',
                    'registration_certificate_number',
                ],
                'typoTolerance' => [
                    'enabled' => true,
                    'disableOnAttributes' => [
                        'license_plate',
                        'registration_certificate_number',
                    ],
                ],
                'nonSeparatorTokens' => ['-', '_', '.'],
            ],
            AccessCard::class => [
                'filterableAttributes' => [
                    'id',
                    'status',
                    'card_type',
                    'assigned_user_id',
                    'assigned_apartment_id',
                    'assigned_vehicle_id',
                ],
                'sortableAttributes' => [
                    'created_at',
                    'card_number',
                ],
                'searchableAttributes' => [
                    'card_number',
                    'card_uid',
                    'user_name',
                    'apartment_number',
                    'vehicle_license_plate',
                ],
                'typoTolerance' => [
                    'enabled' => true,
                    'disableOnAttributes' => [
                        'card_number',
                        'card_uid',
                    ],
                ],
                'nonSeparatorTokens' => ['-', '_', '.'],
            ],
            VisitorRegistration::class => [
                'filterableAttributes' => [
                    'id',
                    'host_resident_user_id',
                    'apartment_id',
                    'qr_pass_status',
                    'is_pre_approved_by_resident',
                ],
                'sortableAttributes' => [
                    'expected_arrival_time',
                    'created_at',
                ],
                'searchableAttributes' => [
                    'registration_code',
                    'visitor_name',
                    'visitor_phone',
                    'vehicle_license_plate',
                    'host_name',
                    'apartment_number',
                    'visit_purpose',
                ],
                'typoTolerance' => [
                    'enabled' => true,
                    'disableOnAttributes' => [
                        'registration_code',
                        'vehicle_license_plate',
                    ],
                ],
                'nonSeparatorTokens' => ['-', '_', '.'],
            ],
            Parcel::class => [
                'filterableAttributes' => [
                    'id',
                    'apartment_id',
                    'recipient_user_id',
                    'status',
                    'courier_company',
                    'stored_location_type',
                ],
                'sortableAttributes' => [
                    'received_at',
                    'created_at',
                ],
                'searchableAttributes' => [
                    'tracking_number',
                    'recipient_name',
                    'recipient_phone',
                    'apartment_number',
                    'courier_company',
                ],
                'typoTolerance' => [
                    'enabled' => true,
                    'disableOnAttributes' => [
                        'tracking_number',
                    ],
                ],
                'nonSeparatorTokens' => ['-', '_', '.'],
            ],
        ],
    ],

];
