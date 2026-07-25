<?php

declare(strict_types=1);

/*
 | Laravel Scout — Servana search substrate (Plan §68; Phase 22; decision D-22-02).
 |
 | Hand-written rather than vendor-published so that every key present is a key
 | Servana actually uses, and so `identify` is explicitly false: Scout's identify
 | feature forwards the caller's IP and user id to the search engine, which would
 | put request-identifying data into a system that must hold nothing but
 | tenant-filterable match text (§74, §24.5).
 |
 | The engine host and key are SERVER-ONLY. They are read here, consumed by the
 | Scout engine inside the API container and the queue workers, and never reach a
 | Resource, OpenAPI, the generated TypeScript contract, a log line, or the SPA
 | bundle (no VITE_* variable references them). See docs/architecture/search/
 | search-security.md §5.
 */

return [

    /*
     | testing forces `null` (Scout's NullEngine) through phpunit.xml, so the
     | pre-existing suite carries zero indexing coupling; the Phase 22 tests that
     | must prove real engine behaviour opt in explicitly.
     */
    'driver' => env('SCOUT_DRIVER', 'meilisearch'),

    /*
     | Environment-derived so no two environments can ever address the same index
     | and a test run cannot touch the dev indexes.
     */
    'prefix' => env('SCOUT_PREFIX', 'servana_'.env('APP_ENV', 'local').'_'),

    /*
     | Indexing is queued on its own connection/queue: a search index is never on
     | the critical path of a business write, and a slow engine must not slow a
     | financial transaction (§72).
     */
    'queue' => [
        'connection' => env('SCOUT_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'redis')),
        'queue' => env('SCOUT_QUEUE_NAME', 'search-index'),
    ],

    /*
     | Index only after the database transaction commits — a rolled-back write must
     | never leave a phantom document behind.
     */
    'after_commit' => true,

    'chunk' => [
        'searchable' => 250,
        'unsearchable' => 250,
    ],

    /*
     | Servana soft-deletes by status rather than destroying rows, so no model uses
     | Eloquent SoftDeletes for these types and Scout's soft-delete support is off.
     */
    'soft_delete' => false,

    /*
     | NEVER true. See the header note.
     */
    'identify' => false,

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://meilisearch:7700'),
        'key' => env('MEILISEARCH_KEY'),

        /*
         | Applied by `php artisan scout:sync-index-settings`.
         |
         | `displayedAttributes` is `id` only: the engine is asked to return nothing
         | but candidate ULIDs, so even a misconfigured index cannot surface a field.
         | Every displayed value is read from PostgreSQL during the mandatory
         | per-record re-authorization pass.
         |
         | `sortableAttributes` is empty everywhere by design: ordering is either
         | engine relevance or a PostgreSQL `created_at desc`, so no user-supplied
         | token can ever reach an engine sort expression.
         |
         | `filterableAttributes` is exactly the tenancy pair — the only filter the
         | server ever emits.
         |
         | Keys here are the UNPREFIXED index names; Scout resolves the prefix.
         */
        'index-settings' => [
            'clients' => [
                'searchableAttributes' => ['full_name'],
                'filterableAttributes' => ['merchant_id', 'branch_id'],
                'sortableAttributes' => [],
                'displayedAttributes' => ['id'],
            ],
            'staff_profiles' => [
                'searchableAttributes' => ['display_name', 'first_name', 'last_name', 'role_title'],
                'filterableAttributes' => ['merchant_id', 'branch_id'],
                'sortableAttributes' => [],
                'displayedAttributes' => ['id'],
            ],
            'services' => [
                'searchableAttributes' => ['name'],
                'filterableAttributes' => ['merchant_id', 'branch_id'],
                'sortableAttributes' => [],
                'displayedAttributes' => ['id'],
            ],
            'appointments' => [
                'searchableAttributes' => ['reference', 'client_name', 'service_name'],
                'filterableAttributes' => ['merchant_id', 'branch_id'],
                'sortableAttributes' => [],
                'displayedAttributes' => ['id'],
            ],
            'queue_entries' => [
                'searchableAttributes' => ['reference', 'client_name', 'service_name'],
                'filterableAttributes' => ['merchant_id', 'branch_id'],
                'sortableAttributes' => [],
                'displayedAttributes' => ['id'],
            ],
            'service_sessions' => [
                'searchableAttributes' => ['reference', 'client_name', 'service_name'],
                'filterableAttributes' => ['merchant_id', 'branch_id'],
                'sortableAttributes' => [],
                'displayedAttributes' => ['id'],
            ],
            'invoices' => [
                'searchableAttributes' => ['invoice_number', 'reference', 'client_name'],
                'filterableAttributes' => ['merchant_id', 'branch_id'],
                'sortableAttributes' => [],
                'displayedAttributes' => ['id'],
            ],
            'receipts' => [
                'searchableAttributes' => ['receipt_number', 'invoice_number', 'reference'],
                'filterableAttributes' => ['merchant_id', 'branch_id'],
                'sortableAttributes' => [],
                'displayedAttributes' => ['id'],
            ],
        ],
    ],

];
