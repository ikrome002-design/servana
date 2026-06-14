# Changelog

All notable changes to Servana by Citrus. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); phases map to Plan §27.

## [Unreleased]

### Phase 6 — Account & tenant model (`phase-6-account-tenant-model`)

#### Added
- Merchant Administrator self-registration (Scope §3.1/§3.2): public
  `POST /api/v1/merchant-registration/self-register` (`registration` limiter,
  uniform 202 — no enumeration) → `RegisterMerchant` action creates user +
  merchant (`pending_setup`) + shell profile + `merchant_admin`/`active`
  membership + status-history row in one transaction, then emails the owner a
  Magic Link. No Super Admin approval, no KYC — neither route nor UI exists.
- First-time setup (Scope §3.2 steps 1–7): `GET`/`POST`
  `/api/v1/merchant-registration/first-time-setup` (gated by
  `EnsureFirstTimeSetupAccess`: pending_setup + merchant_admin). Transactional
  `CompleteFirstTimeSetup` action — tier, profile, ≥1 branch, initial
  Branch+HR invited memberships (auto-selected to the single branch), welcome
  emails, then flips merchant → `active` + records status history.
- Tenant context (Plan §8.1): `TenantContext` (request-scoped),
  `TenantContextResolver`, `ResolveTenantContext` middleware, `EnsureMerchantActive`
  + `EnsureFirstTimeSetupAccess` gates, `TenantAccessException` (envelope codes
  `no_tenant_context` / `merchant_suspended` / `pending_setup_only` /
  `setup_already_completed`). Merchant dashboard shell `GET /api/v1/merchant/dashboard`.
- Schema (forward-only): `merchants`, `merchant_profiles`, `merchant_users`,
  `merchant_status_histories`, minimal `merchant_branches` (Phase 6 seam — full
  branch lifecycle is Phase 7); `is_platform_staff` added to `users`. Enum-backed
  statuses + DB CHECK constraints (MerchantStatus, MerchantUserStatus,
  MerchantUserRole, ServiceFeeTier, BranchStatus).
- `/api/v1/me` bootstrap now returns `{ user, merchant, membership, memberships,
  permissions, setup }` from the resolved tenant context (Plan §6.2).
- SPA: `RegisterMerchant.vue`, 4-step `FirstTimeSetup.vue` wizard, merchant
  `Dashboard.vue` shell; `onboardingStore`; `authStore`/`merchantStore` rewired
  to the new bootstrap; global `router.beforeEach` awaits session bootstrap
  before guards; `requiresPendingSetup` guard + pending→wizard routing.
- `StaffWelcomeNotification` (safe, tokenless welcome for invited Branch/HR;
  full invitation-accept flow is the Phase 7 seam).
- Tests: 40 backend onboarding/tenancy/security tests; Vitest +13 (RegisterMerchant,
  FirstTimeSetup, merchantStore, authStore tenant bootstrap); Playwright +4
  (`merchant-onboarding` incl. axe on registration + wizard).

#### Changed
- `LoginEligibilityService`: Scope §2.3 checks 2 & 4 now enforced
  (`User::hasTenantAccess` — active membership or platform staff);
  `AUTH_ENFORCE_TENANCY_ELIGIBILITY` now defaults `true`. Check 6 (branch
  assignment) stays deferred to Phase 7 (always passes for now).
- `AuthenticatedUserResource` reshaped to the tenant-aware bootstrap; Magic Link
  verify populates tenant context before responding.

### Phase 5 — Authentication (Magic Link + sessions) (`phase-5-authentication`)

#### Added
- Magic Link authentication (Plan §9.1): `POST /api/v1/auth/magic-link` (uniform
  202, no enumeration), `POST /api/v1/auth/magic-link/verify` (atomic single-use
  consume → Sanctum session login with session-id regeneration; uniform 422
  `invalid_or_expired_token`), `POST /api/v1/auth/logout` (204), `GET /api/v1/me`.
