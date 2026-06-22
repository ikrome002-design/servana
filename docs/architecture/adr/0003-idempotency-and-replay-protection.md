# ADR-003 — Idempotency and Replay Protection

- **Status:** Accepted (Phase R4, REM-IDEMP-001).
- **Date:** 2026-06-22.
- **Plan refs:** §4 finding 9, §5.3, §8 (ADR-003), §9.4 step 16, §13.5, §24.1–§24.4.
- **Supersedes:** the prior invalid `idempotency_keys` definition (no `key_hash`,
  unique on `(merchant_id, key_hash)`) that Plan §4 finding 9 contradicted. That
  table never existed in the repo, so this is a clean forward-only create.

## Context and failure modes

Financial mutations (payment recording/validation, refunds, payouts, subscription
payment initiation, invoice finalization, reconciliation resolution, billing
credit — Plan §24.1) must produce **exactly one** durable effect even when a
client retries, double-submits, or a provider redelivers a callback. Without a
durable idempotency substrate the future financial phases (17–18, 20D) could
double-charge, double-validate, or double-pay on:

- a user double-clicking / a client auto-retry on a slow response;
- two concurrent submissions of the same operation;
- a worker crash after the effect but before the response is recorded;
- a provider redelivering the same callback (Phase 20D).

R4 builds the reusable substrate now (pre-feature gate, Plan §5.4) so every owning
phase attaches it rather than reinventing it.

## Decision

A single `idempotency_keys` table + a reusable `EnsureIdempotentRequest`
middleware + an `IdempotencyStore`, with PostgreSQL as the correctness boundary.

### Scope derivation
`IdempotencyScopeResolver` derives a deterministic scope that embeds identity:
`merchant:{merchant-ulid}:user:{user-ulid}`, `platform:user:{user-ulid}`, or
`webhook:{provider}:{environment}`. The scope is part of the unique key, so the
same raw key under a different merchant/actor/provider environment never collides.

### Raw-key hashing
Only `SHA-256(raw Idempotency-Key)` is stored (`key_hash`). The raw key never
touches the database, logs, or audit. Keys must be 16–255 printable-ASCII chars.

### Canonical request hashing
`CanonicalRequestHasher` hashes method + route name + normalized (pre-binding)
path params + normalized content type + the **recursively key-sorted** body.
Equivalent JSON with different key ordering hashes identically; a materially
different method/path/body/content type hashes differently. Volatile headers and
transport values are excluded.

### PostgreSQL uniqueness and locks
- `UNIQUE (idempotency_scope, key_hash)` is the first-claim boundary. The first
  claim is an `INSERT ... ON CONFLICT DO NOTHING` (`insertOrIgnore`); the single
  inserter wins and everyone else falls through to locked resolution. This avoids
  the aborted-transaction trap of catching a unique violation mid-transaction.
- Existing-row resolution runs under `SELECT ... FOR UPDATE`, so only one worker
  can reclaim an expired/failed row.

### Active vs expired lock behaviour
A row carries `locked_at` + `lock_expires_at`. A lock is *active* while
`lock_expires_at > now()`:
- `completed` + same request → **replay** the stored response.
- different request → **409 `idempotency_key_reused_with_different_request`**.
- `processing` + active lock → **409 `request_in_progress`** + `Retry-After`.
- `processing` + expired lock → reclaim under `FOR UPDATE`, re-execute.
- `failed` → reclaim and retry (server failures are retryable).

### Transaction boundary
The claim's first-insert is a single statement; existing-row resolution is a short
`FOR UPDATE` transaction. The domain action runs after the claim is recorded as
`processing` (the lock is visible to concurrent callers). On completion the row is
marked `completed` and the lock released; on a thrown/5xx failure it is marked
`failed` (lock released, retryable) with only a redacted code. The owning phase's
domain action remains responsible for its own DB transaction + row locks on the
ledger (Plan §24.1); idempotency is the request-level guard, not a substitute for
ledger-level integrity.

### Stable failures
2xx and deterministic 4xx are stored as `completed` and replayed verbatim
(deterministic client errors must not change on retry). 5xx and transient 4xx
(408/425/429) are stored as `failed` with a redacted code and are retryable.

### Encrypted replay storage
`response_body_encrypted` stores the JSON body encrypted at rest
(`encrypted:array` cast, AES-256-GCM via `APP_KEY`). Streamed/binary/non-JSON
responses are never cached.

### Safe-header allowlist
Only an explicit allowlist (`content-type`) is persisted/replayed. Never
`Set-Cookie`, `Cookie`, `Authorization`, `Proxy-Authorization`, `X-XSRF-TOKEN`,
session/CSP/signed-URL/`Server`/debug headers. A replay adds `Idempotent-Replay:
true` and never exposes key hashes or row ids.

### Retention / pruning
`idempotency:prune` deletes rows past `expires_at` in bounded batches but **never**
an active processing lock. Standard completed records are retained ≥72h
(`IDEMPOTENCY_RETENTION_HOURS`); support-retriable financial records ≥30d
(`IDEMPOTENCY_RETRIABLE_RETENTION_DAYS`). Scheduled daily via `schedule:work`.

### Provider dedupe seam
`ProviderReplayGuard` reuses the store with a `webhook:{provider}:{environment}`
scope, `key_hash = SHA-256(correlationId)`, and `request_hash = payloadHash`:
first → process; same correlation+payload → duplicate; same correlation +
different payload → mismatch. It is provider-agnostic — no M-Pesa tables/routes
and no signature rules.

### Financial-route classification seam
`RouteClass` + `RouteClassification` (route-defaults key `route_class`).
`FinancialRouteIdempotencyCoverageTest` fails when any `financial_mutation` route
lacks the middleware. Phase 10 extends this into the full RouteSecurityContractTest
without replacing the `route_class` key.

## Future integration
- **Phase 10:** full route-classification/OpenAPI contract reuses `route_class`.
- **Phases 17–18:** invoice/payment/refund routes attach `EnsureIdempotentRequest`
  (financial classification) + ledger-level unique constraints.
- **Phase 20D:** binds `ProviderReplayGuard` to M-Pesa correlation ids, the
  callback inbox uniqueness, and receipt-number constraints (ADR-006).
- **Phases 20A/20F–H:** billing/payout/compensation routes attach the same control.

## Rollout and forward repair
Forward-only additive migration; `down()` drops the table; no backfill. Disabling
enforcement is route/config-level, never a destructive migration. Pruning is
bounded and idempotent.

## Limitations
- **Crash after effect, before completion:** the expired-lock path re-executes,
  so request-level idempotency alone does not guarantee exactly-once across a
  crash; the domain action's own ledger uniqueness (owning phase) closes that gap.
- True OS-parallel contention is enforced by the PostgreSQL unique constraint +
  `FOR UPDATE`; the test harness exercises the constraint and the claim state
  machine deterministically (it does not fork OS processes).
- Only JSON responses are replay-safe; streamed/binary responses are not cached.
