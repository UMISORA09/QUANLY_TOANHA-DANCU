<?php

use App\Http\Middleware\AuthenticateBearer;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\RequestIdMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $e) {
            error_log('APP_EXCEPTION: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

$app->dontMergeFrameworkConfiguration();

return $app;
