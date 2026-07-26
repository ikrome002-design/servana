# Search Indexing and Synchronization — Phase 22

> **Authority:** Plan **§68**, **§80 Phase 22**. Substrate choice recorded as decision **D-22-02**
> in `docs/proof/phase-22.md`.

---

## 1. Substrate

**Meilisearch v1.10 via Laravel Scout**, chosen because it is what the repository already
declares:

- `docker-compose.yml` runs `getmeili/meilisearch:v1.10` with a healthcheck and a named volume.
- `.env.example` already declares `SCOUT_DRIVER=meilisearch`, `MEILISEARCH_HOST` and
  `MEILISEARCH_KEY` under the comment *"Wired with Scout in Phase 22"*.
- `composer.json` already allowed the `php-http/discovery` plugin, a Meilisearch client dependency.
- Plan §68 names Meilisearch (as an example) and Phase 22 is its owning phase.

Phase 22 adds the minimum needed to honour that declaration: `laravel/scout`,
`meilisearch/meilisearch-php`, `http-interop/http-factory-guzzle`, and a hand-written
`config/scout.php`. The config is written rather than vendor-published so that every key present
is a key Servana actually uses, and so `identify` (which would send caller IP/id to the engine) is
explicitly `false`.

## 2. Environment matrix

| Environment | `SCOUT_DRIVER` | Effect |
|---|---|---|
| dev (`docker compose`) | `meilisearch` | real engine at `http://meilisearch:7700` |
| **testing** (`phpunit.xml`, `force=true`) | `null` | Scout's `NullEngine`. The pre-existing suite gets **zero** indexing coupling: no network, no latency, no async-index flakiness, and no behaviour change to any of the 2006 tests that existed before Phase 22. |
| Phase 22 engine tests | `meilisearch` (opted in per test) | the tests that must prove real engine behaviour re-bind the engine explicitly and use a run-scoped index prefix |
| CI | `null` by default; `meilisearch` for the engine tests via an ephemeral `getmeili/meilisearch:v1.10` service container added to the Backend job | real-engine proof runs in CI, not only locally |
| production | `meilisearch` | queued sync (`SCOUT_QUEUE=true`) on the `search-index` queue |

`config('scout.prefix')` is environment-derived (`servana_{APP_ENV}_`), so no two environments can
address the same index, and a test run cannot touch the dev indexes.

## 3. Index settings (applied server-side only)

Per index, declared in `config/scout.php` under `meilisearch.index-settings` and applied by
`php artisan scout:sync-index-settings`:

| Index | `searchableAttributes` | `filterableAttributes` | `sortableAttributes` | `displayedAttributes` |
|---|---|---|---|---|
| `clients` | `full_name` | `merchant_id`, `branch_id` | — | `id` |
| `staff_profiles` | `display_name`, `first_name`, `last_name`, `role_title` | `merchant_id`, `branch_id` | — | `id` |
| `services` | `name` | `merchant_id`, `branch_id` | — | `id` |
| `appointments` | `reference`, `client_name`, `service_name` | `merchant_id`, `branch_id` | — | `id` |
| `queue_entries` | `reference`, `client_name`, `service_name` | `merchant_id`, `branch_id` | — | `id` |
| `service_sessions` | `reference`, `client_name`, `service_name` | `merchant_id`, `branch_id` | — | `id` |
| `invoices` | `invoice_number`, `reference`, `client_name` | `merchant_id`, `branch_id` | — | `id` |
| `receipts` | `receipt_number`, `invoice_number`, `reference` | `merchant_id`, `branch_id` | — | `id` |

`displayedAttributes` is `id` only — the engine is asked to return nothing but candidate ULIDs, so
even a misconfigured index cannot surface a field. `sortableAttributes` is empty by design:
ordering is `relevance` (engine ranking) or `recent` (PostgreSQL `created_at desc`), so no
user-supplied token can reach an engine sort expression.

## 4. Document construction

One explicit builder per type in `app/Domain/Search/Documents/`. Each names every key it emits.
There is no `toArray()`, no attribute spread, and no loop over `$fillable`, so a new column can
never be indexed by accident. `Phase22IndexDocumentTest` asserts, for every type, that the emitted
key set is **exactly** the catalogue's declared set — an extra key fails the test.

