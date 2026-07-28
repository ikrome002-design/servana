# Phase 24 — Benchmark Profile (environment, dataset tiers, methodology)

Companion to [`docs/proof/phase-24.md`](../proof/phase-24.md). This document defines **how** Phase
24 measures, so every number in `phase-24-baseline.md` and `phase-24-results.md` is reproducible and
comparable. Plan authority: §72 (targets), §75 (testing strategy), §76 (CI), §13 (schema/indexes).

---

## 1. Measurement environment (captured, not assumed)

| Component | Value |
|---|---|
| Host OS | Windows 10 Pro 10.0.19045 |
| Host physical memory | **7.90 GiB** |
| Host logical CPUs | **4** |
| Docker Desktop allocation | **4 CPUs / 4 052 336 640 bytes (≈3.77 GiB)** |
| PHP | **8.3.32** (NTS, `php:8.3-fpm-alpine`) |
| PostgreSQL | **16.14** |
| Redis | **7.4.9** |
| Node | **24.15.0** |
| Meilisearch | dev stack service (Phase 22 search) |
| Object storage | MinIO (S3-compatible) |
| Web tier | Nginx → PHP-FPM |

### 1.1 Environment truth and its consequences

This is a **4-CPU / ≈3.8 GiB-to-Docker developer laptop**, not production hardware. Three rules
follow and are binding for the whole phase:

1. **Absolute latencies measured here are not production latencies.** They are used to (a) prove or
   disprove a bottleneck, (b) compare *before* against *after* on identical hardware, and (c) test
   the §72 targets on the documented representative profile. Any claim that a number is a production
   guarantee is prohibited; production verification belongs to Phase 25 (§77, §71).
2. **Heavy gates never run concurrently.** The Phase 23 session recorded genuine Playwright memory
   pressure on this host, and Phase 20F/post-20F recorded two load-induced flakes caused by running
   a determinism pass and a Playwright web server against concurrent Docker builds. Full Playwright,
   full backend serial, full backend parallel, Docker image builds and benchmark runs are executed
   **strictly sequentially**. Never two Playwright jobs at once.
3. **Every reported figure carries its environment.** A measurement taken under different Docker
   allocation or with other suites running is either re-run or labelled.

---

## 2. Dataset tiers

No authoritative production volume exists for Servana — the product has not launched, and §77
forbids copying production data into development. The tiers below are therefore **engineering
constructs with a stated basis**, explicitly **not** a business forecast.

| Tier | Purpose | Basis |
|---|---|---|
| **baseline** | Correctness and query-count assertions; fast enough for ordinary CI. Deterministic query-count budgets are asserted at this tier, because a budget must hold at *any* size. | Smallest volume that still exercises pagination, multi-branch scope and relationship loading — i.e. enough rows that an N+1 is visibly distinguishable from a constant-query implementation. |
| **representative** | The tier the §72 p95 targets are verified against. | Models a plausible established multi-branch African service SME tenant plus neighbouring tenants, so tenant-scoped indexes are exercised against data they must actually discriminate against. Sized so high-cardinality filtered/sorted paths cannot be satisfied by a trivial in-memory scan. |
| **stress** | Headroom and plan-stability probe: does a query plan flip (index → sequential scan) or a cost become super-linear as volume grows? | A deliberate multiple of *representative*. Used for plan stability and regression detection, **not** for §72 pass/fail. |

Exact per-table row counts for each tier are recorded in
[`phase-24-baseline.md`](./phase-24-baseline.md) once the harness is built (Increment 2), so the
counts published are the counts actually generated rather than counts intended.

### 2.1 Dataset construction rules (binding)

- Built from the repository's **existing 80 factories** and models — no hand-written SQL that could
  drift from the schema, and no bypass of model-level invariants.
- **Deterministic**: a fixed seed produces the same dataset, so two runs are comparable.
- **Tenant-separated and multi-branch**: several merchants, several branches per merchant, so every
  measurement runs against data the tenant scope must genuinely exclude. A benchmark on a
  single-tenant database would silently validate an unscoped query.
- Includes the shipped high-cardinality list/filter/sort paths, queue and service-session data, and
  finance/audit/export-shaped surfaces that actually exist.
- **Creates no blocked runtime**: no Wallet (20D-W) tables/rows, no 21N report or scheduler
  structures. Blocked phases are benchmarked as *absent*, not simulated.
