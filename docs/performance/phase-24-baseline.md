# Phase 24 — Baseline: surface inventory and pre-optimization measurements

Companion to [`docs/proof/phase-24.md`](../proof/phase-24.md) and
[`phase-24-benchmark-profile.md`](./phase-24-benchmark-profile.md). This document records **what
exists** and **what it costs before any change**. Post-fix numbers live in
`phase-24-results.md`; keeping them apart is what makes the before/after comparison honest.

Baseline captured on branch `phase-24-performance-optimization` at base
`13f54a4df54a46abb2928783373383a87ba301d2`.

---

## 1. Runtime and topology inventory (measured in-container)

| Item | Value |
|---|---|
| Total registered routes | **301** |
| Routes exposing `GET` | **126** |
| `api/v1` collection-shaped GET endpoints (no path parameter) | **70** |
| Paginated call sites | **51** across **45** files |
| Migrations | **118** |
| Model factories | **80** |
| Application data-cache call sites | **2**, both the `HealthController` deep-probe (`Cache::put`/`Cache::forget` on `__deep_health__`) |
| Named rate limiters | **11** (`magic-link-request`, `magic-link-verify`, `registration`, `invitation-accept`, `mfa-confirm`, `mfa-challenge`, `api`, `finance-sensitive`, `search`, `file-upload`) |
| Pre-existing performance/query-count/N+1 tests | **0** |
| Dev topology | `app` (PHP-FPM), `nginx`, `postgres` 16.14, `redis` 7.4.9, `meilisearch`, `minio` + `minio-init`, `worker`, `file-worker`, `scheduler`, `mailpit` — 10 services healthy |
| Queue topology | single default worker + a dedicated `file-worker` + `scheduler`. **Class-separated production topology and Horizon are Phase 21N (blocked by Gate W) and are not introduced here.** |
| `DatabaseSeeder` | `PermissionSeeder` + one test user. **No demo tenant volume exists**, so a performance dataset must be constructed (Increment 2). |

### 1.1 Consequence: the cache-scope surface

Servana currently performs **no application-level data or response caching**. The only `Cache`
usage in `app/` is the health probe, and Redis otherwise backs sessions, queues, locks and rate
limiting. Rate-limiter keys are per-user (`identify($request)`) or per-IP, which is correct for
throttling and is not a data cache.

This is a materially different finding from "the cache keys are wrong", and it changes Increment 5's
work: there are no mis-scoped keys to correct, so the deliverable is a **forward guard** that fails
if a future phase introduces a tenant-, branch-, role- or masking-sensitive cache key without the
required dimensions — plus this truthful record. Per the phase rules a cache is **not** introduced
merely because a route is slow; where a route is slow the fix is an index or eager loading.

---

## 2. Live collection / read surface inventory

The **70** parameterless `api/v1` GET endpoints below are the candidate list/report/read-model
surface. Each is carried into the Increment 3 query/index review, where it receives route, role,
scope, filters, sorts, pagination, tables, existing indexes, query count, N+1 status, representative
row count, `EXPLAIN` plan, baseline p95 and a disposition.

**Scheduling / operations** — `appointments`, `queue-entries`, `queue/configuration`,
`service-sessions`, `personnel/me/appointments`, `personnel/me/queue`, `personnel/me/sessions`,
`branch/personnel-options`.

**Catalogue / clients / staff** — `services`, `service-categories`, `clients`, `staff`,
`staff-invitations`, `commission-rule-service-options`, `hr/permission-preview`, `branches`.

**Invoicing and merchant-client money** — `invoices`, `receipts`, `refunds`,
`payment-recording-groups`, `cash-ups`, `period-locks`, `finance-disputes`, `finance-exports`.

**Compensation** — `compensation-plans`, `commission-rules`, `compensation/adjustments`,
`compensation/liabilities`, `compensation/liabilities/summary`, `finance/earnings-queries`,
`finance/payout-runs`, `hr/payout-runs`, `merchant/payout-runs`, `merchant/compensation-summary`,
`personnel/me/compensation`, `personnel/me/earnings`, `personnel/me/earnings-queries`,
`personnel/me/payouts`.

**Billing / subscription (merchant side)** — `subscription`, `subscription/plans`,
`subscription-invoices`, `subscription/scheduled-plan-change`, `platform-fees`,
`platform-fees/summary`, `platform-fee-disputes`, `branch/preferred-personnel-fee-rule`,
`merchant/profile`, `merchant/dashboard`, `merchant-registration/first-time-setup`.

**Platform (super-admin)** — `platform/merchants`, `platform/plans`, `platform/settings`,
`platform/billing-settings`, `platform/billing/platform-fee-configurations`,
`platform/free-period-offers`, `platform/promotional-discounts`,
`platform/preferred-personnel-fee-rules`, `platform/registration-monitor`, `platform/audit-logs`.

**Audit** — `audit-logs`, `audit-logs/compensation`, `audit-logs/finance`, `audit-flagged-events`,
`audit-exports`.

