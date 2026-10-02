<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Setup writable storage directory in /tmp for Vercel Serverless environment
$storagePath = '/tmp/storage';
putenv('LARAVEL_STORAGE_PATH='.$storagePath);
$_ENV['LARAVEL_STORAGE_PATH'] = $storagePath;
$_SERVER['LARAVEL_STORAGE_PATH'] = $storagePath;

$directories = [
    $storagePath.'/app/public',
    $storagePath.'/framework/cache/data',
    $storagePath.'/framework/sessions',
    $storagePath.'/framework/views',
    $storagePath.'/logs',
    $storagePath.'/bootstrap/cache',
];

foreach ($directories as $dir) {
    if (! is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Fallback application key if not configured in environment
if (empty($_ENV['APP_KEY']) && empty(getenv('APP_KEY'))) {
    $fallbackKey = 'base64:6bW+uN89m43gZpP5xLqA7K2s8D9F0G1H2J3K4L5M6N7=';
    putenv('APP_KEY='.$fallbackKey);
    $_ENV['APP_KEY'] = $fallbackKey;
    $_SERVER['APP_KEY'] = $fallbackKey;
}

// Ensure SQLite database file exists in /tmp if using sqlite
$sqlitePath = $storagePath.'/database.sqlite';
if (! file_exists($sqlitePath)) {
    @touch($sqlitePath);
}
// Maintenance check
if (file_exists($maintenance = $storagePath.'/framework/maintenance.php')) {
    require $maintenance;
}

// Register composer autoloader
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->useStoragePath($storagePath);

$app->handleRequest(Request::capture());
