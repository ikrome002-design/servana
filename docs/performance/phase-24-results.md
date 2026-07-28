# Phase 24 — Results: measurements, plans, and the Section 72 matrix

Companion to [`docs/proof/phase-24.md`](../proof/phase-24.md),
[`phase-24-benchmark-profile.md`](./phase-24-benchmark-profile.md) (method) and
[`phase-24-baseline.md`](./phase-24-baseline.md) (what exists / pre-optimization state).

Environment for every number below: Windows 10 host, **4 logical CPUs / 7.90 GiB RAM**, Docker
Desktop **4 CPUs / ≈3.77 GiB**, PHP **8.3.32**, PostgreSQL **16.14**, Redis **7.4.9**, Node
**24.15.0**. Heavy gates were run strictly sequentially (benchmark profile §1.1).

---

## 1. Representative dataset as actually generated

Built by `PerformanceDatasetSeeder` at the `representative` tier on a disposable PostgreSQL 16.14
database (`servana_p24_perf_rep_20260728064707`), 118 migrations from zero, **dropped and verified
absent** afterwards; the developer database (`servana`) was never touched.

| Table | Rows |
|---|---|
| merchants | 6 |
| merchant_branches | 18 |
| service_categories | 18 |
| services | 360 |
| users / merchant_users | 252 / 252 |
| staff_profiles | 252 |
| personnel_availabilities | 1 512 |
| service_personnel_eligibilities | 2 520 |
| clients | **9 000** |
| walk_ins | 540 |
| queue_entries | 540 |
| service_sessions (in_progress) | 90 |
| **TOTAL** | **15 360** |

Seed time 744 s. Two harness limits were found and fixed while building it — both recorded in the
proof file as harness defects, not product defects: `ServiceCategoryFactory`'s global
`fake()->unique()` over a six-name pool (capped the dataset at six branches), and Scout syncing every
seeded row into the **developer's** Meilisearch indexes (`scout.prefix` is `servana_{APP_ENV}_`, not
database-derived).

---

## 2. Query plans — `EXPLAIN (ANALYZE, BUFFERS)` on the representative database

Captured after `ANALYZE`. Bound values are representative of the seeded data.

| # | Query | Plan | Buffers | Exec |
|---|---|---|---|---|
| A | `clients` branch list, default sort `full_name ASC, id DESC`, LIMIT 25 | Index Scan `clients_branch_id_index` → top-N heapsort (500 rows scanned) | 46 | **0.667 ms** |
| B | `clients` merchant-wide (3 branches), **deep offset 400** | Index Scan `clients_branch_id_index` (1 500 rows) → top-N heapsort | 125 | **3.025 ms** |
| C | `queue_entries` active, ordered by `position` | Bitmap Index Scan `queue_entries_branch_id_queued_at_index` → quicksort | 9 | **0.249 ms** |
| D | `service_sessions` branch list | **Seq Scan** (90-row table) → quicksort | 8 | **0.205 ms** |
| E | `staff_profiles` branch list, active only | Bitmap Index Scan `staff_profiles_merchant_id_primary_branch_id_index` | 11 | **0.329 ms** |

### 2.1 Index decisions — **no index added, and no migration**

Every measured filter/sort is either index-backed or trivially bounded:

- **A/B:** the branch predicate is the selective one (500 of 9 000 rows; 1 500 for three branches)
  and is index-backed. The residual `full_name` sort is a **top-N heapsort over the already-narrowed
  branch set**, costing well under a millisecond. A `(branch_id, full_name)` covering index would
  save ~0.3 ms per read while adding write amplification to every client insert and update — not
  justified by the evidence, so it was **not** added.
- **D is a sequential scan and is correct.** The table holds 90 rows; PostgreSQL choosing a seq scan
  over an index on a table that fits in a single heap page is the cheaper plan. Per the phase rules a
  sequential scan is not automatically a defect — this one is a **documented justified exception**.
