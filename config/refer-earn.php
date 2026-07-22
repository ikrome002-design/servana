<?php

declare(strict_types=1);

/*
 | Citrus Refer & Earn integration (Plan §58A, §17.1, §9 rules 22-24, §77.1; ADR-013/015;
 | Phase 21R-A). Servana is a SOURCE PRODUCT here: it captures referrals and emits signed facts.
 | It owns no reward logic.
 |
 | NO SECRET HAS A DEFAULT. Every credential is env-only and null when unset, and the integration
 | fails closed rather than guessing: with no base URL, key id or algorithm, delivery is disabled and
 | the FakeReferEarnClient is bound. That is exactly the Plan §80 Phase 21R-A fallback for "R&E
 | sandbox unavailable", and it is why CI never calls a live partner (Plan §81 rule 21).
 |
 | Contract-pin status: docs/integrations/refer-earn/contract-pins.md.
 | Credential-receipt status: docs/integrations/refer-earn/credentials-receipt.md.
 */

return [

    /*
    | Master switch. When false (the default, and always in tests) NOTHING is sent to R&E: capture
    | and outbox emission still happen — the local evidence must survive regardless — but delivery
    | is skipped. Registration is never affected either way (Plan A-19).
    */
    'enabled' => (bool) env('REFER_EARN_ENABLED', false),

    /*
    | Servana's product code at R&E. Plan §81 rule 24 records `SRV` as an ASSUMPTION, not a pin:
    | the real code is assigned by R&E and is tracked by REM-RE-002.
    */
    'product_code' => env('REFER_EARN_PRODUCT_CODE', 'SRV'),

    /*
    | Environment label carried in every payload envelope (§58B.2). Distinct from APP_ENV only so a
    | partner-facing label can be pinned independently of Laravel's local naming.
    */
    'environment' => env('REFER_EARN_ENVIRONMENT', env('APP_ENV', 'local')),

    // Base URL of the R&E API. No default: unset ⇒ delivery disabled.
    'base_url' => env('REFER_EARN_BASE_URL'),

    'signing' => [
        /*
        | ADR-015: verification/signature routine is selected by algorithm identifier + key id +
        | contract version, and HMAC-SHA-256 is NOT hardcoded. Null here means "unpinned", and
        | CitrusEventSigner THROWS rather than falling back — an unsigned or wrongly-signed event
        | must never reach a partner.
        */
        'algorithm' => env('REFER_EARN_SIGNING_ALGORITHM'),
        'key_id' => env('REFER_EARN_SIGNING_KEY_ID'),
        'secret' => env('REFER_EARN_SIGNING_SECRET'),
        'contract_version' => env('REFER_EARN_CONTRACT_VERSION', '1'),
    ],

    /*
    | Delivery retry policy (Plan §58A.2: exponential backoff with jitter, base 30 s, cap 1 h,
    | max age 7 days → dead-letter + alert).
    */
    'delivery' => [
        'queue' => env('REFER_EARN_QUEUE', 're-outbox'),
        'timeout_seconds' => (int) env('REFER_EARN_TIMEOUT_SECONDS', 10),
        'backoff_base_seconds' => (int) env('REFER_EARN_BACKOFF_BASE_SECONDS', 30),
        'backoff_cap_seconds' => (int) env('REFER_EARN_BACKOFF_CAP_SECONDS', 3600),
        'max_age_days' => (int) env('REFER_EARN_MAX_AGE_DAYS', 7),
        // Bound on the redacted response body persisted per attempt (column is varchar(512)).
        'response_body_max_chars' => 512,
        // Batch size for the dispatcher sweep (Plan §72: 100/sweep).
        'sweep_batch' => (int) env('REFER_EARN_SWEEP_BATCH', 100),
    ],

    /*
    | Referral capture (Plan §58A.1). The code pattern is the Plan's `SERVANA-XXXXX` shape; a real
    | R&E-published pattern would replace it as a contract pin, not a code change.
    */
    'capture' => [
        'code_pattern' => env('REFER_EARN_CODE_PATTERN', '/^SERVANA-[A-Z0-9]{5,16}$/'),
        'max_submitted_length' => 64,
        // Plan §81 rule 24 lists the R&E confirm window as a blocking ambiguity; this is a
        // conservative configured default, tracked by REM-RE-002 until R&E publishes it.
        'confirm_window_hours' => (int) env('REFER_EARN_CONFIRM_WINDOW_HOURS', 168),
        /*
        | Landing-metadata allowlist (Plan §13.17 "utm-style minimal, no PII, allowlisted keys
        | only"). Anything not listed here is DROPPED, never stored. Names, emails, phones, IPs,
        | user agents, headers, cookies, session ids and free text are forbidden by construction:
        | they simply have no key to land in.
        */
        'landing_metadata_allowlist' => [
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'landing_path',
            'referrer_host',
            'capture_variant',
        ],
        'landing_metadata_max_value_length' => 128,
    ],

    // Async validation/confirmation seams (Plan §58A.1).
    'jobs' => [
        'queue' => env('REFER_EARN_JOBS_QUEUE', 're-outbox'),
        'tries' => (int) env('REFER_EARN_JOB_TRIES', 5),
    ],
];
