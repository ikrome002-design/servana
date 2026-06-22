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

    /*
    |--------------------------------------------------------------------------
    | MFA and Step-Up (Plan §18, Phase R3)
    |--------------------------------------------------------------------------
    |
    | TOTP MFA is mandatory for Super Administrator, Merchant Administrator and
    | Finance (resolved by MfaRequirementResolver). A confirmed credential must
    | be asserted once per session to reach privileged routes; designated
    | sensitive actions additionally require a *fresh* assertion within
    | `step_up_window_minutes`.
    |
    | The Plan does not pin a numeric freshness window, so a conservative 5-min
    | default is used (overridable via env). `totp_window` is the RFC 6238
    | acceptance window in time-steps either side of now (1 ⇒ ±30s for clock
    | drift); replay is prevented independently by last_used_timestep.
    |
    */
    'mfa' => [
        'issuer' => env('MFA_TOTP_ISSUER', 'Servana'),
        'totp_window' => (int) env('MFA_TOTP_WINDOW', 1),
        'recovery_code_count' => (int) env('MFA_RECOVERY_CODE_COUNT', 10),
        'step_up_window_minutes' => (int) env('MFA_STEP_UP_WINDOW_MINUTES', 5),
    ],
];
