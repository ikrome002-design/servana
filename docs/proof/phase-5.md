# Phase 5 Proof — Authentication (Magic Link + sessions)

**Branch:** `phase-5-authentication`
**Date:** 2026-06-14
**Plan sections implemented:** §9 (Authentication Model: §9.1 Magic Link flow,
§9.2 session security, §9.3 rate limiting), §11.5 (error envelope), Scope §2.3
(seven Merchant Access checks).

---

## 1. Evidence of requirement

Plan §27 Phase 5 objective: "§9 fully" — Magic Link authentication, sessions,
verification, `/me` bootstrap, session lifecycle, throttling, no enumeration,
hashed single-use tokens. Phase 4 left `LoginStub.vue`/`VerifyStub.vue` and an
empty `authStore`; Phase 3 shipped the error envelope, correlation id, and the
seven named rate limiters (names only). Phase 5 builds the real flow on top.

---

## 2. Files created / modified

### Backend — Domain
- `app/Domain/Auth/Models/MagicLoginToken.php` — hash-only token model; ULID on create.
- `app/Domain/Auth/Services/MagicLinkTokenService.php` — issue (64 random bytes,
  base64url, SHA-256 at rest, 15-min expiry) + **atomic single-use consume**.
- `app/Domain/Auth/Services/LoginEligibilityService.php` — the **seven-check
  contract** (1/3/5/7 enforced now; 2/4/6 deferred behind a feature flag — see §6).
- `app/Domain/Auth/Actions/RequestMagicLink.php` — uniform side-effect-only request.
- `app/Domain/Auth/Actions/ConsumeMagicLink.php` — consume + re-check + stamp user.
- `app/Domain/Auth/Notifications/MagicLoginLinkNotification.php` — branded mail, `mail` queue.
- `app/Domain/Auth/Support/{EligibilityResult,AuthEventLogger}.php` — result VO + interim audit sink.
- `app/Domain/Auth/Enums/AuthEvent.php` — `login_link_requested|denied|failed`, `login_success`, `logout`.
- `app/Domain/Auth/Exceptions/InvalidMagicLinkException.php` — uniform 422 `invalid_or_expired_token`.

### Backend — HTTP
- `app/Http/Controllers/Api/V1/Auth/{MagicLinkController,MeController,MfaController}.php`
- `app/Http/Requests/Auth/{RequestMagicLinkRequest,VerifyMagicLinkRequest}.php`
- `app/Http/Resources/Auth/AuthenticatedUserResource.php`
- `app/Http/Middleware/EnforceIdleTimeout.php` — sliding 60-min idle timeout (§9.2).

### Backend — wiring / schema
- `database/migrations/2026_06_14_000001_add_auth_columns_to_users_table.php` —
  forward-only expand: `ulid`, `status`, `last_login_at`; `password` made nullable
  (Plan A3 / §7.1 "No password column").
- `database/migrations/2026_06_14_000002_create_magic_login_tokens_table.php`
- `database/migrations/2026_06_14_073055_create_personal_access_tokens_table.php` (Sanctum, published).
- `config/sanctum.php` (published), `config/auth.php` (`sanctum` guard), `config/servana.php` (new).
- `routes/api.php` (auth routes + limiters + idle middleware), `bootstrap/app.php`
  (`statefulApi()`), `app/Models/User.php`, `database/factories/{UserFactory,MagicLoginTokenFactory}.php`.
- `composer.json/lock` — added `laravel/sanctum ^4.3` (was missing).
- `docker-compose.yml` — worker now consumes the `mail` queue (defect fix, see §7).
- `.env.example` — `SESSION_LIFETIME=480`, `AUTH_IDLE_TIMEOUT_MINUTES`, `AUTH_ENFORCE_TENANCY_ELIGIBILITY`.
- `phpunit.xml` + `tests/bootstrap.php` — force the test env over docker-injected `$_SERVER` vars (defect fix, see §7).

### Frontend
- `resources/spa/src/pages/auth/{Login,CheckEmail,Verify}.vue` — replace the stubs
  (`LoginStub.vue`/`VerifyStub.vue` deleted).
