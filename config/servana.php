<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | Base URL of the Vue SPA, used to build Magic Link verify URLs
    | ({frontend}/auth/verify?token=…). Defaults to APP_URL since Nginx serves
    | the SPA and the API from the same origin in dev (Plan §4.1).
    |
    */
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:8080')),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | enforce_tenancy_eligibility — Scope §2.3 checks 2 & 4 (active merchant
    | membership / active role). Phase 6 implemented the merchant tenancy schema
    | and the real lookups, so this now defaults TRUE: a Magic Link is issued only
    | to a user with an active membership (or platform staff). Check 6 (branch
    | assignment) remains deferred to Phase 7 regardless of this flag. The env var
    | can still force it off for diagnostics.
    |
    */
    'auth' => [
        'enforce_tenancy_eligibility' => (bool) env('AUTH_ENFORCE_TENANCY_ELIGIBILITY', true),

        // Sliding idle timeout in minutes (Plan §9.2). Authenticated requests
        // reset the clock; exceeding it logs the session out.
        'idle_timeout_minutes' => (int) env('AUTH_IDLE_TIMEOUT_MINUTES', 60),
    ],
];
