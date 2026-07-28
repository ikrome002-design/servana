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

    /*
    |--------------------------------------------------------------------------
    | Idempotency & replay protection (Plan §13.5, §24.4, ADR-003; Phase R4)
    |--------------------------------------------------------------------------
    |
    | Every financial_mutation route requires an `Idempotency-Key`. A claim holds
    | a lock for `lock_ttl_seconds`; if a worker crashes, the lock expires and a
    | later identical request can safely recover it. Completed records are pruned
    | after a retention horizon: `retention_hours` for standard records, and
    | `retriable_retention_days` for support-retriable financial records. Active
    | locks are never pruned.
    |
    */
    'idempotency' => [
        'lock_ttl_seconds' => (int) env('IDEMPOTENCY_LOCK_TTL_SECONDS', 30),
        'retention_hours' => (int) env('IDEMPOTENCY_RETENTION_HOURS', 72),
        'retriable_retention_days' => (int) env('IDEMPOTENCY_RETRIABLE_RETENTION_DAYS', 30),
        'key_min_length' => 16,
        'key_max_length' => 255,
        // Max rows deleted per prune run (bounded cleanup).
        'prune_batch' => (int) env('IDEMPOTENCY_PRUNE_BATCH', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health probes (Plan §22.1, §79 R7; REM-OPS-001)
    |--------------------------------------------------------------------------
    |
    | Liveness (`GET /health`) is dependency-free. Readiness (`GET /health/deep`)
    | returns 200 only when every REQUIRED production dependency is healthy and
    | 503 otherwise. The required set is derived from the production topology
    | (docker-compose.prod.yml: managed PostgreSQL, Redis and S3); Redis backs the
    | cache and queue. Meilisearch is NOT required yet — search lands in Phase 22 —
    | so it is probed informationally and only degrades readiness, never fails it.
    | Local-only services (e.g. Mailpit) are never readiness dependencies.
    |
    | `require_configured` makes an unconfigured REQUIRED dependency fail readiness
    | (so production cannot silently treat a managed dependency as optional). It
    | defaults to true only in production; non-production allows an unconfigured
    | required dependency (e.g. S3 in CI) to pass as "skipped". A required
    | dependency that is configured but ERRORING always fails (503), in any env.
    |
    | `probe_timeout` bounds every network probe so a hung dependency cannot stall
    | the readiness response.
    |
    */
    'health' => [
        'required_dependencies' => ['database', 'redis', 'cache', 's3'],
        'optional_dependencies' => ['queue', 'meilisearch'],
        'require_configured' => (bool) env('HEALTH_REQUIRE_CONFIGURED', env('APP_ENV') === 'production'),
        'probe_timeout' => (float) env('HEALTH_PROBE_TIMEOUT', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Clients — contact protection (Plan §35, guardrail §6.4; Phase 15A)
    |--------------------------------------------------------------------------
    |
    | Client phone numbers are encrypted at rest (AES-256-GCM on APP_KEY) and are
    | searchable / duplicate-checked through a keyed HMAC-SHA256 *blind index*
    | (never a reversible deterministic ciphertext, never plaintext). The index
    | key is a dedicated secret, base64-encoded 32 bytes, separate from APP_KEY so
    | the blind index can be re-keyed independently. It is NEVER committed, logged,
    | or returned by any API. In non-production it falls back to a derivation of
    | APP_KEY so local/test runs work without extra setup; production MUST set
    | CLIENT_CONTACT_INDEX_KEY explicitly (asserted by a guard test).
    |
    */
    'clients' => [
        'contact_index_key' => env('CLIENT_CONTACT_INDEX_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling (Phase 15B)
    |--------------------------------------------------------------------------
    |
    | Personnel availability weekday/date resolution and derived current-state
    | calculation use branch business time. Plan §1 pins business-day logic to
    | Africa/Nairobi; timestamps remain UTC. Override only via a signed ADR.
    |
    */
    'scheduling' => [
        'business_timezone' => env('SCHEDULING_BUSINESS_TIMEZONE', 'Africa/Nairobi'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance benchmarking (Plan §72; Phase 24)
    |--------------------------------------------------------------------------
    |
    | Selects which documented dataset tier `PerformanceDatasetSeeder` builds.
    | Tiers and their engineering basis are defined in
    | `docs/performance/phase-24-benchmark-profile.md`. This only ever affects a
    | disposable benchmark database - the seeder refuses to run outside
    | local/testing and against any database whose name is not disposable.
    |
    */

    'performance' => [
        'tier' => env('SERVANA_PERF_TIER', 'baseline'),
    ],
];