- Row estimates track actual rows closely enough that no plan is at risk of flipping; no
  `Sort Method: external merge` (disk sort) appeared anywhere.

Phase 24 therefore adds **no migration and no index**. The Plan §13 schema already carries the
indexes its query shapes need.

---

## 3. Pagination review

The contract is centralized in `app/Http/Api/ApiPagination.php`: default page size **25**, hard
maximum **100**, allowlisted `sort` tokens via `Rule::in`, and a stable tiebreaker (`orderByDesc(id)`)
so ordering is deterministic across equal keys and pages.

- **No unbounded collection exists.** Of 44 files that paginate, 23 use `ApiPagination::perPage()`
  and the other 21 apply a locally duplicated `min(max($request->integer('per_page', 25), 1), 100)`
  clamp — the same bounds. A caller cannot force an unbounded load anywhere.
- **Contract inconsistency, recorded not "fixed":** `ApiPagination` **rejects** an over-limit
  `per_page` with 422 while the duplicated clamp **silently clamps** it. That is an API-contract
  difference, not a performance defect, and changing it would alter documented response behaviour on
  21 endpoints. Out of scope for a performance phase; recorded here so it is not lost.
- **Offset pagination is retained.** Deep offset (page 15 of a merchant-wide list) measures 3.0 ms at
  the query level and 54.2 ms end-to-end at p95. Cursor pagination is **not** introduced: there is no
  measured need, and it would change the public API contract.

---

## 4. Collection N+1 results

Guard: `tests/Feature/Performance/CollectionQueryBudgetTest.php`. Each endpoint is measured at two
cardinalities and the query count must be **identical** — an equality assertion, so it holds on any
hardware and survives framework upgrades that shift constant overhead.

| Endpoint | Rows few → many | Query count | Verdict |
|---|---|---|---|
| `GET /api/v1/clients` | 3 → 23 | unchanged | no N+1 |
| `GET /api/v1/queue-entries` | 3 → 18 | unchanged | no N+1 |
| `GET /api/v1/service-sessions` | 3 → 18 | unchanged | no N+1 |
| `GET /api/v1/appointments` | 3 → 18 | unchanged | no N+1 |

**False positive investigated and dismissed:** seven paginated controllers issue no `->with(...)`
eager load. Reading their Resources showed why that is correct — e.g. `PlatformMerchantResource`
serializes only own-row columns (`ulid`, `name`, both status fields, timestamps) plus a
permission-derived `can` map, and touches no relation. No eager load is needed where no relation is
serialized, and the measured query counts confirm it.

---

## 5. Queue estimator — before/after (Increment 4)

| Workload (8 eligible personnel) | Before | After |
|---|---|---|
| `estimateFor()` single entry | 14 queries | **≤ 6** |
| `recalculateBranch()` 4 entries | 60 queries | `9 + C` |
| `recalculateBranch()` 16 entries | 252 queries | `9 + C` |
| `recalculateBranch()` 5 entries (statement capture) | 22 statements | **13** |
| Capacity cost vs eligible-personnel count | linear | **constant (4)** |
| Busy personnel in `active_capacity` | counted (wait **under-estimated**) | **excluded** |
| Estimate, 1 of 2 personnel busy | 45 (wrong) | **90** |
| Estimate, both busy | 30 (wrong) | **60** |

Final statement shape: `6 constant (entry load + capacity) + 1 update per changed entry + 3 constant
(batched search re-index)`.

---

## 6. Cache-scope audit (Increment 5)

| Metric | Value |
|---|---|
| Application **data** cache call sites | **0** |
| Cache/Redis call sites in `app/` | **3**, all in `HealthController` (probe sentinel put/forget + Redis ping) |
| Named rate limiters | **11**, every one keyed by principal or IP — no global bucket |
| Unsafe / unscoped cache keys | **0** |

Servana serves every read from PostgreSQL. There were therefore no mis-scoped keys to correct, and
**no cache was introduced** — §2.3 forbids adding one merely because a route is slow, and §2 above
shows the reads are already sub-millisecond at the query level.

