# Phase V — Security & Authorization Verification

Captured 2026-06-21 @ `e8681f6`. Evidence is repository/runtime-derived. A text
match alone is not treated as proof — behaviour is proven by tests, schema, the
running route table, or direct DB queries.

## 1. Dependency / secret / image scans
| Scan | Result |
|---|---|
| `composer audit --locked` (container) | clean — 0 advisories |
| `npm audit --audit-level=high` | 0 vulnerabilities |
| `gitleaks detect --no-git --redact` | no leaks (6.47 MB) |
| `docker build` php:dev + nginx:prod | both build clean |
| `composer.json` advisory ignore | removed (no `audit` key; no CVE/GHSA in source) |

## 2. Forbidden capabilities — proven absent (`route:list --json`)
38 total routes; 31 under `api/v1`. Full URI list in `routes.json`.
- **No Super-Administrator / platform merchant-creation route.** The only
  merchant-creation path is the public `POST api/v1/merchant-registration/self-register`
  (limiter `registration`). No `super-admin`, `platform`, or admin merchant-create
  URI exists. Guarded by `tests/Feature/Onboarding/NoPlatformMerchantCreationTest.php` (passing).
- **No personnel contact-export route.** No URI contains `export`, `contact`, or
  `personnel`. (ADR-010 surface does not exist — correct at this phase.)
- **No signed application route** beyond the framework default `storage/{path}`
  (local-disk driver). Signed-URL framework guarantee pinned by
  `SignedUrlIntegrityTest`.

## 3. Route authorization posture (middleware from routes.json)
- Public (no `auth:sanctum`), each with a dedicated limiter — enumeration posture:
  `auth/magic-link` (`magic-link-request`), `auth/magic-link/verify`
  (`magic-link-verify`), `merchant-registration/self-register` (`registration`),
  `staff-invitations/accept` (`invitation-accept`).
- Authenticated: `Authenticate:sanctum` → `EnforceIdleTimeout` →
  `ResolveTenantContext` → `EnsureMerchantActive` (60-min idle timeout + tenant
  context on every authenticated route).
- Branch-bound (`{branch}`): add `EnsureBranchScope`.
- Mutating branch routes: add `EnsurePermission:<key>` (e.g. `branches.create`,
  `branch.profile.manage`, `day.open_close`).
- Staff lifecycle / invitation / permission-override routes authorize via
  **Policies** in the controller (Plan §10.4), not `EnsurePermission` middleware
  — verified by passing `AuthorityBoundariesTest`, `HrSelfEscalationTest`,
  `AuditReadOnlyTest`, `PermissionMiddlewareTest`.

## 4. Tenant / branch isolation
- Global scopes `BelongsToMerchant` (MerchantScope) + `BelongsToBranch`
  (BranchScope); `withoutTenancy()`/`withoutGlobalScope()` appear **only** in the
  trait definition (sanctioned escape, inside `app/Domain/Tenancy`) and in the
  two PHPStan rule files. No usage elsewhere.
- No raw-SQL concatenation, no `$guarded = []`, no static `::find()/::where()`
  in controllers (route-model binding used). Enforced by
  `Security/TenancyStaticAnalysisTest` (3 source-scan tests passing) + Larastan
  rules `NoWithoutTenancyOutsidePlatformRule`, `NoRawSqlConcatRule`.
- Foreign-tenant ULID → 404 + `unauthorized_access` audit:
  `Isolation/RouteBindingTest`, `Isolation/UnauthorizedAccessAuditTest`,
  `Isolation/CrossTenantAccessTest` (passing).
- Same-tenant out-of-branch → documented 403 posture:
  `Isolation/CrossBranchAccessTest`, `Isolation/BranchRouteBindingTest` (passing).
- **Gap (R5 / REM-TEN-001):** branch-owned tables
  `branch_calendar_exceptions`, `branch_cash_ups`, `branch_day_records`,
  `branch_operating_hours`, `branch_user_assignments` carry `branch_id` but **no
  `merchant_id`** (merchant isolation derived via branch→merchant subquery).
  Confirmed by tenant-column coverage query.

## 5. Magic Link (Plan §9.1) — `MagicLinkTokenService`
- SHA-256 at rest (`token_hash`; raw token never stored/returned), 15-min expiry
  (`EXPIRY_MINUTES = 15`).
- Atomic single-use: conditional `UPDATE ... WHERE token_hash=? AND consumed_at
  IS NULL AND invalidated_at IS NULL AND expires_at > now`; requires affected
  rows == 1. Uniform `null` on miss → no enumeration.
- Suspension/replacement: `invalidateUnconsumedForEmail()` sets `invalidated_at`.
- Tests: `MagicLinkTokenSecurityTest`, `MagicLinkRequestTest`,
  `MagicLinkConsumeTest`, `ExpiredMagicLinkTest`, `ReusedMagicLinkTest`,
  `NoAccountEnumerationTest`, `MerchantRegistrationEnumerationTest` (passing).

## 6. Seven-check eligibility + idle timeout + revocation
- Eligibility contract: `LoginEligibilityMerchantMembershipTest`,
  `BranchAssignmentEligibilityTest`, `InactiveMerchantUserCannotLoginTest` (passing).
- Idle timeout middleware on all authenticated routes (`EnforceIdleTimeout`),
  `SessionLifecycleTest` (passing).
- Suspension/deactivation revokes sessions + unused Magic Links + pending
  invitations: `Hr/StaffLifecycleRevocationTest`, `Hr/StaffSuspensionTest`,
  `Security/SuspendedMerchantTest` (passing).
- **Gap (R6 / REM-SESS-001):** an explicit *per-request* active-membership +
  active-role re-check on **every** authenticated request, and the full
  mid-session-suspension matrix, are owned by R6. Current backstop:
  `ResolveTenantContext` + `EnsureMerchantActive` re-resolve context per request.

## 7. Permissions / authority boundaries
- Registry resolver: role defaults ± per-user overrides; deny beats grant;
  suspended/deactivated → no permissions; read-only `audit` can never gain a
  mutating key. `PermissionMatrixTest` (DB == registry), `AuthorityBoundariesTest`,
  `HrSelfEscalationTest`, `AuditReadOnlyTest`, `PermissionDeniedStillWorksTest`,
  `PermissionOverrideAuditTest` (passing).
- **Scope note (R-PERM / Phase 19):** registry is 54 keys × 8 roles (baseline);
  the canonical Plan §19 matrix is larger. Reconciliation + parity test owned by
  Phase 19 (REM-PERM-001). Not a Phase V defect.

## 8. Audit log immutability & chain
- Columns: `previous_hash char(64)`, `hash char(64) NOT NULL`, `severity` CHECK.
- Trigger `audit_logs_block_mutation()` on BEFORE UPDATE + BEFORE DELETE.
- Runtime proof (disposable DB): UPDATE and DELETE both raise
  `audit_logs is append-only (... blocked)`; row intact.
- **Gap (R2 / REM-AUD-001):** full event coverage + hash-chain *verifier*
  command + masked read API are owned by R2/Phase 19.

## 9. Staff invitations
- SHA-256-hashed token, 72h expiry, raw token only in the email; atomic accept
  (user + membership + staff_profile + branch assignment + history).
- `Hr/StaffInvitationTest`, `Hr/StaffInvitationAcceptTest`,
  `Hr/StaffInvitationResendRevokeTest`, `Security/DuplicateActiveStaffTest` (passing).

## 10. Log redaction
- `Security/LogRedactionTest` passing (Plan §24.5 / §9.13 redaction list:
  tokens, references, PII never logged).
