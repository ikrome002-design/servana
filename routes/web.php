<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 | Liveness probe (Plan Phase 1 acceptance + §22.1 observability).
 | Deliberately dependency-free so it returns 200 even before the database,
 | cache, or queue are provisioned (those arrive in Phases 2-3). A deeper
 | readiness probe that checks dependencies is added in Phase 3.
 */
Route::get('/health', function (): JsonResponse {
    return response()->json([
        'status' => 'ok',
        'service' => 'servana',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');
