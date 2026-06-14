<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\MagicLinkController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Middleware\EnforceIdleTimeout;
use Illuminate\Support\Facades\Route;

/*
 | API v1 routes (prefix /api/v1, registered in bootstrap/app.php).
 |
 | Phase 5 adds the authentication surface (Plan §9). The broader versioned
 | resource surface (Plan §11.2) is still built in Phase 10.
 */

Route::middleware('throttle:api')->group(function (): void {
    // Phase 10 adds versioned resource routes here.
});

/*
 | Authentication (Plan §9.1–§9.3). Public endpoints carry only their named
 | Magic Link limiters — NOT the per-user `api` limiter — because the caller is
 | unauthenticated. Authenticated endpoints require the Sanctum session guard.
 */
Route::prefix('auth')->group(function (): void {
    Route::post('magic-link', [MagicLinkController::class, 'request'])
        ->middleware('throttle:magic-link-request')
        ->name('auth.magic-link.request');

    Route::post('magic-link/verify', [MagicLinkController::class, 'verify'])
        ->middleware('throttle:magic-link-verify')
        ->name('auth.magic-link.verify');

    Route::post('logout', [MagicLinkController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('auth.logout');
});

Route::middleware(['auth:sanctum', EnforceIdleTimeout::class, 'throttle:api'])->group(function (): void {
    Route::get('me', [MeController::class, 'show'])->name('me');
});