- `Domain/Auth/*`: `MagicLoginToken` model; `MagicLinkTokenService` (64 random
  bytes → base64url, SHA-256 at rest, 15-min expiry, atomic conditional-UPDATE
  single-use); `LoginEligibilityService` (seven-check contract, Scope §2.3);
  `RequestMagicLink`/`ConsumeMagicLink` actions; `MagicLoginLinkNotification`
  (branded, `mail` queue); `AuthEventLogger` (interim redacted audit until Phase 8);
  `InvalidMagicLinkException`; `AuthEvent` enum; `EligibilityResult` VO.
- `magic_login_tokens` table; auth-owned expand of `users` (`ulid`, `status`,
  `last_login_at`; `password` made nullable — Plan A3 "no password column").
- Laravel Sanctum `^4.3` installed; SPA stateful mode (`statefulApi()` + `sanctum`
  guard); `EnforceIdleTimeout` middleware (60-min sliding, Plan §9.2).
- SPA auth pages `Login.vue`/`CheckEmail.vue`/`Verify.vue` (replace Phase 4 stubs);
  `authStore` real `bootstrap/requestMagicLink/verifyMagicLink/logout`; bootstrap
  on app mount; typed `apiError` on `AxiosError`.
- `config/servana.php` (frontend URL, idle timeout, tenancy-eligibility flag);
  `sanctum` guard in `config/auth.php`.
- Tests: 8 backend auth/security suites (28 tests), Vitest authStore/Login/Verify
  (11 tests), Playwright `auth-magic-link` (5 tests incl. axe on all auth pages).

#### Fixed
- Test environment now overrides docker-injected `$_SERVER` vars via
  `tests/bootstrap.php` (previously the suite ran on the shared redis cache,
  causing rate-limiter bleed and non-deterministic sessions).
- Dev `worker` now consumes the `mail` queue (`queue:work --queue=mail,default`)
  so Magic Link emails are actually delivered.

#### Security
- Raw tokens never stored (only SHA-256), never logged (masked-email audit only),
  never returned in API responses. Single-use, 15-min expiry, uniform 202/422 to
  prevent account enumeration; named Magic Link rate limiters → structured 429.

#### Deferred
- Eligibility checks 2/4 (membership/role) → Phase 6; check 6 (branch) → Phase 7
  (seam methods + `AUTH_ENFORCE_TENANCY_ELIGIBILITY` flag in place). Instant
  suspension revocation of sessions → Phase 7. Real MFA/TOTP → later phase
  (safe `MfaController` placeholder only). No Phase 6 tenant schema created.

---

### Phase 4 — Frontend foundation (`phase-4-frontend-foundation`)

#### Added
- 8 role-based layout shells: `AuthLayout`, `PlatformAdminLayout`, `MerchantLayout`,
  `BranchLayout`, `FrontOfficeLayout`, `PersonnelLayout`, `FinanceLayout`, `AuditLayout`.
  Each includes skip link, accessible landmarks (`header`, `nav`, `main`), `.dark`-compatible
  tokens (Plan §6.1, §15.9).
- Router foundation: `router/index.ts` integrating 9 route modules, `router/guards.ts`
  with UX-only stubs for `requiresAuth`, `requiresRole`, `requiresPermission`,
  `requiresActiveMerchant` (Plan §6.2).
- 6 typed Pinia stores: `authStore`, `merchantStore`, `branchStore`, `permissionStore`,
  `themeStore` (persists to `localStorage`), `notificationStore`.
- `services/apiClient.ts`: single axios instance (`baseURL=/api/v1`, `withCredentials`,
  CSRF priming helper), response interceptor mapping Phase 3 error envelope to typed
  `ApiError { code, message, fields, meta }` (Plan §6.3, §11.5).
- `composables/useForm<T>`: typed values, dirty, touched, errors, `submitting`,
  `reset()`, `setFieldError()`, `mergeServerErrors(ApiError)`, `handleSubmit()` with
  duplicate-submit prevention (Plan §16).
