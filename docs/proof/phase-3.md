# Phase 3 — Laravel Backend Foundation · Proof of Resolution

**Branch:** `phase-3-laravel-backend-foundation` (based on merged main: PR #1 + PR #2)
**Date:** 2026-06-13
**Plan reference:** §5 (backend architecture), §9.3 (rate limiters), §11.5 (error
envelope), §22 (observability), §27 Phase 3, §28. **Guardrails:** CLAUDE.md §6.

---

## 1. Prove the Problem

| What must be built | Requirement | Failure if omitted | Verification |
|---|---|---|---|
| Domain-oriented skeleton | Plan §5.1 | No place for domain code | 20 `app/Domain/*` folders |
| `Money` value object (integer minor units) | CLAUDE.md §6.6, Plan AS-3 | Float money drift in finance | `MoneyTest` (13 cases) |
| Cross-cutting enums | Plan AS-3, §11.5, §22.2 | No typed currency/severity/error codes | enums + tests |
| Structured API error envelope | Plan §11.5 | Inconsistent/ leaky errors | `ErrorEnvelopeTest` |
| Correlation id middleware | Plan §11.5, §22.1 | No request tracing | `CorrelationIdTest` |
| Log redaction | Plan §3 r6, §22.1, CLAUDE.md §6.4/§6.9 | Secrets/tokens in logs | `LogRedactionTest` |
| Named rate limiters | Plan §9.3 | No abuse defense scaffolding | `RateLimitersRegisteredTest` |
| `/health/deep` readiness | Plan §22.1 (deferred from Phase 2) | No dependency readiness signal | `DeepHealthTest` |
| Sentry wiring (placeholders) | Plan §22.1 | No error tracking hook | `SentryConfigTest` |
| Framework tables | Plan Phase 3 | No session/queue/cache storage | migrations confirmed |

---

## 2. What was built

- **Domain skeleton** — 20 `app/Domain/{Auth…Reports}/` folders with `.gitkeep`.
- **`app/Support/Money.php`** — immutable, integer-minor-unit, currency-checked
  arithmetic + comparisons + integer-only formatting; `CurrencyMismatchException`;
  `Currency` enum (KES launch, USD for forward-compat/testing).
- **Enums** — `Currency`, `Severity`, `ErrorCode` (with `httpStatus()` +
  `fromHttpStatus()`).
- **Error envelope** — `app/Exceptions/ApiErrorRenderer.php` wired in
  `bootstrap/app.php`; emits `{ error: { code, message, fields, meta } }` for
  JSON/`api/*` requests; 5xx → generic message + `meta.correlation_id`, never
  internals.
- **Correlation id** — `CorrelationIdMiddleware` (prepended globally) +
  `CorrelationId` holder; safe/length-bounded inbound id or generated ULID;
  echoed on the response header and into the 5xx envelope.
- **Structured logging** — `Redaction\Redactor` (key + email/phone masking),
  `Logging\{RedactionProcessor,CorrelationIdProcessor,StructuredLogTap}`; tap
  added to `single` + `stderr` channels (JSON + redaction + correlation id).
- **Rate limiters** — all seven §9.3 limiters registered in `AppServiceProvider`.
- **Health** — `HealthController::live()` (dependency-free) and `deep()`
  (db/redis/cache required; meilisearch/s3 optional; queue table; no leaks);
  routes registered session-less in `bootstrap/app.php`.
- **Sentry** — `sentry/sentry-laravel ^4.10`; `Integration::handles()` wired;
  env placeholders only (`SENTRY_LARAVEL_DSN=` disables delivery).
- **Framework tables** — confirmed present in the 3 default migrations
  (`users`+`sessions`+`password_reset_tokens`, `cache`+`cache_locks`,
  `jobs`+`job_batches`+`failed_jobs`). No new migration required.
- **`routes/api.php`** — registers the `/api/v1` group (no business routes; Phase 10).

---

## 3. Verification evidence (run inside the Docker `app` container, PHP 8.3)

```text
### make test  (composer pint -- --test && composer stan && php artisan test --parallel)
Pint ......... PASS  49 files
Larastan ..... [OK] No errors           (level 8)
Pest ......... Tests: 41 passed (124 assertions)   Parallel: 4 processes

### targeted (php artisan test)
Tests\Unit\MoneyTest ......................... 13 passed
Tests\Feature\Api\ErrorEnvelopeTest .......... 6 passed
Tests\Feature\Api\CorrelationIdTest .......... 5 passed
Tests\Feature\Api\DeepHealthTest ............. 3 passed
Tests\Feature\Security\LogRedactionTest ...... 3 passed
Tests\Feature\RateLimitersRegisteredTest ..... 7 passed (data provider)
Tests\Feature\SentryConfigTest ............... 2 passed

### npm run build
✓ built in ~53s (Vite 8) → public/spa

### security / audits
gitleaks detect --source . --no-git --redact --config .gitleaks.toml  -> no leaks found (exit 0)
composer audit  -> 1 ignored advisory (CVE-2026-48019, documented); no unhandled high/critical
npm audit --audit-level=high  -> found 0 vulnerabilities
```

### Live endpoint behaviour (in-container)

```text
GET /health        -> 200 {"status":"ok","service":"servana","timestamp":...}
GET /health/deep   -> 200 {"status":"ok","checks":{database:ok,redis:ok,cache:ok,
                            queue:ok,meilisearch:ok,s3:ok}}   (all deps up in dev)
GET /api/v1/x      -> 404 {"error":{"code":"not_found",...}}  (JSON envelope)
5xx               -> {"error":{"code":"internal_error","message":"An unexpected
                            error occurred.","meta":{"correlation_id":"…"}}}
```

---

## 4. Defects found & fixed during verification (Bug Fix Protocol)

**Defect A — app fatal-erred on boot after Sentry reference before sync.**
- Evidence: nginx `unhealthy`; `bootstrap/app.php` references
  `Sentry\Laravel\Integration` not yet present in the container vendor volume.
- Root cause: composer lock updated on host; container vendor volume stale.
- Fix: `docker compose exec app composer install` (and the final image rebuild
  bakes it for fresh installs).

**Defect B — Larastan: `getHandlers()/pushProcessor()` undefined on
`Psr\Log\LoggerInterface`.**
- Root cause: `Illuminate\Log\Logger::getLogger()` is typed as the PSR
  interface; the Monolog-only methods are not on it.
- Fix: runtime-safe `instanceof Monolog\Logger` narrowing in `StructuredLogTap`
  (a real guard, not a suppression).

---

## 5. Work skipped / deferred (see docs/PROGRESS.md for the structured list)

Full Magic Link auth → Phase 5 · tenant model/middleware → Phase 6/9 · branches
→ Phase 7 · roles/permissions registry → Phase 8 · full API route surface →
Phase 10 · frontend foundation → Phase 4 · Horizon dashboard → Phase 21 · upload
scanning → Phase 23 · opcache preload → Phase 24 · deploy/secrets → Phase 25.

---

## 6. Residual risks

1. CVE-2026-48019 (Laravel 11 email-rule) still ignored-with-rationale; no
   Laravel 11 fix. Revisit at Laravel 12 / Phase 5.
2. Local PHP 8.5 vs pinned 8.3 — CI and Docker enforce 8.3.
3. `/health/deep` treats Meilisearch + S3 as optional (degraded, still 200) so
   the readiness probe stays green in CI where those services are absent; this
   is intentional and documented in `HealthController`.
4. Sentry delivery is unverifiable locally (empty DSN by design); real delivery
   requires a staging/production DSN.
