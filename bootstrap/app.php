<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Caddy -> nginx -> PHP-FPM inside the Docker network: trust only private
        // addresses so request()->ip() is the real client from X-Forwarded-For
        // (rate limits and audit logs used to see the proxy's address for everyone).
        $middleware->trustProxies(at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);
        $middleware->append(\App\Http\Middleware\RequestMetrics::class);
        $middleware->web(append: [\App\Http\Middleware\HandleInertiaRequests::class,\App\Http\Middleware\AuditAction::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['icafe_token', 'bot_token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
