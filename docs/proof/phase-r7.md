# Phase R7 — Production Probes, CI Isolation, Environment Parity — Proof of Resolution

**Requirement:** REM-OPS-001 (C1, PRE_FEATURE) · Plan §79 R7 · §22 (probes),
§24 (health/API security), §26 (environment/infra), §75–§77 (testing/CI/prod),
§8 (ADR-009), §85 (traceability); Correction 7.
**Branch:** `phase-r7-production-probes-ci-parity` · **Base:** merged `main`
`57ae8db` (PR #18, R6). **Date:** 2026-06-22.

No private tenant data, secret, host, bucket, DSN or exception detail appears in
this document.

---

## 1. Branch and merged-R6 base

```
current branch : phase-r7-production-probes-ci-parity
base           : 57ae8db  (PR #18 squash merge to main — REM-SESS-001 verified_complete)
PR #18         : MERGED 2026-06-22T19:50:09Z; CI Backend/Frontend/Docker/Security = SUCCESS
                 reviewDecision blank (solo-maintainer governance exception)
start state    : on clean main @ 57ae8db (== PR #18 mergeCommit), origin/main...HEAD = 0 0
```

R6 documentation corrected on this branch (see §9): REM-SESS-001 →
`verified_complete` (PR #18, `57ae8db`); REM-DOC-001 → `verified_complete`
(PR #12, `c58b64a`).

---

## 2. Proven before-state (as-built, inspected)

| Area | Before R7 | Gap |
|---|---|---|
| `/health` (liveness) | dependency-free 200, no secrets | OK — preserved |
| `/health/deep` (readiness) | REQUIRED = database, redis, cache only | **queue/meilisearch/s3 treated as optional → degraded 200** even when a managed prod dep fails |
| S3 in readiness | optional (degrade) | **S3 is a managed production service (prod compose) but never fails readiness** |
| Probe timeouts | only Meilisearch bounded (2s) | Redis/S3/PG unbounded → a hung dep could stall the probe |
| Prod healthcheck wiring | nginx → `/health` (liveness) | traffic eligibility didn't reflect dependency health |
| Test cache/session/queue | `array`/`array`/`sync` (in-memory per process) | already isolated, but **no Redis/cache prefix namespace** for direct Redis usage / CI shared Redis |
| Redis prefix in tests | `APP_NAME`-derived, shared | **no per-run/per-process namespace** |
| Node version | Docker `node:20`, CI `node 20` | **not pinned in package metadata** (no `engines`, no `.nvmrc`) |
| PHP / Composer | 8.3 / composer 2 across Docker+CI | aligned (no gap) |
| Brand contrast | CTA `bg-primary text-brand-deep` committed | **no ADR-009, no contrast test** |

Production dependency derivation (`docker-compose.prod.yml`): *"PostgreSQL, Redis
and S3 are expected to be MANAGED services."* → REQUIRED = database, redis, cache
(Redis-backed), s3. Meilisearch is absent from prod and unused until Phase 22 →
OPTIONAL. Mailpit is local-only → never a dependency.

---

## 3. Liveness and readiness (after)

`app/Http/Controllers/HealthController.php` is now config-driven
(`config/servana.php` → `health`):

```
required_dependencies : [database, redis, cache, s3]
optional_dependencies : [queue, meilisearch]
require_configured    : true only in production (env-derived)
probe_timeout         : 2s (HTTP probes)
```

- **Liveness `GET /health`** — returns `{status, service, timestamp}` 200, never
  touches a dependency, never reports `checks`. `LivenessProbeTest` proves it
  stays 200 with the DB broken and Redis throwing, and leaks no host/secret.
- **Readiness `GET /health/deep`** — 200 only when every required dependency is
  healthy; **503** otherwise. Required `error` always fails; required `skipped`
  (unconfigured) passes only when `require_configured` is off (non-production).
  Optional `error` degrades (200). Overall `status ∈ {ok, degraded, unhealthy}`.

Bounded timeouts: `probe_timeout` (Meilisearch + S3 HTTP), Redis connection
`timeout=2`, S3 `http.connect_timeout=2`, PostgreSQL `PGCONNECT_TIMEOUT=5`
(libpq; the Laravel pgsql DSN builder has no `connect_timeout` key — set at the
env level in `docker-compose.prod.yml`).

Healthcheck wiring (`docker-compose.prod.yml`): nginx → `/health/deep`
(readiness, traffic eligibility); the `app`/`worker`/`scheduler` containers keep
`php -v` (process liveness).

---

## 4. Readiness failure proof (503)

`ReadinessDependencyFailureTest` + `ReadinessProbeTest` + `LivenessProbeTest` +
`ProductionReadinessConfigurationTest` (18 tests, all green):

```
liveness 200 with DB down + Redis throwing ............ PASS
required database failure  → 503 unhealthy ............ PASS
required redis failure     → 503 unhealthy ............ PASS
required cache failure     → 503 unhealthy ............ PASS
required s3 (configured) failure → 503 unhealthy ...... PASS
optional meilisearch failure → 200 degraded ........... PASS
production strictness: unconfigured s3 → 503 .......... PASS
non-production: unconfigured s3 → 200 (skipped) ....... PASS
required set = [database,redis,cache,s3]; not mailpit/meili  PASS
probe timeouts bounded (≤ 5s) ......................... PASS
```

---

## 5. Health response redaction

`HealthResponseRedactionTest` — a failing probe never echoes a DB password, SQL
state, `pgsql:` DSN, Redis host, S3 bucket/endpoint, or AWS key; only safe
dependency names + statuses:

```
DB failure  : body excludes sentinel password, 'secret','password','SQLSTATE','pgsql:'
Redis failure: body excludes the host/'refused'; redis.status = 'error'
S3 failure  : body excludes bucket, endpoint, AKIA key, 'AccessDenied'
```

---

## 6. Redis / cache / rate-limit isolation

Strategy: cache/session/queue already use `array`/`array`/`sync` in tests
(in-memory, per process — no shared backend, no `FLUSHDB`, and each test boots a
fresh app so counters reset). `tests/bootstrap.php` additionally assigns a unique
**Redis + cache namespace per run and per parallel process**:

```
namespace = servana_test_{runId}_{token}_
  runId  = CI_TEST_RUN_ID | GITHUB_RUN_ID | GITHUB_RUN_ATTEMPT | 'local'
  token  = TEST_TOKEN | LARAVEL_PARALLEL_TESTING_TOKEN | getmypid()
→ REDIS_PREFIX, CACHE_PREFIX, SERVANA_TEST_NAMESPACE
```

Proven (all green):
- `RedisPrefixIsolationTest` — two prefixes hold the SAME logical key without
  collision (raw phpredis `OPT_PREFIX`); deleting one namespace's key leaves the
  other intact (never FLUSHDB). The configured prefix equals the per-process
  namespace.
- `CacheIsolationTest` — array store in tests (no shared backend, scoped flush);
  two prefixed Redis cache repositories don't collide.
- `RateLimitIsolationTest` — counters don't bleed across keys or across tests
  (fresh app per test); the limiter is keyed through the namespaced cache prefix.
- `ParallelTestIsolationTest` — distinct tokens derive distinct namespaces; the
  active parallel token is reflected in the namespace.

---

## 7. Parallel & browser stability

```
full backend serial ........... 443 passed, 4 skipped (2016 assertions)
3× parallel backend ........... run 1 PASS · run 2 PASS · run 3 PASS   (see §12)
```

Browser (Playwright) run in isolation (no concurrent Docker builds / parallel
suite): **30 passed**. The earlier intermittent failures observed under R6 were
resource-starvation flakes (different tests failed on different concurrent runs);
a clean isolated run is green. R7 changes no product UI; the release-wide a11y/e2e
sweep remains owned by Phase 23.

---

## 8. PHP / Node / Composer parity

Docker is the canonical local runtime; no host runtime is canonical.
`RuntimeParityTest` (machine-checkable, fails on drift):

```
PHP 8.3      : php.Dockerfile (php:8.3-fpm-alpine) · composer.json require ^8.3 +
               platform 8.3.31 · CI php-version 8.3
Node 20      : nginx.Dockerfile (node:20-alpine) · docker-compose.yml spa-builder
               (node:20-alpine) · CI node-version 20 · package.json engines.node
               >=20 <21 · .nvmrc 20
Composer 2   : php.Dockerfile (composer:2) · CI tools composer:v2
```

`@types/node` is `^22` (dev-time TYPES only, ahead of the Node 20 runtime) — not a
runtime parity defect; left unchanged to avoid an unrelated upgrade.

---

## 9. R6 documentation correction (actual GitHub evidence)

```
PR #18  state MERGED · merge 57ae8db · merged 2026-06-22T19:50:09Z
        CI Backend SUCCESS · Frontend SUCCESS · Docker SUCCESS · Security SUCCESS
        reviewDecision '' (blank) → solo-maintainer governance exception (NOT a review)
→ REM-SESS-001 status verified_complete in register/PROGRESS/CHANGELOG/traceability;
  stale 'local_complete / pending push / pending CI / pending review' removed.
PR #12  (Phase V) MERGED · merge c58b64a · CI all SUCCESS → REM-DOC-001 verified_complete.
```

---

## 10. ADR-009 — brand contrast

`docs/architecture/adr/0009-brand-contrast-tokens.md` records measured ratios
from the committed tokens; `tests/Unit/BrandContrastTokenTest.php` recomputes and
guards them:

```
brand-deep #4a2208 on primary #f97316 (CTA)      ≈ 4.92:1  AA PASS
white      #ffffff on primary #f97316 (rejected) ≈ 2.80:1  AA FAIL → reason for dark CTA text
white      #ffffff on error   #dc2626 (destruct) ≈ 4.83:1  AA PASS
accent     #007c78 on surface #ffffff (links)    ≈ 5.06:1  AA PASS
text       #1f2933 on bg      #f9fafb (body)      ≈ 14.8:1  AA PASS
```

---

## 11. R6 controls preserved

`RevocationMiddlewareOrderTest`, `MidSessionSuspensionTest`,
`AuthorizationFreshnessTest`, `SessionRevocationTest`, `MfaMiddlewareOrderTest`,
`CrossTenantAccessTest`, `CrossBranchAccessTest` — all green after the R7
middleware/cache/session/CI changes. Ordering (auth → active-principal → MFA →
tenant), DB-backed freshness, revocation, and the 404/403 posture are unchanged.

---

## 12. Full quality-suite results

```
composer validate --strict ... PASS
composer pint --test ......... PASS
composer stan (Larastan L8) .. PASS
php artisan test (serial) .... PASS — 443 passed, 4 skipped (2016 assertions)
php artisan test --parallel ×3 PASS — runs 1/2/3 all exit 0 (stability gate)
audit:verify-chain ........... PASS — no chain to verify on the empty dev table
composer audit --locked ...... PASS — no advisories
npm run lint ................. PASS — 0 errors
npm run typecheck ............ PASS
npm run test (vitest) ........ PASS
npm run build ................ PASS
npm audit --audit-level=high . PASS — 0 vulnerabilities
gitleaks (--no-git --redact) . PASS — no leaks
docker build php --target dev  PASS
docker build nginx --target prod PASS
npm run e2e .................. PASS — 30 passed (isolated run; no concurrent builds)
```

### Initial failures and reruns (honest record)
- `RedisPrefixIsolationTest` first cut changed the prefix via `config()` +
  `Redis::purge()`; the `RedisManager` caches its config so the connection did not
  reconnect with the new prefix → both writes hit one key. Rewrote to raw phpredis
  `OPT_PREFIX` clients; rerun green. A passing rerun does not erase the failure.

---

## 13. Work skipped and owning phase

```
Full OpenAPI / route contract                 -> Phase 10
File/media pipeline                           -> Phase 10F
Release-wide responsive/dark/a11y redesign    -> Phase 23
Deployment, backups, alerting, restore        -> Phase 25
Horizon/queue observability                   -> Phase 21N/25
Feature-domain business routes/tables         -> owning feature phases
```

---

## 14. Residual risks

- The S3 readiness probe does a live round-trip only when a custom endpoint is set
  (MinIO/dev); for managed AWS S3 (no endpoint) it reports configured-disk
  readiness without a network call — acceptable for R7; a deeper live check can
  land with file storage (Phase 10F).
- `PGCONNECT_TIMEOUT` bounds PG connect at the libpq/env level rather than in the
  Laravel DSN; documented in ADR/compose.
- e2e remains environmentally flaky on Windows (R6-documented); CI on Linux is the
  source of truth.

---

## 15. REM-OPS-001 status & pre-feature gate

`REM-OPS-001` = `local_complete`. Liveness is dependency-free; readiness requires
every configured production dependency and 503s on failure with bounded timeouts
and redacted output; Redis/cache/rate-limit namespaces are isolated per run and
process with no global flush; three consecutive parallel runs pass; PHP/Node/
Composer parity is automated; ADR-009 exists with measured AA ratios; R6 controls
pass. Promotion to `verified_complete` requires the R7 PR merged, green CI, and
review or a truthful PR-specific governance exception — not asserted here.

The §5.4 pre-feature gate remains **BLOCKED_PENDING_R7_MERGE**
(`docs/remediation/pre-feature-completion-report.md`). Phase 10 must not start
before the dedicated post-merge gate-closure update is merged.

## Solo-Maintainer Review Exception

An independent second reviewer was unavailable because the repository currently
has one eligible maintainer. The product owner authorized a PR-specific
governance exception rather than fabricating approval.

Evidence:

- PR: #19
- CI/Backend: passed
- CI/Frontend: passed
- CI/Security: passed
- CI/Docker: passed
- GitHub reviewDecision: intentionally blank
- Exception record:
  docs/governance/solo-maintainer-review-exception-pr-19.md

This exception applies only to PR #19.