- `resources/spa/src/stores/authStore.ts` — real `bootstrap()/requestMagicLink()/verifyMagicLink()/logout()`.
- `resources/spa/src/services/apiClient.ts` — typed `apiError` augmentation on `AxiosError`.
- `resources/spa/src/router/routes/auth.ts` (real pages + `auth.check-email`),
  `resources/spa/src/App.vue` (bootstrap on mount), `resources/spa/src/types/models.ts` (`AuthenticatedUser`).

---

## 3. Security properties (Plan §3 rule 14, §9)

| Property | How enforced | Test |
|---|---|---|
| Raw token never stored | only `token_hash = sha256(raw)` persisted | `MagicLinkTokenSecurityTest` |
| Raw token never logged | `AuthEventLogger` logs masked email + event only | `MagicLinkTokenSecurityTest` |
| Cryptographically random | `random_bytes(64)` → base64url (86 chars) | live token len = 86 |
| 15-minute expiry | `expires_at = now()+15m`; consume checks `expires_at > now()` | `ExpiredMagicLinkTest` |
| Single-use | atomic `UPDATE … WHERE consumed_at IS NULL` affecting exactly 1 row | `ReusedMagicLinkTest` |
| No enumeration (request) | always 202, identical body | `NoAccountEnumerationTest` |
| No enumeration (verify) | identical 422 for invalid vs ineligible | `NoAccountEnumerationTest` |
| No email on denial | eligibility checked before issue/notify | `MagicLinkRequestTest`, `InactiveMerchantUserCannotLoginTest` |
| Session id regenerated on login | `$request->session()->regenerate()` | `MagicLinkConsumeTest` (stateful) |
| Logout invalidates session | `logout()+invalidate()+regenerateToken()` | `SessionLifecycleTest` |
| Idle timeout | `EnforceIdleTimeout` 60 min | `SessionLifecycleTest` |
| `/me` requires auth | `auth:sanctum` | `SessionLifecycleTest` |
| Structured 429 | named limiters → envelope | `MagicLinkRequestTest` |

---

## 4. Test results

### Backend — `php artisan test --group=auth` (Docker, PostgreSQL 16)
```
PASS  Tests\Feature\Auth\ExpiredMagicLinkTest
PASS  Tests\Feature\Auth\InactiveMerchantUserCannotLoginTest
PASS  Tests\Feature\Auth\MagicLinkConsumeTest
PASS  Tests\Feature\Auth\MagicLinkRequestTest
PASS  Tests\Feature\Auth\NoAccountEnumerationTest
PASS  Tests\Feature\Auth\ReusedMagicLinkTest
PASS  Tests\Feature\Auth\SessionLifecycleTest
PASS  Tests\Feature\Security\MagicLinkTokenSecurityTest
Tests:    28 passed (104 assertions)
```

### Backend — full suite (Docker)
```
Tests:    69 passed (230 assertions)
```
(includes Phase 3/4 suites; previously Docker-only `DeepHealthTest` now green
because `tests/bootstrap.php` pins the test env — see §7.)

### Quality gates
```
composer pint -- --test   → PASS (81 files)
composer stan             → [OK] No errors (Larastan level 8)
npm run typecheck         → 0 errors
npm run test (vitest)     → 38 passed (7 files)   incl. authStore (6), Login (2), Verify (3)
npm run build             → built (vue-tsc + vite), no errors
npm run e2e (Playwright)  → 16 passed   incl. auth-magic-link (5) + foundation (11)
gitleaks --no-git         → no leaks found
npm audit --audit-level=high  → 0 vulnerabilities
composer audit            → 1 advisory, documented-ignored (CVE-2026-48019, not high/critical; Magic Link mitigates)
```

---

## 5. Live API + Mailpit transcript (Docker stack, http://localhost:8080)

Captured against the running stack. nginx proxies `/api/*` to Laravel.

**Request (always 202, no enumeration):**
```
POST /api/v1/auth/magic-link   {"email":"proof@servana.test"}
→ 202  {"message":"If the email exists and is active, a link was sent."}
```

**Mailpit received the branded mail** (after the worker drained the `mail` queue):
```
GET http://localhost:8025/api/v1/messages   → messages_count: 1
Subject: "Your Servana sign-in link"
Link in body: /auth/verify?token=<raw>     (extracted token length = 86 chars)
```