Denormalized `client_name` / `service_name` / `invoice_number` values are resolved from
already-loaded relations. `makeSearchableUsing()` eager-loads them for the observer path, and the
reindex command eager-loads them per chunk, so no lazy load happens inside a queued job (where no
tenant context is bound).

Tenant/branch keys are written from the model's own `merchant_id` / `branch_id` columns
(`primary_branch_id` for `StaffProfile`), never from `TenantContext` — an index document must
describe the row, not whoever happened to save it.

## 5. Synchronization

- **Observers.** Scout's `Searchable` trait syncs on create/update/delete. `after_commit` is
  `true`, so a rolled-back transaction never leaves a phantom document.
- **Queue.** `SCOUT_QUEUE=true` on the dedicated `search-index` queue. Index lag is eventual by
  design and is never a correctness dependency: search re-resolves every candidate against
  PostgreSQL, so a stale document produces a *missing* result, never a wrong or leaked one.
- **Who drains it.** The dev `worker` container consumes `mail,default,search-index`, with
  `search-index` last because it is the lowest-priority work in the system. **The production worker
  is not wired for it here:** `docker-compose.prod.yml` is Phase 25's file (deployment) and its
  general worker currently consumes only the default queue, exactly as it does for the `mail` and
  `re-outbox` queues that earlier phases introduced. Full per-queue topology (Horizon, priorities,
  alerting) is Phase 21N's scope and is blocked behind External Gate W. Until then
  `servana:search-reindex` is the reconciliation path and `servana:search-verify-counts` is how the
  gap is detected — this is recorded as a residual risk in `docs/proof/phase-22.md`.
- **Deletes.** Servana soft-deletes by status rather than destroying rows, so archived records stay
  indexed and remain findable to authorized callers — their status is displayed from PostgreSQL.
  Hard deletes (none exist for these types today) would remove the document through the same
  observer.

## 6. Backfill — `servana:search-reindex`

```bash
php artisan servana:search-reindex [--type=client,invoice] [--chunk=250]
```

- **Idempotent.** Meilisearch upserts by primary key (the ULID), so re-running converges rather
  than duplicating.
- **Chunked.** Default 250 rows per batch, streamed with `chunkById`, relations eager-loaded per
  chunk. Memory stays flat on large tenants.
- **Forward-only and non-destructive.** The command **never** deletes or recreates an index. There
  is no `--fresh`, no implicit `scout:flush`. A stale document is corrected by the upsert or
  reported by the drift check.
- **Cross-tenant by design, and safe.** It runs outside a request, so the global scopes are
  no-ops (`MerchantScope` applies only when a merchant is resolved) and it indexes every tenant's
  rows — each document carrying its own `merchant_id`, which is exactly what makes the per-query
  filter work.

**Index aliases are not used** because Meilisearch v1.10 has no alias primitive (index swapping
would require `swapIndexes` plus a build-index naming scheme). The forward-only upsert approach is
therefore the documented strategy: no index is ever dropped in an environment that serves traffic,
and a settings change is applied in place by `scout:sync-index-settings`.

## 7. Drift verification — `servana:search-verify-counts`

```bash
php artisan servana:search-verify-counts [--type=client]
```

For each type it compares the engine's document count with the authoritative PostgreSQL row count
and reports `missing` / `excess` per index, exiting non-zero on any drift so it is usable as a
monitored check. It **reads only** — it never repairs, because a silent auto-repair would hide the
cause.

**Scheduling is deliberately not wired here.** Queue topology, the scheduler and scheduled
reporting are Phase 21N's scope (Plan §80.1: `(17,18,20D-W) → 21N`), and 21N is blocked behind
External Gate W. Phase 22 therefore ships the command and records that its **scheduled invocation
is owned by Phase 21N** — this is decision **D-22-05** and the reason no entry was added to
`routes/console.php` or the scheduler.

## 8. What indexing must never do

Never index an integration table (`referral_snapshots`, `re_outbound_events`,
`re_event_deliveries`, SMS recipient/attempt tables, any future `wallet_*` table, any provider
callback/inbox table). Never index `phone_encrypted`, `email_encrypted`, `phone_index`, any blind
index, `phone_last_four`, `StaffProfile::phone`, an SMS message body, or an SMS destination.
Never index free-text operator fields (`notes`, `*_reason`). Never send a phone-like query to the
engine. Never expose the engine host or key to the browser.
