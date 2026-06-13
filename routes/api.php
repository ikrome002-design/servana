<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 | API v1 routes (prefix /api/v1, registered in bootstrap/app.php).
 |
 | Intentionally minimal in Phase 3 — the full versioned surface (Plan §11.2)
 | is built in Phase 10. Registering this file enables the `api` middleware
 | group and the `/api/v1` prefix so the structured error envelope (Plan §11.5)
 | and the named `api` rate limiter (Plan §9.3) apply to API requests.
 */

Route::middleware('throttle:api')->group(function (): void {
    // Phase 10 adds versioned resource routes here.
});
