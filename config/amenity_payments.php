<?php

return [
    'bank_bin' => env('AMENITY_PAYMENT_BANK_BIN', '970436'),
    'bank_name' => env('AMENITY_PAYMENT_BANK_NAME', 'Vietcombank'),
    'account_number' => env('AMENITY_PAYMENT_ACCOUNT_NUMBER', '1037900935'),
    'account_name' => env('AMENITY_PAYMENT_ACCOUNT_NAME', 'DANG DANG NGUYEN'),
    'payment_minutes' => (int) env('AMENITY_PAYMENT_MINUTES', 15),
    'review_minutes' => (int) env('AMENITY_PAYMENT_REVIEW_MINUTES', 30),
];