The deliverable is a forward guard (`tests/Feature/Performance/CacheScopeGuardTest.php`) that fails
if any new cache call site appears in `app/` without being declared and justified, records the key
dimensions Plan §69 requires (merchant, branch, principal/own-scope, role/masking, filters, sort,
page, date range, currency, resource version), and asserts no rate limiter degenerates into a single
global bucket.

---

## 7. Frontend bundle — before/after (Increment 6)

Production build (`npm run build`), measured from the emitted chunks.

| | Before | After |
|---|---|---|
| Role landing + FAQ documents in the eager module | **all 16** | **0** |
| `roleContent` chunk | carried all 16 documents | **0.2 KB raw / 0.2 KB gzip** |
| Documents a signed-in role downloads | 16 | **2** |
| Payload for one role (Front Office) | 484.3 KB raw / **144.7 KB gzip** | 54.8 KB raw / **16.5 KB gzip** |
| Reduction for one role | — | **-88.6 % gzip** |

The measured 144.7 KB gzip corroborates the historical Phase 11 note ("`roleContent` ≈134 KB gzip").

After the split Vite emits **16 independently-fetched chunks** — eight FAQ (39.7–74.0 KB raw) and
eight landing (7.7–12.6 KB raw). Front Office loads exactly
`merchant_front_office_faq` (45.3 KB raw / 12.9 KB gzip) and
`merchant_front_office_landing_page_content` (9.5 KB raw / 3.6 KB gzip).

Content is unchanged: the markdown is still sourced verbatim from `docs/**` via `?raw`, exactly as
`content/legalContent.ts` already did for the ~3 MB of legal text. Only *when* it is fetched changed.
No legal copy, branding, navigation or permission was touched.

---

## 8. OPcache / preload (Increment 7)

| | Before | After |
|---|---|---|
| `opcache.enable` | On | On |
| `opcache.validate_timestamps` (prod) | Off | Off |
| `opcache.preload` | **no value** (claimed in comments only) | `/var/www/html/docker/php/preload.php` |
| `opcache.preload_user` | no value | no value (correct — pool is non-root `servana`) |
| Files preloaded at pool start | **0** | **2 522 compiled, 0 skipped, 0 candidates missed** |
| JIT buffer | 0 (disabled) | 0 (unchanged — not enabled without evidence) |

Production image verified by running it: non-root `uid=1000(servana)`, preload file present and
readable, php-fpm reaches `NOTICE: ready to handle connections`, and the pool logs
`[servana-preload] compiled 2522 files, skipped 0, from 2522 candidates` with no fatal or warning.

**Defect found only by booting the image (PH24-OPCACHE-002).** The first implementation wrote its
diagnostics with `fwrite(STDERR, …)`. `STDERR` is defined only for the **CLI** SAPI, so under php-fpm
the preload script fataled with `Undefined constant "STDERR"` and preloaded **nothing** — while the
pool still started, so inspecting configuration alone would have reported success. Fixed by using
`error_log()`, and a regression guard now rejects CLI-only constructs in that script.

### 8.1 Cold-start timing — measured, and honestly inconclusive

| Run | With preload | Without preload |
|---|---|---|
| 1 | 13.41 s | 38.73 s |
| 2 | 32.19 s | 5.79 s |

Container-boot time on this 4-CPU / 3.77 GiB Docker allocation is dominated by host contention and
entrypoint work, and the two runs **contradict each other**. No cold-start improvement is claimed
from this data. What *is* proven is that preloading now happens, compiles 2 522 files
deterministically, and costs nothing observable in correctness. A trustworthy cold/warm latency delta
needs a controlled runner and belongs to Phase 25 production verification (§77, §71).

---

## 9. Section 72 API latency — three complete runs

