<?php

declare(strict_types=1);

use App\Analytics\ErrorReporter;
use App\Http\Controllers\IngestProxyController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // posthog-js reverse proxy, outside the web group: no session, cookies or CSRF
            Route::any(config('posthog.proxy_path').'/{path?}', IngestProxyController::class)
                ->where('path', '.*')
                ->name('posthog.ingest');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // PostHog error tracking, alongside (not instead of) Laravel's own logging
        $exceptions->report(function (Throwable $exception): void {
            app(ErrorReporter::class)->report($exception);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