- Types: `types/api.ts` (`ApiError`, `Paginated<T>`), `types/models.ts`, `types/enums.ts`.
- Utils: `utils/money.ts` (minor-unit formatting, Africa/Nairobi), `utils/dates.ts`.
- 9 core UI components under `components/ui/` (all light+dark, all states, axe-verified):
  `SvButton` (4 variants, loading, disabled, 44px touch, `text-brand-deep` on orange for
  WCAG AA 4.78:1), `SvInput`, `SvSelect`, `SvTextarea` (labels, `aria-invalid`,
  `aria-describedby`, `aria-required`), `SvCard`, `SvModal` (focus trap, Esc, `aria-modal`),
  `SvToast` (`role="status"`, 5s auto-dismiss, pause on hover), `SvStateBoundary`
  (loading/empty/error/success), `SvEmptyState`.
- `pages/dev/DesignSystemDemo.vue` routed at `/dev/design-system` — renders all Phase 4
  components in both themes with all required states.
- `playwright.config.ts`; Playwright smoke suite (11 tests) covering 3 breakpoints,
  no horizontal scroll, component rendering, theme toggle, modal keyboard, axe WCAG AA scan.
- Vitest tests for `apiClient` error mapping (10), `useForm` (8), `SvStateBoundary` (8).
- `npm` packages added: `axios`, `@playwright/test`, `@axe-core/playwright`.

#### Fixed
- Primary button and CTA button contrast: `text-white` on `#f97316` (2.8:1) replaced with
  `text-brand-deep` (`#4A2208`) on `#f97316` (4.78:1) to meet WCAG AA (Plan §15.3).
- Loading skeleton: added `role="status"` to permit `aria-label` on the loading div
  (axe `aria-prohibited-attr` violation resolved).

---

### Phase 3 — Laravel backend foundation (`phase-3-laravel-backend-foundation`)

#### Added
- Domain-oriented skeleton: 20 `app/Domain/*` folders (Plan §5.1).
- `app/Support/Money.php` — immutable integer-minor-unit money value object with
  currency-checked arithmetic, comparisons and integer-only formatting;
  `CurrencyMismatchException`.
- Enums: `Currency` (KES + USD forward-compat), `Severity`, `ErrorCode`.
- Structured API error envelope (Plan §11.5) via `app/Exceptions/ApiErrorRenderer`
  wired in `bootstrap/app.php`; 5xx responses carry a generic message + correlation
  id only.
- `CorrelationIdMiddleware` (+ `App\Support\CorrelationId`) — safe/length-bounded
  inbound `X-Correlation-ID` or generated ULID, echoed on the response and 5xx meta.
- Structured logging: `Support\Redaction\Redactor` and Monolog
  `RedactionProcessor` / `CorrelationIdProcessor` / `StructuredLogTap`, tapped onto
  the `single` and `stderr` channels (JSON + redaction + correlation id).
- Seven named rate limiters (Plan §9.3) registered in `AppServiceProvider`.
- `HealthController` with `/health` (liveness) and `/health/deep` (readiness:
  db/redis/cache required, meilisearch/s3 optional).
- `sentry/sentry-laravel ^4.10` wired (`Integration::handles`); env placeholders only.
- `routes/api.php` registering the `/api/v1` group (no business routes yet).
- Tests: `Unit/MoneyTest`, `Feature/Api/{ErrorEnvelope,CorrelationId,DeepHealth}Test`,
  `Feature/Security/LogRedactionTest`, `Feature/RateLimitersRegisteredTest`,
  `Feature/SentryConfigTest`.

#### Changed
- `bootstrap/app.php` — api routing + `apiPrefix=api/v1`, correlation middleware,
  `/health` + `/health/deep`, exception→envelope renderer, Sentry integration.
- `config/logging.php` (structured tap), `config/services.php` (meilisearch host),
  `app/Providers/AppServiceProvider.php`, `.env.example` (Sentry PII flag).
- `composer.json`/lock — added `sentry/sentry-laravel`.

#### Notes
- Framework tables (sessions/cache/jobs/job_batches/failed_jobs) already exist in
  the default migrations — confirmed, no new migration added.

### Phase 2 — Docker & environment setup (`phase-2-docker-environment`)

#### Added
- `docker/php.Dockerfile` — PHP-FPM 8.3 (alpine), extensions `pdo_pgsql`,
  `redis`, `intl`, `gd`, `bcmath`, `pcntl`, `zip`, `opcache`; Composer;
  non-root `servana` user; `dev` and `prod` build stages (Plan §26.1).