- **No personal and no production data.** Factory-generated values only.
- Runs against a **dedicated disposable PostgreSQL 16 database**, created and dropped by the
  harness. `migrate:fresh` is never run against the normal developer database, and the perf dataset
  never pollutes normal local seed data.

---

## 3. Methodology

### 3.1 What is measured

Two independent classes, because they answer different questions and have different reliability:

| Class | Metric | Determinism | Where it runs |
|---|---|---|---|
| **Structural** | SQL query count per request; presence/absence of N+1; index usage in the `EXPLAIN (ANALYZE, BUFFERS)` plan; cache-key composition; frontend chunk ownership; OPcache/preload configuration | **Fully deterministic** — identical on any hardware | Ordinary CI (safe to gate) |
| **Latency** | p50 / p95 / p99 / min / max / error rate | Hardware-sensitive | Phase 24 proof command on the documented environment; **not** a hardware-sensitive assertion in shared CI |

This split is deliberate and follows §12 of the phase brief: CI enforces invariants that cannot
flake (query-count budgets, index/plan assertions, bundle budgets, cache-key scope, OPcache
configuration), while wall-clock p95 verification is a controlled, documented run. Weakening or
removing any existing required CI job is prohibited.

### 3.2 Warm-up and steady state

`cold start` and `warm steady state` are reported separately and never mixed:

- **Cold**: first request after container/worker start — includes autoload, config resolution,
  connection establishment and (post-Increment 7) preload effects. Reported as its own improvement
  story for the OPcache work.
- **Warm steady state**: measurements taken after a fixed warm-up of discarded requests against the
  same endpoint, so the JIT-less PHP opcode cache, PostgreSQL shared buffers and the connection are
  all primed. **§72 p95 verification uses warm steady state.**

The warm-up request count and the discard rule are recorded with the results, not chosen per run.

### 3.3 Sampling and aggregation

- Each endpoint is sampled repeatedly within a run; sample count and concurrency are recorded with
  every result.
- The full benchmark matrix is executed **at least three complete times** after warm-up.
- **p95 values are never averaged across runs.** Percentiles are not additive, so an arithmetic mean
  of three p95s is not a p95. Results are reported as either (a) the percentile of the **combined
  sample distribution** across runs, or (b) each run separately plus the **conservative
  (worst-run)** figure. Whichever is used is stated explicitly.
- Error rate is reported for every endpoint. A benchmark containing unexplained errors is not a
  passing benchmark.

### 3.4 Timing source

Latency is measured as server-side request duration for the API surface, so client-side and network
noise on a loopback stack is not attributed to application latency. External-partner time is never
counted inside a Servana internal target (§72: "External provider delays are measured separately and
never hidden inside application latency") — and in this phase no external partner is called at all.

### 3.5 External systems

No live Wallet, Refer & Earn, Safaricom/Daraja, SMS or email system is contacted, per §81 rule 21.
Where an implemented path has a fake/fixture (e.g. the fake SMS provider), the fake is used and
labelled. Simulated partner latency is never added to an internal figure.

---

## 4. Invariants no optimization may violate

Every change in this phase preserves, and is re-tested against:

`auth:sanctum` · active-principal and active-membership freshness · tenant scope
(`BelongsToMerchant`) and branch scope (`BelongsToBranch`) · personnel own-scope · policy/permission
enforcement · Form Requests · route classification and middleware (§24.1) · idempotency on financial
POSTs · fresh step-up · audit events and the append-only hash chain · field masking · private-file
authorization and signed-download reauthorization · state machines · financial DB constraints
(unique invoice/receipt numbers, receipt-after-validation trigger, appointment exclusion constraint)
· integer minor-unit money.

Explicitly prohibited as "optimizations": removing a scope or authorization check; replacing a
server-side filter with client-side filtering; unscoped cache entries; hiding a slow operation
behind a longer timeout; weakening or skipping a test; shrinking the dataset to pass a target;
adding an index without a query plan justifying it; editing a shipped migration.

---

## 5. Reproduction

The exact commands for dataset construction, baseline capture, the benchmark matrix and the
disposable-database proof are recorded in [`phase-24-baseline.md`](./phase-24-baseline.md) as they
are built, so a later session re-runs the phase rather than re-deriving it. Raw benchmark logs and
profiler output are **not** committed; compact machine-readable summaries are.