**Messaging / search / identity** — `personnel/me/sms-campaigns`,
`personnel/me/served-clients/sms`, `search`, `me`, `auth/mfa`.

Detail-route (`{ulid}`) reads and the **170** mutating routes are inventoried with the write-path
measurements in Increment 3; the §72 write target is verified against representative shipped writes.

### 2.1 Surfaces deliberately absent

| Surface | Why absent | Owner |
|---|---|---|
| Wallet payment initiation, webhook ack, reconciliation | External Gate W CLOSED | 20D-W |
| R&E qualification / inbound reconciliation | needs 20D-W | 21R-B |
| Scheduled report generation, day-close/cash-up PDFs, notifications, Horizon, class-separated queues | needs 20D-W | 21N |

These are recorded as `blocked_external_gate` in the §72 matrix. No route, scheduler or read model
is fabricated to produce a benchmark for them.

---

## 3. Confirmed pre-optimization defects (proven at Increment 1, from code)

These are established by reading the current implementation; their **measured** cost is recorded in
the increment that fixes each one.

### 3.1 PH24-QUEUE-001 — estimator recomputation re-resolves availability per entry

`QueueWaitEstimator::recalculateBranch()` (`app/Domain/Scheduling/Services/QueueWaitEstimator.php`)
iterates every active entry and calls `estimateFor()`. Per entry:

| Step | Queries |
|---|---|
| entries ahead (`position <`) | 1 |
| eager-loaded `service:id,duration_minutes` | 1 |
| `availableCapacity()` → `ServicePersonnelEligibility` | 1 |
| `availableCapacity()` → `StaffProfile` | 1 |
| `AvailabilityResolver::currentState($s)` → `rowsFor($staff)` **per staff member** | S |

Total ≈ **E × (4 + S)** for E active entries and S eligible staff. The eligibility set, staff set and
availability rows are identical for every entry of the same service, yet are re-fetched for each
entry, and `currentState()` re-queries because the optional `$rows` argument it already supports is
never supplied. This is the Phase 16B carry-forward.

### 3.2 PH24-QUEUE-002 — busy personnel inflate `active_capacity`

`QueueWaitEstimator::availableCapacity()` counts staff whose **schedule-derived**
`AvailabilityResolver::currentState()` is `Available`. `AvailabilityResolver`'s own docblock records
that "`busy` is NOT computed here (queue/session aggregates — Phases 16B/16C)". The authoritative
overlay `PersonnelStateProjector` — which returns `Busy` when an `in_progress` `ServiceSession`
exists for the staff member, and which the availability read already uses
(`StaffAvailabilityController.php:119`) — is never consulted by the estimator.

Effect: a personnel member currently mid-session still counts as capacity, so
`estimated_wait = ceil(queued_work_minutes / active_capacity)` divides by an inflated denominator and
**under-estimates** the wait shown to clients. This is a correctness defect with a performance
dimension, and is the Phase 16C carry-forward. The deterministic formula and the "Estimate" label
are preserved; only the denominator's definition of *available* is aligned with the authoritative
projection.

### 3.3 PH24-OPCACHE-001 — production preload claimed but absent

`docker/php.Dockerfile:3` and `:76` describe the `prod` stage as "optimized, no dev deps, **opcache
preload**", and `docker/php/opcache.ini:1` says "preload in prod". No `opcache.preload` or
`opcache.preload_user` directive exists anywhere in the repository; the `prod` stage performs only
`composer install --no-dev --optimize-autoloader`. OPcache itself **is** correctly enabled with
`validate_timestamps=0` in prod and `=1` in dev. The documentation therefore overstates the runtime.

### 3.4 PH24-BUNDLE-001 — all-role landing/FAQ content in one eager module

`resources/spa/src/content/roleContent.ts` statically imports 16 markdown documents (8 landing + 8
FAQ) with `?raw` into a single `SOURCES` record keyed by `RoleIdentity`. Because the imports are
static, every consumer of the module —
`components/layout/RoleLandingScaffold.vue`, `components/legal/LegalAcknowledgement.vue`,
`pages/legal/LegalDocument.vue`, `content/legalContent.ts` — causes **all eight roles'** landing and
FAQ text to be bundled and shipped, no matter which single role is signed in. The ~3 MB of legal
documents are already lazy per document, so this is the residual eager payload.

---

## 4. Measurements pending

| Section | Increment |
|---|---|
| Dataset tier row counts as actually generated; harness commands | 2 |
| Per-surface query counts, `EXPLAIN (ANALYZE, BUFFERS)` plans, index evidence, N+1 findings, baseline p95 | 3 |
| Estimator query counts before/after; busy-exclusion behaviour | 4 |
| Cache-scope guard | 5 |
| Bundle sizes before/after (raw, gzip, Brotli where tooling supports it) | 6 |
| OPcache/preload cold and warm measurements | 7 |
| Full three-run §72 matrix | 8 |

Nothing is recorded in this document until it has been run.
