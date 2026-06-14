# Servana — Build Progress

Tracks the Plan §27 roadmap. One phase = one reviewed PR. A phase is not
"Done" until its acceptance criteria are demonstrably met and the owner approves.

| Phase | Title | Status | Branch / PR | Proof |
|---|---|---|---|---|
| 1 | Project initialization | ✅ Complete — merged PR #1 | `phase-1-initialization` | [phase-1.md](proof/phase-1.md) |
| 2 | Docker & environment setup | ✅ Complete — merged PR #2 | `phase-2-docker-environment` | [phase-2.md](proof/phase-2.md) |
| 3 | Laravel backend foundation | ✅ Complete — merged PR #3 | `phase-3-laravel-backend-foundation` | [phase-3.md](proof/phase-3.md) |
| 4 | Frontend foundation | ✅ Complete — merged PR #4 | `phase-4-frontend-foundation` | [phase-4.md](proof/phase-4.md) |
| 5 | Authentication (Magic Link + sessions) | 🔄 Complete locally — awaiting CI + approval | `phase-5-authentication` | [phase-5.md](proof/phase-5.md) |
| 6 | Account & tenant model | ⬜ Not started | — | — |
| 7 | Branches, memberships, invitations | ⬜ Not started | — | — |
| 8 | Roles & permissions | ⬜ Not started | — | — |
| 9 | Tenant-scoped data access hardening | ⬜ Not started | — | — |
| 10 | API foundation | ⬜ Not started | — | — |
| 11 | UI layout foundation | ⬜ Not started | — | — |
| 12 | Responsive design pass | ⬜ Not started | — | — |
| 13 | Dark mode | ⬜ Not started | — | — |
| 14 | Accessibility foundation | ⬜ Not started | — | — |
| 15 | HR, catalogue, clients | ⬜ Not started | — | — |
| 16 | Scheduling, queue, sessions, preferred personnel | ⬜ Not started | — | — |
| 17 | Invoicing | ⬜ Not started | — | — |
| 18 | Payments, receipts, refunds, disputes, cash-up, period locks | ⬜ Not started | — | — |
| 19 | Audit logging completion | ⬜ Not started | — | — |
| 20 | Citrus Billing Engine & commissions | ⬜ Not started | — | — |
| 21 | Queues, notifications, scheduled reports | ⬜ Not started | — | — |
| 22 | Search | ⬜ Not started | — | — |
| 23 | Security hardening & threat-model verification | ⬜ Not started | — | — |
| 24 | Performance optimization | ⬜ Not started | — | — |
| 25 | Deployment pipeline & final production readiness | ⬜ Not started | — | — |

## Phase 5 — Authentication (Magic Link + sessions)

