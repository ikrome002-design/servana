<?php

declare(strict_types=1);

use App\Http\Controllers\SpaShellController;
use App\Http\Middleware\ResolveAccountHost;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Browser routes (Phase UI-02; ADR-016, ADR-017)
|--------------------------------------------------------------------------
|
| Every browser path that is not owned by a backend surface renders the Servana
| SPA shell on the resolved account host. Before UI-02 this file returned
| Laravel's stock `welcome` scaffold, which is what the deployed root actually
| served (UI01-PROV-001).
|
| Two properties are required of the shell route, and it takes both mechanisms
| to get them:
|
| 1. It must never SHADOW a real route. `Route::fallback()` is matched only after
|    every other route has failed to match, so a route registered later — by a
|    package, a test, or a future phase — still wins. A plain `/{path?}` catch-all
|    registered here would silently swallow it, which is precisely what happened
|    to the signed-URL regression suite during this phase.
|
| 2. Backend surfaces must never resolve to HTML. The `where` pattern is a
|    NEGATIVE LOOKAHEAD, so an unknown path under a backend prefix falls through
|    to a normal 404 (JSON for the API) instead of being handed the SPA shell:
|
|      api/...                   the /api/v1 surface
|      health, health/deep, up   liveness and readiness probes
|      sanctum/...               CSRF cookie
|      storage/...               signed file downloads
|      spa-assets/...            fingerprinted Vite output (served by Nginx)
|      assets/...                Laravel public assets, incl. brand
|      build/...                 reserved for build output
|
| `ResolveAccountHost` runs first: an unapproved host gets a safe 421 denial and
| never reaches the shell. Resolving a host grants nothing (ADR-017).
|
*/

$backendOwned = 'api|health|up|sanctum|storage|spa-assets|assets|build';

Route::middleware(ResolveAccountHost::class)->group(function () use ($backendOwned): void {
    Route::fallback(SpaShellController::class)
        ->where('fallbackPlaceholder', '^(?!(?:'.$backendOwned.')(?:/|$)).*$')
        ->name('spa.shell');
});

// The /health liveness probe is registered in bootstrap/app.php (outside the
// web middleware group) so it has no session/database dependency.
