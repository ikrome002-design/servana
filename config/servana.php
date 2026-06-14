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
    | enforce_tenancy_eligibility — Scope §2.3 checks 2/4/6 (active merchant
    | membership, active role, branch assignment) depend on the merchant tenancy
    | schema owned by Phases 6–7. While that schema is absent these checks cannot
    | be evaluated, so this flag stays false and LoginEligibilityService treats
    | them as not-yet-enforceable. Phase 6 implements the lookups and flips this
    | to true; no other auth code changes.
    |
    */
    'auth' => [
        'enforce_tenancy_eligibility' => (bool) env('AUTH_ENFORCE_TENANCY_ELIGIBILITY', false),

        // Sliding idle timeout in minutes (Plan §9.2). Authenticated requests
        // reset the clock; exceeding it logs the session out.
        'idle_timeout_minutes' => (int) env('AUTH_IDLE_TIMEOUT_MINUTES', 60),
    ],
];