- **Branch:** `phase-5-authentication` (based on merged `main`: PR #1–#4).
- **Status:** Complete locally; awaiting CI + owner approval.
- **Proof:** [docs/proof/phase-5.md](proof/phase-5.md).

### Completed
- `magic_login_tokens` table + auth-owned expand of `users` (`ulid`, `status`,
  `last_login_at`; `password` nullable per Plan A3).
- `Domain/Auth/*`: token service (random 64B, SHA-256 at rest, 15-min, atomic
  single-use), `LoginEligibilityService` (seven-check contract), request/consume
  actions, branded `MagicLoginLinkNotification`, interim `AuthEventLogger`.
- Endpoints: `POST /auth/magic-link` (uniform 202), `POST /auth/magic-link/verify`
  (atomic consume → session login + id regeneration; uniform 422
  `invalid_or_expired_token`), `POST /auth/logout` (204), `GET /me` (`auth:sanctum`).
- Laravel Sanctum installed + SPA stateful mode (`statefulApi()`, `sanctum` guard).
- `EnforceIdleTimeout` middleware (60 min, §9.2). All Magic Link limiters wired.
- SPA: real `Login.vue`/`CheckEmail.vue`/`Verify.vue` (stubs deleted); `authStore`
  bootstrap/request/verify/logout; `App.vue` bootstrap on mount.
- MFA: safe `MfaController` placeholder (`mfa_not_enabled`, unrouted) — real TOTP deferred.

### Commands that passed
- `docker compose exec app php artisan test --group=auth` → **28 passed (104 assertions)**.
- `docker compose exec app php artisan test` → **69 passed (230 assertions)**.
- `composer pint -- --test` → PASS · `composer stan` → No errors (level 8).
- `npm run typecheck` → 0 errors · `npm run test` → 38 passed · `npm run build` → built.
- `npm run e2e` → 16 passed (auth 5 + foundation 11).
- `gitleaks --no-git` → no leaks · `npm audit --audit-level=high` → 0 · `composer audit` → 1 documented-ignored.
- Live: `POST /auth/magic-link` → 202; Mailpit delivered branded mail (86-char token); reuse → 422; missing token → 422 validation.

### Commands that failed / limitations
- Live HTTP capture of the clean `200` verify, `429` throttle, and `/me`→logout
  cycle hit nginx 504/timeouts because the Windows Docker host was CPU-bound this
  session (a queued job took ~3 min). Behaviour is proven by the feature suite on
  real PostgreSQL (see proof §5). Two defects found & fixed during verification —
  test-env override (`tests/bootstrap.php`) and worker `mail` queue — see proof §7.

### Skipped (deferred)
```
- Merchant self-registration / tenant model → Phase 6
- Eligibility checks 2 & 4 (membership/role) enforcement → Phase 6 (seam + flag in place; MUST flip)
- Eligibility check 6 (branch assignment) enforcement → Phase 7
- Instant session/token revocation on suspension → Phase 7 (invalidated_at column ready)
- Real MFA (TOTP) → later account-model phase (placeholder only now)
- Roles/permissions → 8 · full API → 10 · role nav → 11 · responsive → 12 · dark → 13 · a11y gate → 14
- Horizon → 21 · uploads → 23 · opcache → 24 · deployment → 25
```

### Known risks
- `AUTH_ENFORCE_TENANCY_ELIGIBILITY=false` until Phase 6 — any *active* user passes
  checks 2/4/6 (correct now, no tenants exist; hard Phase 6 gate).
- Suspension revocation partial (user-level only; session-row deletion is Phase 7).
- Host performance only (not code) limited some live captures.

### Context for Phase 6
- Build merchants/merchant_profiles/merchant_users + onboarding; fill the eligibility
  seam methods and flip the flag; populate `/me` memberships/permissions (6/8).

## Phase 4 — Frontend foundation

- **Branch:** `phase-4-frontend-foundation` → **PR #4 merged into main.**
- **Status:** ✅ Complete. **CI passed: Backend, Frontend, Security, Docker.**
- **Proof:** [docs/proof/phase-4.md](proof/phase-4.md).

### Completed
- 8 layout shells (accessible landmarks, skip link, dark-mode tokens).
- Router: `index.ts` + 9 route modules + `guards.ts` (UX-only stubs).
- 6 Pinia stores: auth, merchant, branch, permission, theme (localStorage), notification.
- `services/apiClient.ts` — axios + CSRF helper + typed `ApiError` mapping Phase 3 envelope.
- `composables/useForm<T>` — dirty, touched, errors, server 422 merge, duplicate-submit guard.
- 9 UI components: SvButton, SvInput, SvSelect, SvTextarea, SvCard, SvModal, SvToast, SvStateBoundary, SvEmptyState.
- `pages/dev/DesignSystemDemo.vue` at `/dev/design-system`.
- Playwright suite: 11 tests (3 breakpoints, no horizontal scroll, theme toggle, axe WCAG AA).
- Vitest: 27 tests (apiClient, useForm, SvStateBoundary).
- Accessibility violations found and fixed: `aria-prohibited-attr` + `color-contrast`.

### Commands that passed
- `npm run typecheck` → 0 errors.
- `npm run test` → 27 passed.
- `npm run build` → built in 2.21s, no errors.
- `npm run e2e` → 11 passed (17s).
- `composer pint --test` → PASS.
- `composer stan` → PASS (Larastan level 8, 0 errors).
- `npm audit --audit-level=high` → 0 vulnerabilities.
- `gitleaks detect --no-git` → no leaks.

### Commands that require Docker
- `php artisan test --parallel` → 40 passed, 1 failed (`DeepHealthTest` needs PostgreSQL + Redis; same known constraint as Phase 3).
- `make up / make fresh / make test` → requires Docker Desktop.

### Skipped (deferred)
```
Skipped:
- Item: Full Magic Link authentication flow
- Reason: Phase 4 stubs auth routes only.
- Correct future phase: Phase 5 (Authentication)
- Risk if forgotten: no login.

Skipped:
- Item: Authenticated /me bootstrap and real auth store data
- Reason: Requires Phase 5 auth flow.
- Correct future phase: Phase 5
- Risk if forgotten: auth store empty; guards remain UX stubs.

Skipped:
- Item: Account and tenant model
- Correct future phase: Phase 6
- Risk if forgotten: no multi-tenancy.

Skipped:
- Item: Tenant middleware / tenant data hardening
- Correct future phase: Phase 6 / Phase 9
- Risk if forgotten: cross-tenant leakage not enforced.

Skipped:
- Item: Branches, memberships, invitations
- Correct future phase: Phase 7
- Risk if forgotten: no org structure.

Skipped:
- Item: Role and permission registry
- Correct future phase: Phase 8
- Risk if forgotten: guards stay as stubs.

Skipped:
- Item: Full /api/v1 route surface and pagination traits
- Correct future phase: Phase 10 (API foundation)
- Risk if forgotten: no API endpoints.

Skipped:
- Item: Final role navigation lists (verbatim from Scope)
- Correct future phase: Phase 11
- Risk if forgotten: nav stubs only.

Skipped:
- Item: Full responsive sweep across all product workflows
- Correct future phase: Phase 12

Skipped:
- Item: Full dark mode across all product workflows
- Correct future phase: Phase 13

Skipped:
- Item: Full accessibility release gate across all critical flows
- Correct future phase: Phase 14

Skipped:
- Item: Horizon, upload scanning, opcache, deployment
- Correct future phase: Phase 21 / Phase 23 / Phase 24 / Phase 25
```

### Known risks
- Button contrast fix deviates from brand assumption of "white on orange"; brand owner should review.
- Router guards are UX stubs only; no backend auth enforcement until Phase 5.
- `DeepHealthTest` requires Docker to pass.

### Context for Phase 5 (Authentication — Magic Link)
- Branch from merged main as `phase-5-authentication`.
- `authStore`, `apiClient`, `primeCsrfCookie()`, `useForm`, `AuthLayout`, and `auth.login`/`auth.verify` routes are ready.
- Phase 5 implements: Magic Link request + "check your email" page, `/auth/verify?token=…` consumption, Sanctum session, `/api/v1/me` bootstrap, all 7 Scope §2.3 checks, session revocation on suspension.

---

## Phase 3 — Laravel backend foundation

- **Branch:** `phase-3-laravel-backend-foundation` (based on merged main: PR #1 + PR #2).
- **Status:** ✅ Complete — merged PR #3.
- **Proof:** [docs/proof/phase-3.md](proof/phase-3.md).

### Completed
- 20 `app/Domain/*` folders (Plan §5.1) with `.gitkeep`.
- `app/Support/Money.php` (integer minor units, currency-checked, integer-only
  formatting) + `CurrencyMismatchException`; `Currency` (KES + USD forward-compat),
  `Severity`, `ErrorCode` enums.
- API error envelope `{ error: { code, message, fields, meta } }` (Plan §11.5)
  via `ApiErrorRenderer` wired in `bootstrap/app.php`; 5xx generic + correlation id.
- `CorrelationIdMiddleware` (global) + `CorrelationId` holder; safe inbound id or ULID.
- Structured logging: `Redaction\Redactor` + Monolog `RedactionProcessor`,
  `CorrelationIdProcessor`, `StructuredLogTap` (tapped on `single`/`stderr`).
- All 7 named rate limiters (Plan §9.3) registered in `AppServiceProvider`.
- `/health` (dependency-free) + `/health/deep` (db/redis/cache required;
  meilisearch/s3 optional; no leaks) via `HealthController`.
- `sentry/sentry-laravel ^4.10` wired (`Integration::handles`), env placeholders only.
- Framework tables (sessions/cache/jobs/job_batches/failed_jobs) confirmed in the
  3 default migrations — **no new migration needed**.
- `routes/api.php` registers `/api/v1` group (no business routes — Phase 10).

### Commands that passed (run in the Docker `app` container, PHP 8.3)
- `make up` → all services healthy; `make fresh` → migrated on PostgreSQL 16.
- `make test` → Pint PASS (49 files), Larastan level 8 OK,
  `php artisan test --parallel` **41 passed (124 assertions), 4 processes**.
- `npm run build` → built with Vite 8 → `public/spa`.
- `gitleaks detect --no-git` → no leaks; `composer audit` → 1 documented-ignored;
  `npm audit --audit-level=high` → 0 vulnerabilities.

### Failed checks
- None outstanding. Two defects found and fixed during verification (Sentry vendor
  sync; Larastan Monolog type narrowing) — see proof §4.

### Skipped (deferred)
```
Skipped:
- Item: Full Magic Link authentication flow
- Reason: Phase 3 only registers the rate-limiter names; the flow is auth scope.
- Correct future phase: Phase 5 (Authentication)
- Risk if forgotten: no login.

Skipped:
- Item: Tenant model + ResolveTenantContext/EnsureBranchScope middleware
- Reason: requires the merchant/branch schema.
- Correct future phase: Phase 6 (tenant model) / Phase 9 (isolation hardening)
- Risk if forgotten: no multi-tenancy enforcement.

Skipped:
- Item: Branches, memberships, invitations
- Correct future phase: Phase 7
- Risk if forgotten: no org structure.

Skipped:
- Item: Role + permission registry / policies
- Correct future phase: Phase 8
- Risk if forgotten: no authorization.

Skipped:
- Item: Full /api/v1 route surface + Idempotency-Key + pagination traits
- Reason: only the group is registered now.
- Correct future phase: Phase 10 (API foundation)
- Risk if forgotten: no API endpoints.

Skipped:
- Item: Frontend foundation (layouts, stores, design-system core)
- Correct future phase: Phase 4
- Risk if forgotten: no SPA app shell.

Skipped:
- Item: Horizon dashboard; upload scanning; opcache preload; deploy/secrets
- Correct future phase: Phase 21 / Phase 23 / Phase 24 / Phase 25 respectively
- Risk if forgotten: covered by their owning phases (carried from Phase 2).
```

### Known risks
- CVE-2026-48019 (Laravel 11 email-rule) still ignored-with-rationale; revisit at
  Laravel 12 / Phase 5.
- Local PHP 8.5 vs pinned 8.3 (CI/Docker enforce 8.3).
- `/health/deep` treats Meilisearch + S3 as optional so the probe stays green in
  CI where those services are absent (intentional, documented in code).

### Context for the next prompt (Phase 4 — Frontend foundation)
- Branch from merged main (after this PR merges) as `phase-4-frontend-foundation`.
- Stack: `make up && make fresh && make test`; SPA dev via `npm run dev` (Vite 8).
- Phase 4 builds: the 8 role layouts, router + stubbed guards, Pinia stores,
  `apiClient.ts`, `ui/` core components (SvButton, inputs, SvCard, SvModal,
  SvToast, SvStateBoundary, SvEmptyState), light+dark theme tokens + head theme
  script (Plan §6, §12). Tests: Vitest (apiClient error mapping, useForm,
  StateBoundary) + Playwright smoke at 3 breakpoints.
- Backend foundation now available to the SPA: `/health`, `/health/deep`, the
  error envelope shape, and `X-Correlation-ID` on every response.

## Phase 2 — Docker & environment setup

- **Branch:** `phase-2-docker-environment` → **PR #2 merged into main.**
- **Status:** Complete. **CI passed: Backend, Frontend, Security, Docker.**
- **Proof:** [docs/proof/phase-2.md](proof/phase-2.md).

### Completed
- `docker/php.Dockerfile` — PHP-FPM 8.3 alpine; ext `pdo_pgsql, redis, intl,
  gd, bcmath, pcntl, zip, opcache`; Composer; non-root `servana` (uid 1000);
  `dev`/`prod` stages; `git safe.directory` set.
- `docker/nginx.Dockerfile` (non-root nginx-unprivileged + Node 20 SPA build
  stage) and `docker/nginx/default.conf`; `docker/php/{php.ini,opcache.ini,
  entrypoint.sh}`.
- `docker-compose.yml` (app, nginx, postgres:16, redis:7, meilisearch, minio
  + bucket-init, mailpit, clamav [profile], worker, scheduler, spa-builder
  [profile]) with healthchecks; `docker-compose.prod.yml`; `.dockerignore`.
- `.env.example` rewritten with documented vars + Docker hostnames (placeholders
  only); `Makefile` with working targets; `brianium/paratest` +
  `league/flysystem-aws-s3-v3` added; CI `docker` build job + parallel tests.
- `/health` moved to a session-less route (bootstrap/app.php `then:`) so the
  liveness probe has no DB dependency.
- `Logo.svg` confirmed present at `public/assets/brand/Logo.svg` (owner-added) —
  **Phase 1 residual risk closed.**

### Commands that passed
- `make up` → all services healthy (app, nginx, postgres, redis, meilisearch,
  minio, mailpit) + worker/scheduler running + minio-init exited 0.
- `make fresh` → migrations on PostgreSQL 16.
- `make test` → Pint PASS, Larastan level 8 OK, `php artisan test --parallel`
  2 passed (4 processes).
- Reachability: Redis `PONG`; Meilisearch `{"status":"available"}`; MinIO bucket
  `servana` created + Laravel `s3` disk round-trip; Mailpit received a test mail;
  app container `id` → `uid=1000(servana)`.
- gitleaks staged scan → no leaks.

### Skipped (deferred)
```
Skipped:
- Item: Laravel Horizon dashboard/config
- Reason: Horizon not installed until the queue phase; a `worker` container
  running `php artisan queue:work` is the compatible placeholder.
- Correct future phase: Phase 21 (Queues, notifications, scheduled reports)
- Risk if forgotten: no queue dashboard/metrics in production.

Skipped:
- Item: ClamAV upload scanning integration
- Reason: no upload pipeline exists yet; ClamAV daemon is provided behind an
  opt-in `clamav` compose profile (memory-heavy, per Plan §27 risk note).
- Correct future phase: Phase 23 (Security hardening) / Phase 19 (uploads)
- Risk if forgotten: uploaded files unscanned.

Skipped:
- Item: /health/deep readiness probe (DB/cache/queue checks)
- Reason: those subsystems mature in Phase 3; Phase 2 ships a dependency-free
  liveness probe only.
- Correct future phase: Phase 3 (Laravel backend foundation)
- Risk if forgotten: orchestrators can't distinguish live-vs-ready.

Skipped:
- Item: opcache preload + production deploy/secrets/registry push
- Reason: preload script generation is a perf optimization; deployment is a
  later phase. Prod Dockerfile/compose exist but are not deployed.
- Correct future phase: Phase 24 (performance) / Phase 25 (deployment)
- Risk if forgotten: suboptimal prod opcache; no live deploy.
```

### Known risks
- Local PHP 8.5 vs pinned 8.3 (CI/Docker enforce 8.3). Unchanged from Phase 1.
- CVE-2026-48019 (Laravel 11 email-rule advisory) still ignored-with-rationale.
- `make` and `gitleaks` were installed on the dev machine via winget this phase.

### Context for the next prompt (Phase 3 — Laravel backend foundation)
- Work continues on branch `phase-2-docker-environment` until merged; Phase 3
  should branch from the latest Phase 2 (or merged main).
- Dev: `make up && make fresh && make test`. App at http://localhost:8080,
  Mailpit 8025, MinIO console 9101.
- Phase 3 implements: `app/Domain/*` skeleton, `Support/Money.php`, enums,
  error-envelope exception renderer (Plan §11.5), correlation-id middleware,
  structured logging + redaction, named rate limiters (§9.3), Sentry, and the
  `/health/deep` readiness probe. Tests: `Unit/MoneyTest`,
  `Feature/Api/ErrorEnvelopeTest`, `Security/LogRedactionTest`.

## Phase 1 — completed work

- Laravel 11.54 (PHP `^8.3`) scaffold; existing `docs/` and `public/assets/`
  preserved untouched.
- Vue 3 + TypeScript + Vite 5 SPA under `resources/spa` (standalone, builds to
  gitignored `public/spa`).
- Tailwind with brand tokens (Plan §12.1) and exact breakpoints `md:768`,
  `lg:1025` (Plan §13); dark-mode class strategy + flash-prevention script.
- Quality tooling: Pest, Larastan level 8 (+ `NoWithoutTenancyOutsidePlatform`,
  `NoRawSqlConcat` rule placeholders for Phase 9), Pint, ESLint flat + vue-tsc,
  gitleaks pre-commit hook + `.gitleaks.toml`.
- `.github/workflows/ci.yml` — PR-stage pipeline with Postgres 16 + Redis 7
  service containers (Plan §26.2).
- `tests/Feature/SmokeTest` — `/health` 200 + app boot; all gates green.

## Open items carried forward

- ~~`Logo.svg` missing~~ — **resolved in Phase 2**: `public/assets/brand/Logo.svg`
  is present (owner-added).
- CI to be confirmed green on the first PR push.
- CVE-2026-48019 (Laravel 11 email-rule advisory) ignored with documented
  rationale — revisit at Laravel 12 upgrade / Phase 5.
