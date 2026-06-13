<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Liveness probe (Plan §22.1). Registered outside the web group so it
        // carries only global middleware — no session/CSRF, hence no database
        // dependency. It answers 200 even before migrations run. A deeper
        // readiness probe (DB/cache/queue) is added in Phase 3.
        then: function () {
            Route::get('/health', fn (): JsonResponse => response()->json([
                'status' => 'ok',
                'service' => 'servana',
                'timestamp' => now()->toIso8601String(),
            ]))->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