Harness `tests/Feature/Performance/ApiLatencyBenchmarkTest.php`, opt-in via
`SERVANA_RUN_BENCHMARK=1` so ordinary CI never gates on laptop wall-clock. 30 samples per read
endpoint and 20 per write, each after a 5-sample (3 for writes) warm-up; **warm steady state**.
Every endpoint's status is asserted 200/201 **before** measuring, so no 4xx is ever timed.

All values in milliseconds. Conservative disposition = **worst p95 across the three runs**.

| Surface | Run 1 p95 | Run 2 p95 | Run 3 p95 | **Worst p95** | Target | Errors |
|---|---|---|---|---|---|---|
| `GET /clients` (branch, masked) | 120.31 | 27.44 | 37.19 | **120.31** | ≤500 | 0 |
| `GET /clients?per_page=100` | 116.27 | 86.52 | 34.39 | **116.27** | ≤500 | 0 |
| `GET /clients?page=15` (deep offset) | 54.17 | 26.39 | 23.04 | **54.17** | ≤500 | 0 |
| `GET /queue-entries` | 49.40 | 48.33 | 91.33 | **91.33** | ≤500 | 0 |
| `GET /service-sessions` | 21.86 | 22.64 | 18.80 | **22.64** | ≤500 | 0 |
| `GET /appointments` | 59.38 | 18.26 | 15.07 | **59.38** | ≤500 | 0 |
| `POST /clients` (write) | 29.82 | 32.87 | 58.22 | **58.22** | ≤800 | 0 |

p50 range across runs: reads 12.63–63.36 ms, write 23.88–27.04 ms. p99 worst: read 285.26 ms
(`per_page=100`, run 1), write 61.29 ms. Error rate **0 across all three runs and all seven
surfaces** (630 measured requests).

**Worst read p95 = 120.31 ms against a 500 ms target. Worst write p95 = 58.22 ms against an 800 ms
target.** Both pass with substantial margin.

### 9.1 A harness defect that would have faked a pass

The first benchmark attempt reported ~5 ms p95 for four of seven surfaces — with a 100 % error rate.
A single principal ran warm-up plus samples across all six read endpoints and exhausted the `api`
rate limiter (120 requests/minute, keyed by principal), so every later request measured a fast, tidy
**429**. Timing 429s would have produced excellent-looking numbers for work never performed. Fixed by
giving each endpoint its own Front Office principal — the `ThrottleRequests` middleware stays in the
measured path, because it is genuinely part of request cost. The status precondition described above
was added so this class of error can never pass silently again.

---

## 10. Section 72 disposition matrix

| §72 target | Disposition | Evidence |
|---|---|---|
| API p95 read ≤ 500 ms (indexed) | **PASS** — worst 120.31 ms | §9, three runs |
| API p95 write ≤ 800 ms (excl. external-partner completion) | **PASS** — worst 58.22 ms | §9, three runs |
| Payment-initiation response ≤ 2 s | **blocked_external_gate** — no Wallet runtime exists (Gate W CLOSED) | owner 20D-W |
| Wallet webhook acknowledgement p95 ≤ 250 ms | **blocked_external_gate** — no webhook runtime exists | owner 20D-W |
| Queue lag p95 ≤ 60 s | **not measured — no shipped queue-lag path to measure truthfully**; production class-separated topology and Horizon are Phase 21N, blocked behind Gate W | owner 21N |
| Critical billing/recovery job lag ≤ 30 s | **blocked_external_gate** — the recovery jobs belong to 20D-W | owner 20D-W |
| Monthly availability 99.9 % | **deferred to Phase 25** — operational proof, not establishable locally | Plan §71, §77 |
| RPO ≤ 15 min | **deferred to Phase 25** — backup/PITR proof | Plan §78 |
| RTO ≤ 2 h | **deferred to Phase 25** — restore exercise | Plan §78 |

No blocked or deferred target is recorded as a pass, and no Plan target was lowered. No Wallet route
and no 21N scheduler was fabricated to manufacture a benchmark.
