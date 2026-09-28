<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return [
    /*
    |--------------------------------------------------------------------------
    | Sentry DSN
    |--------------------------------------------------------------------------
    */
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    /*
    |--------------------------------------------------------------------------
    | Release version matching Git Commit SHA
    |--------------------------------------------------------------------------
    */
    'release' => env('APP_VERSION', env('COMMIT_SHA', 'a83f21c')),

    'environment' => env('APP_ENV', 'production'),

    'sample_rate' => (float) env('SENTRY_SAMPLE_RATE', 1.0),

    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.2),

    'send_default_pii' => false,

    'ignore_exceptions' => [
        ValidationException::class,
        AuthenticationException::class,
        NotFoundHttpException::class,
    ],
];
