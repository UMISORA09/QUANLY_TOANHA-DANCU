<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Concurrency Worker for Resident API Testing
 *
 * Runs as an isolated sub-process with its own PHP runtime, memory space,
 * and independent MySQL database connection.
 * Synchronizes execution via precise microsecond barrier.
 */

require __DIR__.'/../../../vendor/autoload.php';
$app = require_once __DIR__.'/../../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$rawInput = file_get_contents('php://stdin');
$input = json_decode($rawInput, true);

if (! $input) {
    echo json_encode(['error' => 'Invalid stdin input']);
    exit(1);
}

$targetTime = (float) ($input['target_time'] ?? 0);
$token = $input['token'] ?? '';
$payload = $input['payload'] ?? [];
$mode = $input['mode'] ?? 'kernel'; // 'kernel' or 'http'
$url = $input['url'] ?? 'http://127.0.0.1:8000/api/v1/residents';

// Barrier synchronization: wait until target microsecond
if ($targetTime > 0) {
    while (microtime(true) < $targetTime) {
        // High-precision spin-wait for final 5ms
        $remaining = $targetTime - microtime(true);
        if ($remaining > 0.005) {
            usleep(1000);
        }
    }
}

$actualStart = microtime(true);

if ($mode === 'http') {
    // Send via real HTTP socket
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer '.$token,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $responseBody = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $actualEnd = microtime(true);

    echo json_encode([
        'status' => $status,
        'body' => $responseBody,
        'data' => json_decode((string) $responseBody, true),
        'error' => $error ?: null,
        'start' => $actualStart,
        'end' => $actualEnd,
        'duration_ms' => round(($actualEnd - $actualStart) * 1000, 2),
    ]);
} else {
    // Execute directly through isolated Laravel HTTP Kernel with independent DB connection
    $serverVars = [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ];
    if ($token !== '') {
        $serverVars['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    }

    $request = Request::create(
        '/api/v1/residents',
        'POST',
        [],
        [],
        [],
        $serverVars,
        json_encode($payload)
    );

    try {
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        $content = $response->getContent();
        $kernel->terminate($request, $response);
    } catch (Throwable $e) {
        $status = 500;
        $content = json_encode([
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }

    $actualEnd = microtime(true);

    echo json_encode([
        'status' => $status,
        'body' => $content,
        'data' => json_decode((string) $content, true),
        'start' => $actualStart,
        'end' => $actualEnd,
        'duration_ms' => round(($actualEnd - $actualStart) * 1000, 2),
    ]);
}
