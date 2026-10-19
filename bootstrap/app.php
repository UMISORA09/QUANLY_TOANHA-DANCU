<?php

use App\Http\Middleware\AuthenticateBearer;
use App\Http\Middleware\CheckModuleCooldown;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\LockAmenityConfiguration;
use App\Http\Middleware\RequestIdMiddleware;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->append(RequestIdMiddleware::class);
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'health',
            'metrics',
        ]);

        $middleware->alias([
            'auth.bearer' => AuthenticateBearer::class,
            'permission' => CheckPermission::class,
            'amenity.lock' => LockAmenityConfiguration::class,
            'cooldown' => CheckModuleCooldown::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $e): Response {
            if ($response->getStatusCode() === 500) {
                $source = collect($e->getTrace())->first(fn (array $frame): bool => isset($frame['file']) && str_starts_with($frame['file'], app_path().DIRECTORY_SEPARATOR));
                error_log('APP_EXCEPTION_SUMMARY '.json_encode([
                    'class' => $e::class, 'code' => $e->getCode(),
                    'sqlstate' => $e instanceof QueryException ? ($e->errorInfo[0] ?? null) : null,
                    'driver_code' => $e instanceof QueryException ? ($e->errorInfo[1] ?? null) : null,
                    'source' => ($source['file'] ?? $e->getFile()).':'.($source['line'] ?? $e->getLine()),
                ]));
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

$app->dontMergeFrameworkConfiguration();

return $app;
