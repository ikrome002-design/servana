<?php

declare(strict_types=1);

/*
 | Personnel bulk SMS (Plan §64, §13.13, §20, §24.5; ADR-010; Phase 21S).
 |
 | NO SECRET HAS A DEFAULT. Every provider credential is env-only and null when unset, and the
 | integration fails closed rather than guessing: with the integration disabled, or with any
 | required key missing, the deterministic FakeSmsProviderClient is bound and no live provider can
 | be reached. That binding is unconditional in the `testing` environment, so CI can never call a
 | provider (Plan §81 rule 21).
 |
 | The Plan pins NO SMS provider, no result-code vocabulary and no tariff. The values below that are
 | not credentials are configured PLACEHOLDERS carried by REM-SMS-002 (deferred verification, must
 | close before Phase 25): the per-segment unit price, the max batch size and the message length
 | cap. They are configuration, not code, so pinning them is a config change, not a rewrite.
 */

return [

    /*
    | Master switch. False by default and always in tests. When false, campaigns are still composed,
    | confirmed, snapshotted, billed and driven through their full lifecycle — the fake provider
    | accepts every send deterministically — but no HTTP call to any provider is possible.
    */
    'enabled' => (bool) env('SMS_ENABLED', false),

    // Provider slug recorded on every sms_delivery_attempts row (bounded to 32 chars).
    'provider' => env('SMS_PROVIDER', 'fake'),

    /*
    | Provider credentials. No default: unset ⇒ the real client cannot be constructed and the fake
    | is bound. HttpSmsProviderClient THROWS on a missing value rather than sending unauthenticated.
    */
    'base_url' => env('SMS_BASE_URL'),
    'api_key' => env('SMS_API_KEY'),
    'sender_id' => env('SMS_SENDER_ID'),
    'contract_version' => env('SMS_CONTRACT_VERSION'),

    /*
    | Composition limits (Plan §64 "configurable max batch" / "configurable char/segment limit").
    | The batch cap is what stops a single campaign from becoming a bulk contact operation, so it is
    | enforced server-side by SmsBatchLimiter at BOTH preview and confirm — never by the frontend.
    */
    'limits' => [
        'max_recipients_per_campaign' => (int) env('SMS_MAX_RECIPIENTS_PER_CAMPAIGN', 200),
        'max_message_characters' => (int) env('SMS_MAX_MESSAGE_CHARACTERS', 480),
        'max_segments_per_message' => (int) env('SMS_MAX_SEGMENTS_PER_MESSAGE', 4),
    ],

    /*
    | Tariff (Plan §64 "estimated KES cost", ADR-005 integer minor units). Cost is charged per
    | SEGMENT per RECIPIENT. The price is a configured placeholder pending REM-SMS-002; it is
    | never a float and never computed by the frontend.
    */
    'pricing' => [
        'unit_cost_minor' => (int) env('SMS_UNIT_COST_MINOR', 100),
        'currency' => env('SMS_CURRENCY', 'KES'),
    ],

    /*
    | Delivery retry policy (Plan §64 "retry transient (capped backoff), not permanent invalid/
    | opt-out failures"). `max_attempts` includes the first attempt, so 4 means one send plus three
    | retries; exhausting them dead-letters the recipient with a high-severity audit event.
    */
    'delivery' => [
        'queue' => env('SMS_QUEUE', 'default'),
        'timeout_seconds' => (int) env('SMS_TIMEOUT_SECONDS', 10),
        'max_attempts' => (int) env('SMS_MAX_ATTEMPTS', 4),
        'backoff_base_seconds' => (int) env('SMS_BACKOFF_BASE_SECONDS', 60),
        'backoff_cap_seconds' => (int) env('SMS_BACKOFF_CAP_SECONDS', 3600),
        // Bound on the redacted provider message persisted per attempt (column is varchar(512)).
        'response_body_max_chars' => 512,

        /*
        | Whether a provider DELIVERY RECEIPT channel exists. FALSE in Phase 21S: no provider is
        | contracted, so no authenticated receipt endpoint could be shipped (Plan §24.1 forbids an
        | unverifiable provider webhook) — REM-SMS-002 owns bringing one online.
        |
        | This flag is what makes campaign settlement honest either way:
        |   - false ⇒ `sent` (accepted by the provider) is the final knowledge Servana has, so it
        |     counts as success and a campaign settles as soon as nothing is `pending`;
        |   - true  ⇒ `sent` is still outstanding, and only a receipt moves it to `delivered` or
        |     `failed`, which is when the campaign settles.
        | Nothing is ever fabricated: Servana never claims `delivered` without a receipt.
        */
        'receipts_enabled' => (bool) env('SMS_DELIVERY_RECEIPTS_ENABLED', false),
    ],
];
