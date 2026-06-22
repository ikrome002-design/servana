# Phase R3 — Privileged MFA & Step-Up — Proof of Resolution

**Requirement:** REM-MFA-001 (C0, PRE_FEATURE) · Plan §17, §18, §9.4, §13.5, §79 R3.
**Branch:** `phase-r3-privileged-mfa-step-up` · **Base:** merged `main` `1df759e` (PR #14, R2).
**Date:** 2026-06-22.

All secrets, OTPs, and recovery codes are redacted from this document; the
implementation never logs or audits them, and the tests assert their absence.

---

## 1. Prove the problem (before-state)

Captured on this branch before any code change (clean schema + `route:list`):

```
Tables matching 'mfa'        : (none)
MFA route in route:list      : (none)
app/Http/Middleware/EnsurePrivilegedMfa.php : False
app/Http/Middleware/RequireFreshMfa.php     : False
```

`MfaController` was a placeholder returning `mfa_not_enabled` and was not a live
route (Phase 5 deferral). Plan §4 finding 3 / §4.1 row 3: "MFA placeholder only —
**confirmed** (no privileged MFA) → R3 (REM-MFA-001)". So privileged accounts
(Super Administrator, Merchant Administrator, Finance) had **no second factor and
no step-up**, and the seven designated sensitive actions had no freshness gate.

---

## 2. Schema & constraint evidence (`\d` from PostgreSQL 16)

`mfa_credentials` — identity-owned (no merchant_id), one authenticator per user,
encrypted secret, replay state:

```
 Column             | Type                  | Nullable | Default
 id                 | bigint                | not null | identity
 ulid               | character(26)         | not null |
 user_id            | bigint                | not null |
 type               | character varying(20) | not null | 'totp'
 secret_encrypted   | text                  | not null |          <- encrypted at rest
 confirmed_at       | timestamp             |          |          <- enrolled vs confirmed
 last_used_at       | timestamp             |          |
 last_used_timestep | bigint                |          |          <- RFC 6238 replay guard
 created_at/updated_at
Indexes: PK(id); UNIQUE(ulid); UNIQUE(user_id, type)
Check  : mfa_credentials_type_check CHECK (type = 'totp')
FK     : user_id -> users(id) ON UPDATE CASCADE ON DELETE RESTRICT
```

`mfa_recovery_codes` — hashed, single-use:

```
 Column     | Type          | Nullable
 id         | bigint        | not null  (identity)
 ulid       | character(26) | not null
 user_id    | bigint        | not null
 code_hash  | character(64) | not null            <- SHA-256 hex only
 used_at    | timestamp     |                     <- single-use marker
 created_at/updated_at
Indexes: PK(id); UNIQUE(ulid); UNIQUE(code_hash); INDEX(user_id, used_at)
FK     : user_id -> users(id) ON UPDATE CASCADE ON DELETE RESTRICT
```

Migrations are forward-only (`2026_06_22_000001`, `2026_06_22_000002`); no shipped
migration was edited; no backfill. The data-dictionary entry was authored before
the migrations per Plan §13.2
(`docs/architecture/data-dictionary/core-identity-and-tenancy.md`).

> Note: timestamps are `timestamp(0)` (no tz), consistent with the sibling
> as-built identity tables; the project-wide tz reconciliation is not owned by R3.

---

## 3. Encryption-at-rest & hash-at-rest proof

- **TOTP secret encrypted at rest** — `MfaCredential` casts `secret_encrypted`
  with Laravel's `encrypted` cast (AES-256-GCM via `APP_KEY`).
  `MfaEnrollmentTest::it encrypts the totp secret at rest` and
  `MfaSecretRedactionTest::it stores only ciphertext for the totp secret` assert
  the raw DB column ≠ the plaintext secret returned by enrollment, while the model
  decrypts back to the same value.
- **Recovery codes hashed at rest** — only `SHA-256(code)` is stored.
  `MfaRecoveryCodeTest::it stores recovery codes only as hashes` and
  `MfaSecretRedactionTest::it stores only hashes for recovery codes` assert each
  stored value is 64-hex and is **not** any plaintext code, and that every
  plaintext maps to a stored hash.

---

## 4. Middleware order proof (Plan §9.4 step 2 / §18)

`bootstrap/app.php` priority pins `EnsurePrivilegedMfa` **after**
`AuthenticatesRequests` and **before** `ResolveTenantContext`.

- **Structural** — `MfaMiddlewareOrderTest::it places EnsurePrivilegedMfa after
  auth and before ResolveTenantContext` resolves the priority-sorted stack for the
  `me` route via `Router::gatherRouteMiddleware` and asserts
  `auth < EnsurePrivilegedMfa < ResolveTenantContext`.
- **Behavioural** — `...it checks MFA before tenant route-model binding`: a
  merchant_admin who owns a branch but has no MFA gets `403 mfa_enrollment_required`
  on `GET /branches/{ulid}` (not `200`, not `404`), proving the gate runs before
  binding/tenant resolution.

---

## 5. Mandatory-role enforcement matrix (`testing/privileged-probe`)

`PrivilegedMfaMiddlewareTest` (all passing):

| Identity | MFA state | Result |
|---|---|---|
| Merchant Administrator | no credential | `403 mfa_enrollment_required` |
| Merchant Administrator | confirmed, no session assertion | `403 mfa_challenge_required` |
| Merchant Administrator | confirmed + fresh assertion | `200` |
| Finance | no credential | `403 mfa_enrollment_required` |
| Platform Super Administrator (`is_platform_staff`) | no credential | `403 mfa_enrollment_required` |
| Front Office (non-mandatory) | no MFA | `200` (not blocked) |
| Finance (confirmed + asserted) | lacks `branches.create` | `403 permission_denied` (MFA ≠ authorization) |

`MfaRequirementResolver` resolves mandatory roles from platform status + **active**
memberships **without** `TenantContext`. While MFA is incomplete, only
status/enroll/confirm/challenge/recovery-challenge + `/me` + logout are allowlisted.

---

## 6. Magic Link → MFA handoff

- Magic Link verify still logs in and regenerates the session id, but does **not**
  set `mfa_verified_at` (the Magic Link is never the MFA assertion).
- A mandatory user lands in a restricted session; `/me` (allowlisted) returns the
  safe `mfa` block (`enrollment_required` or `challenge_required`) so the SPA
  routes to setup/challenge.
- A successful TOTP/recovery challenge sets `mfa_verified_at` in the **server
  session**, regenerates the session id, populates tenant context, and returns the
  full bootstrap. Logout invalidates the session (clears the assertion).

The auth response exposes only safe state — `required, enrolled, confirmed,
verified, enrollment_required, challenge_required, step_up_fresh,
step_up_fresh_until, recovery_codes_remaining` — never the secret or hashes
(`MfaSecretRedactionTest::it never exposes the secret or hashes in the bootstrap
or status payloads`).

---

## 7. TOTP, recovery-code & step-up results

- **Enrollment/confirmation** — valid TOTP confirms and returns recovery codes
  once; invalid code → `mfa_invalid_code` and the credential stays unconfirmed; an
  abandoned unconfirmed enrollment rotates its secret safely; re-enroll while
  confirmed → `mfa_invalid_state`; no recovery codes exist before confirmation.
- **Challenge** — valid TOTP asserts the session; **replayed** TOTP →
  `mfa_invalid_code` (replay guard via `last_used_timestep`); challenge with no
  confirmed credential → `mfa_invalid_state`.
- **Recovery codes** — single-use via an atomic conditional UPDATE; second use of
  the same code → `mfa_invalid_code`; `RecoveryCodeManager::consume` returns
  `true` then `false` for the same code (concurrency-safe semantics); remaining
  count decrements; regeneration replaces the whole set.
- **Step-up** — `MfaStepUpTest` loops **every** `StepUpAction::businessActions()`
  classification: missing assertion → `step_up_required`; stale (older than the
  window) → `step_up_required`; fresh → `200`. The live recovery-codes route also
  enforces fresh step-up. `StepUpAction::from('not_a_real_action')` throws (a route
  can never name an unregistered classification).

---

## 8. Rate-limit results

`mfa-confirm` (5/min) and `mfa-challenge` (5/min/user + 15/min/ip).
`MfaChallengeTest::it rate-limits repeated challenge attempts` — 5 attempts return
`422`, the 6th returns `429`.

---

## 9. Audit & redaction results

`MfaAuditTest` (canonical R2 chain, actor = user, null-merchant chain):
`mfa.enrollment_started`, `mfa.enrollment_confirmed`, `mfa.challenge_succeeded`,
`mfa.challenge_failed`, `mfa.recovery_code_used`, `mfa.step_up_denied`,
`mfa.step_up_succeeded` are all recorded. `...it never stores secrets or codes in
audit context and the chain still verifies` scans every `audit_logs.context` and
asserts neither the secret nor any recovery code appears, then runs
`audit:verify-chain` (exit 0). Secrets/codes/session ids are never placed in audit
context (`MfaAuditLogger` stores only `user_ulid` + safe extras).

---

## 10. Full-suite results

```
docker compose exec app php artisan migrate (mfa tables) ............ DONE
docker compose exec app php artisan test ........... 311 passed, 4 skipped
  └ Mfa* (8 suites) .............................. 43 MFA tests passed
docker compose exec app php artisan audit:verify-chain ............. exit 0
docker compose exec app composer validate --strict ................ valid
docker compose exec app composer pint -- --test ....... clean (4 auto-fixed)
docker compose exec app composer stan (Larastan L8) ............... 0 errors
docker compose exec app composer audit --locked ........... 0 advisories
npm run lint ........................ 0 errors (28 pre-existing warnings)
npm run typecheck (vue-tsc) ...................................... clean
npm run test (vitest) ......................................... 77 passed
npm run build .................................................... built
npm run e2e (playwright) ...................................... 30 passed
npm audit --audit-level=high ......................... 0 vulnerabilities
gitleaks detect --no-git --redact ............................. no leaks
docker build php.Dockerfile  --target dev ....................... exit 0
docker build nginx.Dockerfile --target prod ..................... exit 0
```

### Initial failures (recorded, not erased)
- **Replay test failed on first run** — `verifyKeyNewer` returns boolean `true`
  (not the time-step) when `oldTimestamp` is null, so the first verify stored
  `(int) true = 1` and the replay was accepted. Root cause fixed in `TotpProvider`
  (pass `0` for the first verification so the absolute time-step is returned and
  persisted); rerun green. A successful rerun does not erase the original failure.
- **Pint** — 4 import-ordering issues auto-fixed. **Larastan** — 1 nullable-arg
  error in `AuthenticatedUserResource`, fixed with an explicit `User` bind.
- **gitleaks** — 2 findings on placeholder secrets in `authStore.spec.ts`;
  replaced with low-entropy fixtures; rerun clean.

---

## 11. Dependency added

`pragmarx/google2fa` v8.0.3 (+ `paragonie/constant_time_encoding`) — actively
maintained, narrowly scoped RFC 6238 TOTP implementation with constant-time
verification (`hash_equals`). Chosen so the TOTP algorithm is **not** implemented
by hand. `composer audit --locked` = 0 advisories; no unrelated upgrades.

---

## 12. Files changed

New: 2 migrations; `app/Domain/Auth/Mfa/{TotpProvider,RecoveryCodeManager,
MfaRequirementResolver,MfaSession,MfaManager,MfaStatus,MfaAuditLogger,
StepUpAction}.php`; `app/Domain/Auth/Models/{MfaCredential,MfaRecoveryCode}.php`;
`app/Domain/Auth/Exceptions/{MfaException,MfaEnrollmentRequiredException,
MfaChallengeRequiredException,InvalidMfaCodeException,StepUpRequiredException,
MfaStateException}.php`; `app/Http/Middleware/{EnsurePrivilegedMfa,
RequireFreshMfa}.php`; `app/Http/Requests/Auth/MfaCodeRequest.php`;
`database/factories/{MfaCredential,MfaRecoveryCode}Factory.php`;
`docs/architecture/data-dictionary/{README,core-identity-and-tenancy}.md`; 8
backend test files; SPA `pages/auth/{MfaSetup,MfaChallenge}.vue`;
`tests/e2e/mfa.spec.ts`.

Changed: `app/Http/Controllers/Api/V1/Auth/MfaController.php`;
`app/Domain/Audit/Enums/AuditEvent.php`; `bootstrap/app.php`; `routes/api.php`;
`app/Providers/AppServiceProvider.php`;
`app/Http/Resources/Auth/AuthenticatedUserResource.php`; `config/servana.php`;
SPA `types/models.ts`, `stores/authStore.ts` (+ spec), `router/index.ts`,
`router/routes/auth.ts`; `tests/TestCase.php`, `tests/Pest.php`.

---

## 13. Work skipped & owning phases

- Step-up attachment to real business routes — routes do not exist yet; R3 ships
  the reusable control + a `testing`-only harness. Owners: billing **20A**; refund
  finalization & period reopen **18B**; payout approval/mark-paid **20H**; M-Pesa
  reconciliation resolution **20D**; backdated compensation **20F/20G**. Each owning
  phase MUST attach `RequireFreshMfa::class.':'.StepUpAction::<case>`.
- WebAuthn/passkeys, SMS/email OTP — later security enhancement (unless separately
  authorized).
- Administrator MFA reset/recovery and any "disable MFA" endpoint — future
  account-recovery phase (intentionally not built).
- Complete per-request session/membership revocation — **R6** (REM-SESS-001).

---

## 14. Remaining risks

- TOTP acceptance window ±1 step (±30s) for clock drift; replay independently
  blocked by `last_used_timestep`.
- No administrator MFA reset yet — loss of both authenticator and recovery codes
  needs the future account-recovery phase.
- The test base auto-provisions a confirmed MFA session for mandatory roles under
  `actingAs(..., 'sanctum')` (R3 changed the privileged-route precondition);
  MFA-state tests opt out via `withoutMfaSession()` / `statefulMfa()`.

---

## 15. REM-MFA-001 status

`local_complete`. Promotion to `verified_complete` requires the R3 PR merged, CI
green, and a required review or a truthful PR-specific governance exception — not
asserted here.

## Solo-Maintainer Review Exception

An independent second reviewer was unavailable because the repository currently
has one eligible maintainer. The product owner authorized a PR-specific
governance exception rather than fabricating approval.

Evidence:

- PR: #15
- CI/Backend: passed
- CI/Frontend: passed
- CI/Security: passed
- CI/Docker: passed
- GitHub reviewDecision: intentionally blank
- Exception record: docs/governance/solo-maintainer-review-exception-pr-15.md

This exception applies only to PR #15.
