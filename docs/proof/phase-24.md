# Phase 24 — Performance Optimization (Correction 24.2)

> **Lifecycle status: `local_complete pending PR CI/review/merge`.**
> Branch `phase-24-performance-optimization`, based on
> `13f54a4df54a46abb2928783373383a87ba301d2` (the verified Phase 23 squash-merge).
> Plan authority: §80 Phase 24 entry (Correction 24.2), **§72** (targets), §69 (reporting
> architecture), §67 (queues), §71 (observability), §13 (schema/indexes), §19 (authorization),
> §23–§25, §28–§30, §64–§65, §68, §73–§76, §80.1, §85.

Companion documents:

- [`docs/performance/phase-24-benchmark-profile.md`](../performance/phase-24-benchmark-profile.md) —
  environment, dataset tiers, methodology.
- [`docs/performance/phase-24-baseline.md`](../performance/phase-24-baseline.md) — surface
  inventory and pre-optimization measurements.
- `docs/performance/phase-24-results.md` — post-optimization results and the §72 target matrix
  (written at Increment 8).

---

## 1. Predecessor verification (Gate A) — Phase 23 / PR #48

Every value below was re-verified **live** against Git and GitHub before any file changed.

| Item | Verified value |
|---|---|
| `git fsck --full` | exit 0 (dangling blob/commit/tree objects only; not corruption) |
| Branch at preflight | `main` |
| HEAD / `origin/main` / merge-base | `13f54a4df54a46abb2928783373383a87ba301d2` |
| Divergence `origin/main...HEAD` | `0 0`; working tree clean |
| PR | [#48 — Phase 23: Complete release hardening and audits](https://github.com/ikrome002-design/servana/pull/48) — `MERGED`, `isDraft: false` |
| Base ← head | `main` ← `phase-23-release-hardening-audit` |
| Final PR head | `ee2dc2b48d50ff156f8034552d9965bbb4186967` |
| Squash-merge commit | `13f54a4df54a46abb2928783373383a87ba301d2` |
| Squash-merge parent (single ⇒ squash) | `d010ec50f412dfe97ee1c412362e16bf263c2a4d` |
| Merged at | `2026-07-27T19:18:34Z` |
| **Final successful CI run** | **`30296509464`** — `conclusion: success`, `headSha` == the final PR head |
| Backend — Pint, Larastan, Pest | SUCCESS |
| Frontend — ESLint, vue-tsc, Vitest, build | SUCCESS |
| Docker — build images | SUCCESS |
| Security — gitleaks | SUCCESS |
| E2E — Playwright | SUCCESS |
| Required checks | exactly five, all SUCCESS |
| Governance comment | id `5095716132`, present **exactly once** (the PR carries exactly 1 comment); names the final head `ee2dc2b…` and CI run `30296509464`; explicitly claims no independent reviewer approval |
| `reviewDecision` | **blank** — PR-specific solo-maintainer exception. **Not** independent approval. |
| Submitted reviews | `0` |
| Branch cleanup | `phase-23-release-hardening-audit` absent locally and on `origin` (`git ls-remote --heads` returns nothing) |

Gate A **PASSED**. The Phase 24 branch was created from `main` at `13f54a4…` only after every value
above matched.

### 1.1 Phase 23 lifecycle reconciliation performed

The repository's own records still described Phase 23 as in flight. Reconciled from the live
evidence above, technical proof left untouched:

| File | Change |
|---|---|
| `docs/PROGRESS.md` | roadmap row 23 `in_progress` → `verified_complete` with full merge facts; row 24 `Not started` → `in_progress`; Phase 23 section header `local_complete pending PR` → `verified_complete`, with a note marking the in-flight narrative below it as historical |
| `docs/CHANGELOG.md` | new Phase 24 `in_progress` entry; the Phase 23 entry re-headed `verified_complete` with merge facts superseding its "No PR yet" line |
| `docs/proof/phase-23.md` | lifecycle line `in_progress` → `verified_complete`; **new §0 "Merge closure"** carrying the merge/CI/governance table. No technical section rewritten. |
| `docs/remediation/register.yaml` | `REM-SCR-002` and `REM-TRACE-001` `local_complete` → `verified_complete`, each gaining `completion_commit: 13f54a4…` and a reviewer field that states the exception is **not** independent approval |
| `docs/traceability/servana-requirements.csv` | `SRV-SEC-001`, `SRV-MERCHANT-PROFILE-001`, `SRV-BRANCH-CALENDAR-001` → `verified_complete`, each evidence cell extended with the PR #48 closure facts |
| `tests/Feature/Traceability/Phase23TraceabilityTest.php` | `23` moved into `P23_VERIFIED_PHASES`; new `P23_IN_FLIGHT_PHASE = '24'`; the honesty guard now protects Phase 24; new case asserts the in-flight phase is never in the verified list |

**Promoted only where the merge condition was satisfied.** Left open and unchanged:
**`REM-PERM-002`** (Merchant-Administrator staff lifecycle-vs-read authority asymmetry — needs a
product-owner permission decision) and **`REM-EXP-001`** (export retention scheduling — owner Phase
21N). Neither is Phase 24 work.

Guard re-run after the edits: `Phase23TraceabilityTest` **15 passed / 20 assertions** (14 → 15
cases; the added case is the in-flight-phase list assertion).

---

## 2. Gate W re-verification — CLOSED

| Path | State |
|---|---|
| `docs/integrations/wallet/` | **absent** |
| `docs/integrations/wallet/gate-w-evidence.md` | **absent** |
| `docs/proof/phase-20d-w.md` | **absent** |

Therefore **20D-W blocked**, **21R-B blocked** (needs 20D-W), **21N blocked** (needs 20D-W).

Phase 24 is nevertheless executable: the live §80.1 chain is
`(17,18,20D-W) → 21N ; 16C + 15A(consent) → 21S ; → 22 → 23 → 24 → 25`, and the launch rule
("20D-W and 21R-B must be complete before **Phase 25 exit**") binds at Phase 25 exit, not at Phase
24 entry. Phase 24 benchmarks shipped code only and creates **no** blocked runtime.

---

## 3. Repository baseline at Phase 24 entry

Measured in-container against the running dev stack, not quoted from prior notes.

| Metric | Value |
|---|---|
| Routes (total) | **301** |
| Routes with a `GET` method | **126** |
| `api/v1` collection-shaped GET endpoints (no path parameter) | **70** |
| Paginated collection call sites (`paginate`/`simplePaginate`/`cursorPaginate`) | **51 across 45 files** |
| Migrations | **118** |
| Model factories | **80** |
| Application **data-cache** call sites (`Cache::`/`cache()`/`->remember(`) | **2 — both in `HealthController` (`__deep_health__` probe put/forget)**; zero elsewhere in `app/` |
| Named rate limiters (Redis-backed, per-user/per-IP) | **11** in `AppServiceProvider::registerRateLimiters()` |
| Pre-existing performance / query-count / N+1 test files | **0** |
| Dev stack services running healthy | 10 (`app`, `nginx`, `postgres`, `redis`, `meilisearch`, `minio`, `minio-init`, `worker`, `file-worker`, `scheduler`, `mailpit`) |
| `DatabaseSeeder` | minimal — `PermissionSeeder` + one test user; **no demo tenant volume exists** |

Two findings from this baseline shape the phase:

1. **There is no application response/data cache to audit.** Increment 5's cache-scope work is
   therefore not "fix the wrong keys" — the correct disposition is a **forward guard** so no future
   phase can introduce an unscoped tenant-sensitive cache key, plus a truthful record that Servana
   currently serves every read from PostgreSQL. Per §2.3 a cache is **not** added merely because a
   route is slow; index and eager-loading fixes are preferred.
2. **No performance dataset and no query-count tests exist.** Both must be built before any
   optimization can be justified, which is why Increment 2 precedes every code change.

---

## 4. Carried-item disposition (each verified live, not trusted from the old note)

| # | Historical item | Source note | Live finding at Phase 24 entry | Disposition |
|---|---|---|---|---|
| 8.1 | OPcache **preload** | Phases 2–5 deferred "preload script generation → Phase 24" | **GAP CONFIRMED.** `docker/php/opcache.ini` sets `enable`, `memory_consumption=128`, `interned_strings_buffer=16`, `max_accelerated_files=20000`, `validate_timestamps=${PHP_OPCACHE_VALIDATE_TIMESTAMPS}` (1 dev / 0 prod) — but **no `opcache.preload` / `opcache.preload_user` directive exists anywhere in the repository**. `docker/php.Dockerfile` lines 3 and 76 *claim* "opcache preload" in the `prod` stage; the stage only runs `composer install --no-dev --optimize-autoloader`. The claim is comment-only. | **Implement** in Increment 7 |
| 8.2 | Per-role authenticated content lazy split | Phase 11: "`roleContent` ≈134 KB gzip bundles all roles' landing and FAQ markdown" | **GAP CONFIRMED.** `resources/spa/src/content/roleContent.ts` statically imports **16** markdown files (8 landing + 8 FAQ) via `?raw` into one `SOURCES` record. Any importer — `RoleLandingScaffold.vue`, `LegalAcknowledgement.vue`, `LegalDocument.vue`, `legalContent.ts` — pulls **all eight roles'** copy. Legal documents (~3 MB) are already lazy per document, so the residue is exactly the landing+FAQ set the note described. | **Optimize** in Increment 6 |
| 8.3 | Queue-estimator recomputation cost | Phase 16B: "availability recomputation per recalc is acceptable for 16B; perf owner Phase 24" | **GAP CONFIRMED (query amplification).** `QueueWaitEstimator::recalculateBranch()` loads all active entries and calls `estimateFor()` per entry. Each `estimateFor()` issues: 1 query for entries ahead + 1 eager-load of services + 1 eligibility query + 1 staff query + **one availability query per staff member**, because `AvailabilityResolver::currentState()` calls `rowsFor($staff)` whenever `$rows` is not preloaded. Cost is therefore **O(E × (4 + S))** for E active entries and S eligible staff — the eligibility/staff/availability set is re-resolved from scratch for every entry even though it is identical across entries of the same service. | **Optimize** in Increment 4 |
| 8.4 | Busy personnel counted in wait estimates | Phase 16C: "busy projection is applied by the availability read; the wait estimator still counts schedule-available personnel — Phase 24 owner" | **GAP CONFIRMED (correctness + performance).** `QueueWaitEstimator::availableCapacity()` depends on `AvailabilityResolver`, whose own docblock states "`busy` is NOT computed here". The authoritative projection `PersonnelStateProjector` (which overlays `Busy` when an `in_progress` `ServiceSession` exists, and is used by the availability read at `StaffAvailabilityController.php:119`) is **not** consulted by the estimator. A personnel member mid-session therefore still counts toward `active_capacity`, inflating capacity and **under-estimating** the advertised wait. | **Correct + optimize** in Increment 4 |
| 8.5 | Performance / p95 proof | Phase 23 handoff: "performance/p95 → Phase 24" | **ABSENT.** No benchmark harness, no dataset, no query-count guard, no p95 record exists. | **Deliver** across Increments 2–3 and 8 |

---

## 5. Section 72 target ownership (recorded before measuring, so nothing is retro-fitted)

| §72 target | Phase 24 disposition |
|---|---|
| API p95 read ≤ 500 ms (indexed) | **Measured here** — primary acceptance criterion |
| API p95 write ≤ 800 ms (excluding external-partner completion) | **Measured here** — primary acceptance criterion |
| Payment-initiation API response ≤ 2 s | **`blocked_external_gate`** — the route is a 20D-W deliverable and does not exist. Not fabricated. |
| Wallet webhook acknowledgement p95 ≤ 250 ms | **`blocked_external_gate`** — 20D-W. Not fabricated. |
| Queue lag p95 ≤ 60 s | Reported **only** for queue/job execution that actually ships, under the documented local topology. Production class-separated topology and Horizon are 21N (blocked). |
| Critical billing/recovery job lag ≤ 30 s | Same rule as above; recovery jobs that belong to 20D-W are blocked. |
| Monthly availability 99.9 % | **Deferred to Phase 25** — operational proof; cannot be established locally. |
| RPO ≤ 15 min | **Deferred to Phase 25** — backup/PITR proof. |
| RTO ≤ 2 h | **Deferred to Phase 25** — restore exercise. |

No Wallet route and no 21N scheduler will be created to manufacture a benchmark.

---

## 6. Increment log

### Increment 1 — predecessor reconciliation and baseline plan (COMPLETE)

Delivered: live Gate A verification (§1); Phase 23 lifecycle reconciliation across six files
(§1.1); Gate W re-verification (§2); repository/runtime baseline (§3); carried-item disposition
proven against current code (§4); §72 ownership matrix (§5); benchmark profile and baseline
documents created under `docs/performance/`.

Verification run in this increment: `Phase23TraceabilityTest` **15 passed / 20 assertions**.

No product code changed. No migration. No commit.

### Increment 2 — deterministic dataset harness (COMPLETE)

`database/seeders/Performance/PerformanceDatasetSeeder.php` builds the dataset from the repository's
own factories (never hand-written SQL), with three documented tiers (`baseline`, `representative`,
`stress`) whose engineering basis is recorded in the benchmark profile. `config/servana.php` gained
`performance.tier`.

Two safety guards, both enforced in the seeder itself:

1. refuses to run outside `local`/`testing`;
2. refuses to run against a database whose name does not contain `perf`/`benchmark`/`test`, so a
   normal developer database can never be polluted. It is deliberately **not** called by
   `DatabaseSeeder`.

**Executed on a disposable PostgreSQL 16.14 database `servana_p24_perf`** (created → 118 migrations
from zero → seeded → measured → **dropped**; verified absent afterwards; the dev database was never
touched). `baseline` tier as actually generated:

| Table | Rows |
|---|---|
| merchants | 3 |
| merchant_branches | 6 |
| service_categories | 6 |
| services | 48 |
| users / merchant_users | 36 / 36 |
| staff_profiles | 36 |
| personnel_availabilities | 216 |
| service_personnel_eligibilities | 144 |
| clients | 240 |
| walk_ins | 72 |
| queue_entries | 72 |
| service_sessions (in_progress) | 18 |
| **TOTAL** | **933** |

**Fixture defect found and fixed during construction (mine, not a product defect):** the first draft
gave `called` queue entries a `called_at` without an `assigned_at`, which PostgreSQL rejected with
`queue_entries_assigned_at_check`. The **schema was right** — a called entry must already be
assigned — so the fixture was corrected to write the full lifecycle prefix. No constraint was
weakened.

### Increment 4 — queue estimator and busy projection (COMPLETE)

Taken before Increment 3 because both carry-forwards were already proven and one is a **correctness**
defect, not merely a speed defect.

#### Bug-fix record — PH24-QUEUE-002 (busy personnel counted as capacity)

| | |
|---|---|
| **Observed problem** | The advertised queue wait is **under-estimated** whenever an eligible personnel member is mid-session. |
| **Evidence** | New test: with 2 eligible personnel and 90 min of work ahead the estimate is 45. Putting one into an `in_progress` `ServiceSession` left it at **45** (expected 90). With **both** busy it stayed at **30** (expected 60). |
| **Affected files** | `app/Domain/Scheduling/Services/QueueWaitEstimator.php` |
| **Root cause** | `availableCapacity()` counted personnel whose **schedule-derived** `AvailabilityResolver::currentState()` was `Available`. That resolver documents "`busy` is NOT computed here (queue/session aggregates — Phases 16B/16C)". The authoritative overlay `PersonnelStateProjector` — already used by the availability read at `StaffAvailabilityController.php:119` — was never consulted by the estimator. |
| **Why this is the root cause** | Injecting the projector's busy set into the denominator changes 45 → 90 and 30 → 60 with no other edit, and completing the session restores 45 automatically. |
| **Correct fix** | `QueueWaitEstimator` now takes `PersonnelStateProjector` and excludes busy personnel from `active_capacity`. `busy` stays **derived, never stored**; the deterministic formula, the "Estimate" label, the branch/service/eligibility/lifecycle constraints and the `max(1, …)` divide-by-zero floor are all unchanged. No state-machine transition was touched. |
| **Tests** | `tests/Feature/Performance/QueueWaitEstimatorQueryBudgetTest.php` — busy exclusion, automatic restoration on completion, and the all-busy case still returning a finite estimate. |
| **Result** | 45 → **90**, 30 → **60**, completion restores **45**. |
| **Remaining risk** | None identified. Existing `QueueWaitEstimatorTest` (5 cases) and the whole `tests/Feature/Scheduling` suite (**176 passed**) confirm no behavioural regression. |

#### Bug-fix record — PH24-QUEUE-001 (availability re-resolved per entry)

| | |
|---|---|
| **Observed problem** | Whole-branch recalculation cost grew with entries **×** eligible personnel. |
| **Evidence (measured, pre-fix)** | `estimateFor()` single entry, 8 eligible: **14 queries**. `recalculateBranch()`: 4 entries **60 queries**, 16 entries **252 queries**. |
| **Root cause** | `recalculateBranch()` called `estimateFor()` per entry; each call re-queried entries-ahead, eligibility and staff, and `AvailabilityResolver::currentState()` issued **one availability query per personnel** because the `$rows` argument it already supports was never passed. |
| **Correct fix** | `AvailabilityResolver::rowsForMany()` (bulk, one query, grouped) and `PersonnelStateProjector::busyAmong()` (bulk, one query) added, so each rule stays with its authoritative owner. `availableCapacity()` is now a **constant 4 queries** regardless of eligible-personnel count; `recalculateBranch()` resolves capacity **once per distinct service** and computes work-ahead by an in-memory prefix scan over the single ordered load. The narrower work-ahead status set (excluding `transferred`) is preserved exactly. |
| **Result** | `estimateFor()` **14 → ≤6**. Capacity resolution is now flat in personnel count (guard: identical query count at 2 vs 12 eligible). |

#### Bug-fix record — PH24-QUEUE-003 (Scout re-indexed per save) — **newly discovered**

| | |
|---|---|
| **Observed problem** | After fixing the above, a statement-level capture showed **3 extra SELECTs per saved entry** (`queue_entries`, `clients`, `services`). |
| **Evidence** | Captured SQL for a 5-entry recalculation: 22 statements, of which 12 were four repetitions of a 3-query relation load. |
| **Root cause** | Phase 22 made `QueueEntry` searchable (`SearchableDocument`). Scout's per-save observer indexes **one model at a time**, and `makeSearchableUsing()` eager-loads that document's index relations **per save**. `recalculateBranch()` saves entries individually, so the relation load repeated per entry. |
| **Correct fix** | Collect the changed entries, persist them inside `QueueEntry::withoutSyncingToSearch()`, then re-index the whole set **once** via `queueMakeSearchable()`. Indexing is **not** disabled and the index ends in exactly the same state — the same documents are pushed, with relations loaded once for the collection. |
| **Result (statement-level, 5 entries / 8 eligible)** | **22 → 13 statements.** Shape is now `6 constant (load + capacity) + 1 update per changed entry + 3 constant (batched re-index)`. |
| **Tests** | New guard asserts the index relation load happens **at most once** per recalculation, plus a marginal-cost guard: 12 extra queue entries may cost at most 12 extra queries. |
| **Remaining risk** | `tests/Feature/Search` re-run in full — **173 passed / 808 assertions** — so tenant/branch/own-scope search isolation is unaffected. |

#### Combined before/after

| Workload (8 eligible personnel) | Before | After |
|---|---|---|
| `estimateFor()` single entry | 14 queries | ≤ 6 |
| `recalculateBranch()`, 5 entries (statement capture) | 22 | **13** |
| `recalculateBranch()`, 4 entries | 60 | 9 + changed |
| `recalculateBranch()`, 16 entries | 252 | 9 + changed |
| Capacity cost vs eligible-personnel count | linear (1 query each) | **constant (4)** |
| Busy personnel in `active_capacity` | counted (wait under-estimated) | **excluded** |

Cost is now `9 + C` statements for `C` changed entries, verified by direct statement capture — down
from roughly `E × (4 + S) + 4E`.

#### Verification run in this increment

| Command | Result |
|---|---|
| `php artisan test tests/Feature/Performance/QueueWaitEstimatorQueryBudgetTest.php` | **6 passed / 11 assertions** |
| `php artisan test tests/Feature/Scheduling` | **176 passed / 474 assertions** |
| `php artisan test tests/Feature/Search` | **173 passed / 808 assertions** |
| `composer pint -- --test` | **PASS, 1684 files** |
| `composer stan` (Larastan level 8) | **No errors, 1303 files** |
| Disposable PG16.14 proof | created, migrated, seeded, measured, **dropped** (verified absent) |

No migration was added. No index was added (none is yet justified by a query plan — that is
Increment 3's evidence to produce). No authorization, tenant scope, masking, state machine or
financial control was altered.

### Increment 3 — per-surface query, index, pagination and N+1 review (COMPLETE)

Full measurements, plans and tables: [`phase-24-results.md`](../performance/phase-24-results.md)
§§1–4. Summary of what was established:

- **Representative dataset built and measured:** 15 360 rows on a disposable PostgreSQL 16.14
  database, dropped and verified absent; developer database untouched.
- **70 parameterless `api/v1` collection endpoints inventoried** and grouped by query pattern
  (scheduling/operations, catalogue/clients/staff, invoicing/money, compensation,
  billing/subscription, platform, audit, messaging/search/identity). ~53 are paginated collections;
  the remaining ~17 are single-resource or summary reads.
- **Five `EXPLAIN (ANALYZE, BUFFERS)` plans** captured on the priority surfaces. Worst execution
  time **3.025 ms** (merchant-wide clients list at deep offset 400). No disk sort anywhere.
- **No index added and no migration.** Every measured filter/sort is index-backed or trivially
  bounded; the one sequential scan is on a 90-row table and is the cheaper plan (documented
  justified exception). Adding a `(branch_id, full_name)` covering index would save ~0.3 ms per read
  while adding write amplification to every client write — not justified by evidence.
- **Pagination is bounded everywhere.** No unbounded collection exists: 23 of 44 paginating files
  use the shared `ApiPagination` contract (default 25 / max 100 / allowlisted sorts / stable
  tiebreaker) and the other 21 apply the same bounds via a duplicated clamp. Recorded, not changed:
  the shared contract *rejects* an over-limit `per_page` with 422 while the duplicated clamp
  *silently clamps* — an API-contract inconsistency, not a performance defect, and out of scope here.
- **No N+1 remains.** Four collection guards assert query-count **equality** across two
  cardinalities. One false positive (seven controllers with no eager load) was investigated and
  dismissed: their Resources serialize only own-row columns.

New guard: `tests/Feature/Performance/CollectionQueryBudgetTest.php` (4 cases).

### Increment 5 — cache-scope audit and forward guard (COMPLETE)

Re-verified live in-container: **0 application data caches**; the only cache/Redis call sites in
`app/` are the 3 in `HealthController` (probe sentinel + Redis ping); 11 named rate limiters, every
one keyed by principal or IP. **0 unsafe or unscoped cache keys.**

No cache was added — §2.3 forbids adding one merely because a route is slow, and the Increment 3
plans show reads are already sub-millisecond at the query level. The deliverable is the forward
guard `tests/Feature/Performance/CacheScopeGuardTest.php` (4 cases): it fails if any new cache call
site appears in `app/` without a declared, reviewed justification; keeps the allowlist from rotting;
records the Plan §69 key dimensions; and asserts no rate limiter degenerates into a global bucket.

### Increment 6 — role content lazy split (COMPLETE)

#### Bug-fix record — PH24-BUNDLE-001

| | |
|---|---|
| **Observed problem** | Every consumer of `content/roleContent.ts` shipped all eight roles' landing + FAQ markdown, including two components that only needed the `LEGAL_DOCS` constant. |
| **Evidence** | 16 static `?raw` imports in one module. Measured payload **484.3 KB raw / 144.7 KB gzip** — corroborating the historical Phase 11 note of "≈134 KB gzip". |
| **Affected files** | `content/roleContent.ts`, `components/layout/RoleLandingScaffold.vue`, `components/legal/LegalAcknowledgement.vue`, `pages/legal/LegalDocument.vue`, `content/legalContent.ts` |
| **Root cause** | Static imports are unconditionally bundled. The module mixed *constants* (`LEGAL_DOCS`, image counts) with *content*, so importing a constant dragged in every role's copy. |
| **Correct fix** | New `content/roleDocuments.ts` loads landing + FAQ lazily via `import.meta.glob` — the identical pattern `content/legalContent.ts` already used for the ~3 MB of legal text. `roleContent.ts` keeps only constants and image helpers and is now markdown-free. `RoleLandingScaffold.vue` loads its role's two documents asynchronously with loading and error states and a stale-response guard. |
| **Result** | `roleContent` chunk **→ 0.2 KB**. A signed-in role downloads **2** documents instead of 16: **54.8 KB raw / 16.5 KB gzip**, a **-88.6 %** gzip reduction. Vite emits 16 independently-fetched chunks. |
| **Invariants** | Markdown still sourced verbatim from `docs/**` via `?raw` — no legal copy, branding, navigation or permission changed. Only *when* content is fetched changed. |
| **Tests** | `content/roleDocuments.spec.ts` (5 cases: constants module markdown-free, lazy loading, every role resolves its own two documents, no role resolves another's, unknown identity rejected) + updated `RoleLandingContent.spec.ts`. |

**Bug found while implementing:** my first glob used `docs/landing page/` (with a space, per a stale
`CLAUDE.md` path note). The real directory is `docs/landing_page/`. Caught by measuring the actual
files rather than trusting the note; fixed before any build.

### Increment 7 — production OPcache preload (COMPLETE)

Full before/after: [`phase-24-results.md`](../performance/phase-24-results.md) §8.

#### Bug-fix record — PH24-OPCACHE-001

| | |
|---|---|
| **Observed problem** | `docker/php.Dockerfile:3,76` and `docker/php/opcache.ini:1` claimed production preload; no `opcache.preload` directive existed. Live proof: `php -i` reported `opcache.preload => no value`. |
| **Correct fix** | New deterministic `docker/php/preload.php`; `opcache.preload = ${PHP_OPCACHE_PRELOAD}` in the shared ini; prod stage points at the script, dev stage sets it empty. `opcache.preload_user` deliberately unset — PHP requires it only when the master runs as root, and both stages drop to non-root `servana`. |
| **Result** | Production image logs `[servana-preload] compiled 2522 files, skipped 0, from 2522 candidates`; `opcache.preload` resolves to the script; `validate_timestamps => Off`; pool reaches `ready to handle connections`; runtime is `uid=1000(servana)` and the file is readable. |

#### Bug-fix record — PH24-OPCACHE-002 (found only by booting the image)

| | |
|---|---|
| **Observed problem** | First implementation preloaded **nothing** while appearing correctly configured. |
| **Evidence** | Prod container log: `PHP Fatal error: Uncaught Error: Undefined constant "STDERR" in /var/www/html/docker/php/preload.php:131`. |
| **Root cause** | `STDERR`/`STDOUT`/`STDIN` exist only in the **CLI** SAPI. Preloading is performed by the php-fpm master, where they are undefined — so the script fataled, yet the pool still started and configuration inspection still looked healthy. |
| **Correct fix** | `error_log()` for all diagnostics, which works in every SAPI. |
| **Regression guard** | `OpcachePreloadConfigurationTest` now rejects CLI-only constructs in the preload script (11 cases total). |

**Cold-start timing is reported as inconclusive, not as an improvement.** Two paired boots gave
13.41 s vs 38.73 s (with preload) and 32.19 s vs 5.79 s (without) — host contention dominates and the
runs contradict each other. A trustworthy delta needs a controlled runner and belongs to Phase 25.
No production deployment was performed.

### Increment 8 — Section 72 proof and global gates (COMPLETE)

Three complete benchmark runs, full matrix in
[`phase-24-results.md`](../performance/phase-24-results.md) §§9–10.

- **Worst read p95 120.31 ms** (target ≤500 ms) · **worst write p95 58.22 ms** (target ≤800 ms) ·
  **error rate 0** across 630 measured requests. p95 values are reported per run plus the
  conservative worst-run figure — never averaged.
- **Harness defect caught before it could fake a pass:** the first attempt measured ~5 ms p95 on four
  surfaces with a 100 % error rate, because one principal exhausted the 120/min `api` rate limiter
  and every later request timed a fast **429**. Fixed by giving each endpoint its own principal, so
  `ThrottleRequests` stays in the measured path. A status precondition now asserts 200/201 before
  any timing, so this can never pass silently.
- Blocked and deferred §72 targets are recorded as blocked/deferred, never as passes. No Plan target
  was lowered; no Wallet route or 21N scheduler was fabricated.

---

## 7. Final quality gates (all run sequentially on the documented host)

| Gate | Result |
|---|---|
| `composer validate --strict` | **`./composer.json` is valid** |
| `composer pint -- --test` | **PASS — 1 689 files** |
| `composer stan` (Larastan level 8) | **No errors — 1 303 files** |
| `php artisan test` (serial) | **2 368 passed / 8 skipped / 0 failed / 14 106 assertions** (1 897 s) |
| `php artisan test --parallel` (4 processes) | **2 368 passed / 8 skipped / 0 failed / 14 106 assertions** (1 893 s) — **identical to serial** |
| Phase 24 performance suites | **25 passed / 1 skipped / 95 assertions** (the skip is the opt-in latency benchmark) |
| `npm run lint` | **0 errors / 138 warnings** — exactly the Phase 23 merged baseline |
| `npm run typecheck` (`vue-tsc`) | **clean** |
| `npm run test` (Vitest) | **551 passed / 101 files** (Phase 23 baseline 544 / 100; +7 across the two new/updated content specs) |
| `npm run build` | **PASS** (16.22 s) |
| `npm run e2e` (full Playwright) | **846 passed / 0 failed** (26.7 min) — equals the Phase 23 baseline, no retries |
| OpenAPI + generated contracts | **247 paths / 296 operations**, unchanged; `api:contract:check` OK; `permission-types --check` up to date |
| Generator determinism | **5/5 artifacts byte-identical across two full passes** (`openapi.json`, `api.ts`, `permissions.ts`, `inventory.yaml`, `role-navigation.yaml`) |
| `composer audit --locked` | **No security vulnerability advisories found** |
| `npm audit --audit-level=high` | **0 vulnerabilities**, exit 0 |
| `npm audit` (full) | **0 vulnerabilities** |
| `gitleaks detect --no-git --redact` | **no leaks found** (28.27 MB scanned) |
| `docker build --target dev` (php) | **exit 0** |
| `docker build --target prod` (php) | **exit 0** |
| `docker build --target prod` (nginx) | **exit 0** |
| Disposable PostgreSQL 16.14 proof | **PASS** — database created, **118 migrations from zero**, seeded, **97 tables**, **0 forbidden Wallet/21N tables**, audit-chain verifier runs clean, database dropped and verified absent, developer database untouched (97 tables) |
| `git diff --check` | **clean** |

**No migration was added by Phase 24** — the migration count is unchanged at 118, confirming the
index review concluded that the existing schema already carries the indexes its query shapes need.

### 7.1 Failed runs and their classification

| Run | Classification | Resolution |
|---|---|---|
| First `representative` seed — `OverflowException` in `ServiceCategoryFactory` | **harness defect (mine)** — the factory draws its name through a global `fake()->unique()` over a six-element pool, capping any process at six categories | Seeder creates the category directly with a deterministic per-branch name; shared factory left untouched (other suites depend on it) |
| Second `representative` seed — slow, and writing into the developer's Meilisearch indexes | **harness defect (mine)** — `scout.prefix` is `servana_{APP_ENV}_`, not database-derived, so seeding polluted the dev search index | Seeding now runs inside `withoutSyncingToSearch`; polluted indexes flushed with `scout:flush`; documented that search benchmarking needs an explicit `scout:import` |
| First benchmark attempt — four surfaces at ~5 ms p95 with a 100 % error rate | **harness defect (mine)** — one principal exhausted the 120/min `api` rate limiter, so later requests timed fast 429s | Per-endpoint principal (throttle stays in the measured path) plus a status precondition asserting 200/201 before any timing |
| First production-image boot — `Undefined constant "STDERR"` | **product defect in new Phase 24 code (PH24-OPCACHE-002)** — CLI-only constant used in a script executed by the FPM master | `error_log()` everywhere, plus a regression guard rejecting CLI-only constructs |
| First full Playwright run — `Timed out waiting 120000ms from config.webServer` | **environment/load flake** — the backend traceability suite was running concurrently on a 4-CPU / 3.77 GiB host | Re-run in isolation with the host idle: **846 passed / 0 failed**. No code, timeout, retry or assertion changed. |

---

## 8. Residual risks

1. **Wall-clock figures are laptop figures.** The §72 p95 results were measured on a 4-CPU /
   ≈3.77 GiB Docker allocation and are used for pass/fail against the documented representative
   profile, not as a production guarantee. Production verification is Phase 25 (§71, §77).
2. **Cold-start benefit of preloading is unquantified.** Preloading demonstrably runs and compiles
   2 522 files, but the boot-time comparison on this host was inconclusive. A controlled runner is
   needed for a trustworthy delta.
3. **Search latency is not benchmarked on the representative dataset.** Seeding deliberately skips
   Scout to avoid polluting the developer index, so the dataset carries no search documents. An
   explicit `scout:import` against a disposable database is required before any search benchmark.
4. **The `per_page` contract inconsistency remains** (shared contract 422s, duplicated clamp
   silently clamps across 21 controllers). No unbounded collection exists either way; harmonising
   the two would change documented response behaviour and is not performance work.
5. **Benchmark cardinality split.** End-to-end latency is measured against one fully-populated
   branch at the representative per-branch shape, while whole-database selectivity is evidenced by
   the `EXPLAIN` plans on the 15 360-row database. Both are documented; neither alone covers both
   concerns.

---

## 9. Exact next human action

Phase 24 is **`local_complete pending PR CI/review/merge`**: one completion commit exists on
`phase-24-performance-optimization` and has been pushed. **No pull request was created** — that
requires product-owner authorization.

The next human action is to open the PR for `phase-24-performance-optimization` → `main`, let the
five required checks run, and record the governance evidence. Phase 25 must not begin before Phase
24 merges. Gate W remains **CLOSED**, so **20D-W**, **21R-B** and **21N** stay blocked, and the
§80.1 launch rule still requires 20D-W and 21R-B to complete before **Phase 25 exit**.

---

## 10. Lifecycle closure — Phase 24 verified complete

*Appended during Phase UI-00 on branch `plan/role-ui-ux-subdomains`, per the convention that the
next branch reconciles the previous phase from live Git/GitHub evidence. Sections 1-9 above are
preserved exactly as written during Phase 24; their "no PR" and "local_complete" wording is
historical.*

| Field | Value |
|---|---|
| Pull request | [#49 - Phase 24: Optimize performance and scalability](https://github.com/ikrome002-design/servana/pull/49) |
| State | `MERGED` (not draft) |
| Base <- head | `main` <- `phase-24-performance-optimization` |
| Final PR head | `46bed762f3e9afadce920ba9376bf6bc6f9b6e5e` |
| Merge commit | `db3827be40194c4a3905679e5d182f014113641b` (== `origin/main`) |
| Squash parent | `13f54a4df54a46abb2928783373383a87ba301d2` (the Phase 23 PR #48 merge) |
| Merged at | `2026-07-28T08:19:47Z` |
| Merged by | `ikrome002-design` |
| Final CI run | `30340905747` - conclusion `success` |
| Required checks | **5 of 5 SUCCESS** on the final head |
| `reviewDecision` | *(blank)* |
| Submitted reviews | `0` |
| Governance | Solo-maintainer governance exception comment recorded on the PR |
| Branch cleanup | Complete - local and remote `phase-24-performance-optimization` deleted |
| Repository integrity | `git fsck --full` exit `0` (dangling unreachable objects only) |

Required checks at `46bed76`:

```text
Backend - Pint, Larastan, Pest              completed  success
Frontend - ESLint, vue-tsc, Vitest, build   completed  success
Docker - build images                       completed  success
Security - gitleaks                          completed  success
E2E - Playwright                             completed  success
```

The required check set was **not** weakened - the same five checks that gated Phases 22, 23 and 24
gated this head. `reviewDecision` is blank under the PR-specific solo-maintainer exception recorded
in the PR governance comment; **no independent reviewer approval exists and none is claimed.**

### Records reconciled

- `docs/PROGRESS.md` - Phase 24 roadmap row and section heading promoted to `verified_complete`.
- `docs/CHANGELOG.md` - Unreleased entry restated with the live merge, CI and governance evidence.
- `docs/traceability/servana-requirements.csv` - `SRV-OPS-003`, `SRV-OPS-004`, `SRV-OPS-005`
  promoted `local_complete -> verified_complete`; **`SRV-PERF-001` promoted
  `deferred_future_phase -> verified_complete`**, which was stale: it deferred to Phase 24 while
  Phase 24 had already delivered exactly that work. Its `automated_tests` now names the real
  suites (`CollectionQueryBudgetTest`, `QueueWaitEstimatorQueryBudgetTest`,
  `ApiLatencyBenchmarkTest`, `CacheScopeGuardTest`, `OpcachePreloadConfigurationTest`).
- `tests/Feature/Traceability/Phase23TraceabilityTest.php` - `24` added to `P23_VERIFIED_PHASES`;
  `P23_IN_FLIGHT_PHASE` advanced from `24` to `UI-00`.
- `docs/remediation/register.yaml` - `meta` reconciliation note. Phase 24 opened **no** remediation
  item, so no `REM-*` status changed merely because CI passed.

### Unchanged by this merge

External **Gate W** was re-checked and remains **CLOSED** (`docs/integrations/wallet/` and
`docs/proof/phase-20d-w.md` are both absent, asserted by the traceability guard). Phases **20D-W**,
**21R-B** and **21N** stay truthfully blocked. `REM-PERM-002`, `REM-EXP-001`, `REM-SMS-002` and
`REM-RE-002` stay open and must close before Phase 25 exit.

**Backend Phase 25 has not started.** It is the only remaining backend phase and requires its own
product-owner authorization.

The corrective UI/UX programme (`UI-00` ... `UI-17`) was adopted separately in Phase UI-00 - see
[`docs/proof/ui-00.md`](ui-00.md).
