<?php

declare(strict_types=1);

use App\Exceptions\ApiErrorRenderer;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\CorrelationIdMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Health probes live outside the web group: only global middleware,
            // so no session/CSRF/database dependency (Plan §22.1).
            Route::get('/health', [HealthController::class, 'live'])->name('health');
            Route::get('/health/deep', [HealthController::class, 'deep'])->name('health.deep');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Assign the correlation id first so it is available to logging and the
        // error envelope on every request (Plan §11.5, §22.1).
        $middleware->prepend(CorrelationIdMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report to Sentry — a no-op while SENTRY_LARAVEL_DSN is empty.
        Integration::handles($exceptions);

        // Render API / JSON exceptions as the structured envelope (Plan §11.5).
        $exceptions->render(function (Throwable $e, Request $request) {
            $renderer = app(ApiErrorRenderer::class);

            return $renderer->shouldHandle($request)
                ? $renderer->render($e, $request)
                : null;
        });
    })->create();