- `docker/nginx.Dockerfile` — non-root (nginx-unprivileged) edge image with a
  Node 20 SPA-build stage; `docker/nginx/default.conf`.
- `docker/php/php.ini`, `docker/php/opcache.ini`, `docker/php/entrypoint.sh`.
- `docker-compose.yml` — dev stack: app, nginx, postgres:16, redis:7,
  meilisearch, minio (+ bucket init), mailpit, clamav (opt-in `clamav`
  profile), worker + scheduler placeholders, spa-builder (`tools` profile);
  healthchecks on app/nginx/postgres/redis/meilisearch/minio/clamav.
- `docker-compose.prod.yml` — app/nginx/worker/scheduler against managed
  PG/Redis/S3 (Phase 25 completes deployment).
- `.dockerignore`.
- CI `docker` job building the app + nginx images (no push/deploy).
- `docs/proof/phase-2.md`.

#### Changed
- `.env.example` — full documented variable set with Docker service hostnames
  (postgres/redis/mailpit/minio/meilisearch); placeholders only.
- `Makefile` — real targets: `env up down restart logs ps shell composer npm
  fresh test lint stan build clamav-up`, run against the containers.
- `composer.json` — added `brianium/paratest` so `php artisan test --parallel`
  works; `pint` script made a passthrough (so `composer pint -- --test`).
- `.github/workflows/ci.yml` — `pcntl` extension, parallel tests, Pint check via
  `-- --test`.

#### Notes
- Horizon (Phase 21), ClamAV upload scanning (Phase 23), `/health/deep`
  (Phase 3), opcache preload + prod deploy (Phase 24/25) intentionally deferred
  — see `docs/PROGRESS.md`.

### Phase 1 — Project initialization (`phase-1-initialization`)

#### Added
- Laravel 11.54 application skeleton (PHP `^8.3`), domain-oriented per Plan §5.1
  (folders mature in later phases).
- Standalone Vue 3 + TypeScript + Vite 5 SPA under `resources/spa` (Pinia,
  Vue Router, self-hosted Inter/Manrope fonts), building to `public/spa`.
- Tailwind CSS configured with brand design tokens (Plan §12.1) and the exact
  responsive breakpoints `md: 768px`, `lg: 1025px` (Plan §13); dark-mode class
  strategy with pre-paint flash-prevention script (Plan §14).
- Quality tooling: Pest, Larastan level 8 with custom-rule placeholders
  (`NoWithoutTenancyOutsidePlatformRule`, `NoRawSqlConcatRule`), Pint, ESLint
  flat config, vue-tsc.
- Secret scanning: `.gitleaks.toml` + `.githooks/pre-commit` (activate with
  `git config core.hooksPath .githooks`).
- `.github/workflows/ci.yml`: PR-stage pipeline (Pint → Larastan → ESLint →
  vue-tsc → Pest on PostgreSQL 16 + Redis 7 → Vitest → build → dependency
  audits → gitleaks) per Plan §26.2.
- `GET /health` liveness endpoint and `tests/Feature/SmokeTest`.
- `.env.example` (Phase 1 minimal), `.editorconfig`, `pint.json`,
  `phpstan.neon`, `tsconfig.json`, `eslint.config.js`, `vite.config.ts`,
  `tailwind.config.ts`, `postcss.config.js`.
- Docs: `docs/PROGRESS.md`, `docs/proof/phase-1.md`, this changelog.

#### Changed
- `composer.json` rebranded to `citrus-labs/servana`; `audit.ignore` entry for
  CVE-2026-48019 (no Laravel 11 fix; documented, mitigated).
- `.gitignore` extended to ignore the `public/spa` build output.
- README "Local Development Setup" / "Common Commands" / "Repository Structure"
  updated to match the real scaffold.

#### Security
- Confirmed `.env` never entered git history; gitleaks gate clean on staged
  content. Pre-existing malformed local `.env` preserved as
  `.env.local-notes.bak` (gitignored).
