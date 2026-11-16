<?php

return [
    'url' => 'https://quanly-toanha-dancu.vercel.app',
    'updated_at' => '2026-11-10',
    'cli_version' => '1.0.0',
    // shortcut: file quotas are per instance; use a shared store for a global quota.
    'rate_limit_store' => env('PUBLIC_GUIDE_RATE_LIMIT_STORE', 'file'),
    'rate_limit' => 60,
    'rate_limit_window' => 60,
];