**Single-use enforced** — once the token was consumed, replaying it returned the
uniform failure:
```
POST /api/v1/auth/magic-link/verify   {"token":"<already-consumed>"}
→ 422  {"error":{"code":"invalid_or_expired_token","message":"This sign-in link is invalid or has expired. Please request a new one.","fields":{},"meta":{}}}
```

**Validation envelope (missing token):**
```
POST /api/v1/auth/magic-link/verify   {}
→ 422  {"error":{"code":"validation_failed","message":"The token field is required.","fields":{"token":["The token field is required."]},"meta":{}}}
```

> **Environment limitation (documented).** During capture the Windows Docker host
> became severely CPU-bound (a single queued job took ~3 min; some nginx→php-fpm
> requests returned 504/timeout). The 202 response, the real Mailpit delivery, the
> 86-char token, and single-use rejection were captured live. The clean `200`
> verify body, the `429` throttle response, and the `/me`→logout→`/me` session
> cycle were **not** reliably capturable over HTTP in this session due to host
> latency, but each is proven deterministically by the feature suite on real
> PostgreSQL:
> - `200` verify + bootstrap payload + ULID (not PK): `MagicLinkConsumeTest`
> - `429` structured throttle: `MagicLinkRequestTest > it throttles repeated requests…`
> - session login/logout/idle: `SessionLifecycleTest`
> - expired `422`: `ExpiredMagicLinkTest`

---

## 6. Seven-check eligibility contract (Scope §2.3) — deferral record

`LoginEligibilityService::check()` is called at **request** time and re-run at
**consume** time. The result is uniform; the denial reason is audit-only.

| # | Check | Phase 5 |
|---|---|---|
| 1 | user exists by email | **Enforced** |
| 2 | active merchant membership (or platform staff) | **Deferred → Phase 6** |
| 3 | user.status = active | **Enforced** |
| 4 | merchant_users.status = active | **Deferred → Phase 6** |
| 5 | user not suspended | **Enforced** (user level) |
| 6 | branch assignment for branch-scoped roles | **Deferred → Phase 7** |
| 7 | token valid/unused/unexpired | **Enforced** (consume) |

Checks 2/4/6 depend on the merchant tenancy schema (merchants, merchant_users,
merchant_branches, branch_user_assignments) owned by Phases 6–7, which does **not
exist yet**. Enforcing them now would make every login impossible. They are gated
behind `config('servana.auth.enforce_tenancy_eligibility')` (default **false**)
with fixed seam methods (`hasActiveMembershipOrIsPlatformStaff`,
`hasRequiredBranchAssignment`). **No Phase 6 tenant tables were created in Phase 5.**
Phase 6/7 implement the seam bodies and flip the flag — the request/consume flow
is untouched.

---

## 7. Defects found & fixed (Bug Fix Protocol)

**Defect A — test env not applied under Docker (rate limiters bled, sessions wrong).**
- Observed: a single isolated `magic-link` request returned 429; verify returned 500/419.
- Evidence: diagnostic test showed `app.env=local`, `cache.default=redis` despite phpunit `<env>`.
- Root cause: docker-compose injects `APP_ENV/CACHE_STORE/SESSION_DRIVER` as real env
  vars, which land in `$_SERVER`. Laravel's env reader prioritises `$_SERVER`, and
  phpunit `<env>` (even `force="true"`) only sets `$_ENV`/putenv — so the suite ran
  against the shared redis cache (limiter counts accumulated across runs) and the
  container session driver.
- Fix: `tests/bootstrap.php` overrides `$_SERVER`/`$_ENV`/putenv before the framework
  boots; `phpunit.xml` `bootstrap` points to it.
- Result: `app.env=testing`, `cache.default=array`; all 69 tests green and deterministic.

**Defect B — Magic Link emails never delivered by the dev worker.**
- Observed: `POST /auth/magic-link` returned 202 but Mailpit stayed empty.
- Root cause: the notification is dispatched to the `mail` queue (Plan §20), but the
  `worker` container ran `queue:work` with no `--queue`, processing only `default`.
- Fix: worker command → `queue:work --queue=mail,default`. Mailpit then received the mail.
- Remaining risk: full per-queue config (pdf/billing/search/notifications) lands with Horizon (Phase 21).

---

## 8. Skipped / deferred (owning phase)

```
Skipped:
- Item: Merchant self-registration, first-time setup, full tenant/account model
- Reason: Phase 6 owns merchants/merchant_profiles/merchant_users + onboarding.
- Correct future phase: Phase 6
- Risk if forgotten: no tenants; eligibility checks 2/4 stay deferred.

Skipped:
- Item: Eligibility checks 2 & 4 (membership/role) enforcement
- Reason: needs merchant_users (Phase 6). Seam + feature flag in place.
- Correct future phase: Phase 6
- Risk if forgotten: any active user could sign in once tenants exist — MUST flip flag + fill seam.

Skipped:
- Item: Eligibility check 6 (branch assignment) enforcement
- Correct future phase: Phase 7
- Risk if forgotten: branch-scoped roles not branch-gated at login.

Skipped:
- Item: Instant session/token revocation on suspension (delete sessions + invalidate tokens)
- Reason: StaffLifecycleService + suspension flows are Phase 7; `magic_login_tokens.invalidated_at`
  column + consume guard already exist and are tested.
- Correct future phase: Phase 7 (Plan §9.2)
- Risk if forgotten: suspended users keep live sessions until idle/absolute expiry.

Skipped:
- Item: Real MFA (TOTP)
- Reason: optional/future (AS-2, §9.2 "when enabled"); MfaController is a safe
  "mfa_not_enabled" placeholder, unrouted. No weak MFA shipped.
- Correct future phase: account-model phase that owns the privileged roles.
- Risk if forgotten: none at launch (MFA not required).

Skipped:
- Item: Roles/permissions registry, full /api surface, role nav, responsive/dark/a11y
  sweeps, Horizon, uploads, opcache, deployment.
- Correct future phase: 8 / 10 / 11 / 12 / 13 / 14 / 21 / 23 / 24 / 25.
```

---

## 9. Known risks

- **Eligibility flag must be flipped in Phase 6/7.** With
  `enforce_tenancy_eligibility=false`, any *active* user passes checks 2/4/6. This is
  correct for Phase 5 (no tenants exist) but is a hard Phase 6 integration gate.
- **Suspension revocation is partial.** User-level `status` denies new links and
  consume; deleting live `sessions` rows on suspension is Phase 7.
- **Queued raw token in transit.** The notification serialises the raw token into the
  queue payload (standard Laravel). Transient; never persisted/logged. Revisit if a
  durable encrypted queue is required.
- **Host performance.** Windows Docker host was CPU-bound during this session (slow
  FPM); does not affect code correctness (tests green) but slows live capture.
- CVE-2026-48019 (Laravel 11 email rule) still documented-ignored; mitigated by Magic
  Link + FormRequest validation; revisit at Laravel 12.

---

## 10. Commands

```
# Backend (Docker)
docker compose exec app php artisan test --group=auth      → 28 passed
docker compose exec app php artisan test                    → 69 passed
docker compose exec app composer pint -- --test            → PASS
docker compose exec app composer stan                       → No errors

# Frontend (host)
npm run typecheck   → 0 errors
npm run test        → 38 passed
npm run build       → built
npm run e2e         → 16 passed

# Security (host)
gitleaks detect --source . --no-git --redact → no leaks
npm audit --audit-level=high                 → 0 vulnerabilities
composer audit                               → 1 documented-ignored advisory
```

---

## 11. Context for Phase 6

- Branch from merged `main` as `phase-6-account-tenant-model`.
- Implement `merchants`, `merchant_profiles`, `merchant_users` (+ seeders) and
  registration/first-time-setup.
- **Wire eligibility checks 2 & 4:** fill `LoginEligibilityService::hasActiveMembershipOrIsPlatformStaff()`
  and set `AUTH_ENFORCE_TENANCY_ELIGIBILITY=true`. Add `InactiveMerchantUserCannotLoginTest`
  cases for membership/role denial.
- `magic_login_tokens.intended_merchant_id` (deferred in Phase 5) can be added when merchants exist.
- `/me` `memberships`/`permissions` arrays become populated (Phase 6/8); the resource contract is stable.
