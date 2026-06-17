# Phase 9 — Tenant-scoped data access hardening — Proof

**Branch:** `phase-9-tenant-scoped-data-access-hardening` (based on merged `main`, PR #1–#8).
**Plan sections implemented:** §8 (multi-tenancy & data isolation) complete — §8.2
query scoping, §8.3 tenant-aware jobs, §8.4 denied-case tests; §27 Phase 9.

---

## 1. Prove the problem (Manifesto §1)

Through Phase 8, tenant isolation was enforced **per-controller** (explicit
`where('merchant_id', …)` predicates + `EnsureBranchScope` + manual
`abort_if(merchant mismatch, 404)`). There was no structural guarantee: a new
query that forgot the predicate would silently read across tenants, the PHPStan
tenancy rule was a no-op placeholder, no model auto-filled `merchant_id`, and
cross-tenant attempts were not audited. Plan §8.2/§8.3/§8.4 require global scopes,
scoped route binding, `LogUnauthorizedAttempt`, `TenantAwareJob`, and an active
static-analysis rule.

## 2. What was built (Manifesto §2–§3)

### Tenancy traits & scopes (Plan §8.2)
- `app/Domain/Tenancy/Scopes/MerchantScope.php` — global `where merchant_id = …`,
  applied only when a merchant is resolved (no-context reads — login eligibility,
  self-registration, platform — stay unfiltered).
- `app/Domain/Tenancy/Scopes/BranchScope.php` — branch-scoped roles see only their
  assigned branches; merchant-wide roles (admin) see every branch of the merchant
  (so branch-owned models without a `merchant_id` column still cannot leak).
- `app/Domain/Tenancy/Concerns/BelongsToMerchant.php` — adds MerchantScope; a
  `creating` hook fills `merchant_id` from context (explicit value wins — onboarding
  inserts) and throws `MissingTenantContext` when neither is available; a scoped
  `resolveRouteBinding()` that 404s a foreign ULID and audits via
  `LogUnauthorizedAttempt`; the sanctioned `withoutTenancy()` escape.
- `app/Domain/Tenancy/Concerns/BelongsToBranch.php` — adds BranchScope; overridable
  `branchColumn()` (StaffProfile → `primary_branch_id`).
- `app/Domain/Tenancy/Exceptions/MissingTenantContext.php`.

### Model coverage (Plan §8.2, §6 of the brief)
| Model | Traits | Note |
|---|---|---|
| MerchantProfile, MerchantUser, MerchantStatusHistory | `BelongsToMerchant` | merchant-owned |
| MerchantBranch | `BelongsToMerchant` | branch root; per-branch via `EnsureBranchScope` |
| StaffInvitation, StaffProfile | `BelongsToMerchant` + `BelongsToBranch` | StaffProfile branch col = `primary_branch_id` |
| BranchOperatingHour, BranchCalendarException, BranchDayRecord, BranchCashUp | `BelongsToBranch` | no `merchant_id`; merchant isolation via the branch subquery |

**Deliberately excluded (documented):** `Merchant` (the tenant root — resolved via
TenantContext, has no `merchant_id`); `BranchUserAssignment` (branch-scope
*resolution machinery* — scoping it is circular with `activeBranchIds()`; reached
only via the already-scoped membership); `StaffHistory` (append-only, reached via
`staff_profile_id` whose parent is scoped); `MagicLoginToken` (pre-tenant login);
`Permission`/`Role`/`RolePermissionAssignment` (global registry); `MerchantUserPermissionOverride`
(per-membership, reached via scoped staff); `AuditLog` (the audit sink — `merchant_id`
is set explicitly by the recorder, incl. `null` for platform events); `User` (global
identity). Reads of these are added in Phase 19 (audit) / future platform phases.

### Scoped route binding & ordering (Plan §8.2)
- `bootstrap/app.php` pins `ResolveTenantContext` immediately before
  `SubstituteBindings` in the middleware priority, so bindings resolve **inside**
  merchant scope.
- `resolveRouteBinding()` scopes by merchant (drops branch scope so a same-merchant
  cross-branch resource still reaches its policy as a 403, not a 404 — see decision
  in §5). Foreign-merchant ULID → `null` → `LogUnauthorizedAttempt` → 404.
- `EnsureBranchScope` also audits the foreign-branch 404 path (deterministic
  regardless of binding-vs-middleware order).
- `ResolveTenantContext::terminate()` resets the context after the response.

### Unauthorized attempt logging (Plan §8.4, §22.2)
- `app/Domain/Tenancy/Services/LogUnauthorizedAttempt.php` — a single **unscoped
  boolean existence check** (no row hydrated) distinguishes "missing" from "exists
  in another tenant"; only the latter writes a high-severity `unauthorized_access`
  audit row carrying actor, merchant, model, attempted ULID, route, method, path,
  and correlation id. The foreign row is never linked (`auditable_id` null) and no
  request body/secret is recorded.

### Tenant-aware jobs (Plan §8.3)
- `app/Domain/Tenancy/Jobs/TenantAwareJob.php` — captures `merchantId` (+ optional
  `branchId`) at dispatch; `handle()` rehydrates `TenantContext` (re-validating the
  merchant is active) before `handleWithinTenant()`; fails `MissingTenantContext`
  when the id is absent or the merchant is missing/suspended/deactivated.
  `TenantContext::bindForJob()` added.

### Static analysis (Plan §3/§24, §27 Phase 9)
- `NoWithoutTenancyOutsidePlatformRule` — flags `withoutTenancy()`,
  `withoutGlobalScope()`, `withoutGlobalScopes()` outside `App\Domain\Tenancy` /
  `App\Domain\Platform`.
- `NoRawSqlConcatRule` — flags raw-SQL calls whose argument is a concatenation or
  interpolated string.
- `tests/Feature/Security/TenancyStaticAnalysisTest.php` — a fast source scan that
  also catches `::find()` in controllers, as a CI-independent backstop.

## 3. Tests (Manifesto §4)

`tests/Unit/TenantAwareJobTest.php`, `tests/Feature/Isolation/{RouteBinding,
CrossTenantAccess,CrossBranchAccess,UnauthorizedAccessAudit,PermissionDeniedStillWorks,
FutureResourceIsolation}Test.php`, `tests/Feature/Security/{SuspendedMerchant,
TenancyStaticAnalysis}Test.php` (+ the pre-existing `Isolation/BranchRouteBindingTest`).

Proven: foreign-tenant ULID → 404 (branch, staff, invitation) with an
`unauthorized_access` high-severity audit row; a genuinely missing ULID is NOT
audited; same-merchant unassigned branch → 403 `no_branch_scope`; in-scope but
unpermitted → 403 `permission_denied` (not swallowed into 404); `merchant_id`
auto-fills from context on create; create with neither context nor `merchant_id`
→ `MissingTenantContext`; explicit `merchant_id` create with no context still works
(onboarding); cross-merchant list/query returns only own rows; `TenantAwareJob`
rehydrates context, and fails for missing/suspended/non-existent merchant; no
bigint id is serialized.

## 4. Demonstrate resolution (Manifesto §5)

```
docker compose exec app php artisan migrate:fresh --seed   → 26 migrations OK; seeded 54/8/82
php artisan test                       → 230 passed, 4 skipped (1020 assertions)
php artisan test --parallel            → 230 passed, 4 skipped (4 processes)
composer pint --test                   → PASS
composer stan (Larastan level 8)       → No errors
npm run typecheck / test / build       → 0 errors / 72 passed / built
npm run e2e                            → 27 passed (axe clean)
gitleaks / npm audit / composer audit  → no leaks / 0 / 1 documented-ignored (since Phase 1)
```

### Deliberate PHPStan violation (shown failing, then removed)
Injected `MerchantBranch::withoutTenancy()->get();` into `BranchController::index`
(a non-allowed namespace) and ran `phpstan analyse --memory-limit=512M`:

```
38   Calling withoutTenancy() outside App\Domain\Tenancy or App\Domain\Platform
     bypasses tenant isolation (Plan §8.2).
     identifier: servana.tenancy.withoutTenancy
 [ERROR] Found 2 errors
```

Reverted the line; re-ran: `[OK] No errors`. The violation is **not committed**
(`git status` clean on the controller).

### §8.4 denied-case transcripts (from the green isolation suite)
- `GET /api/v1/branches/{ulid-of-merchant-B}` as admin of A → **404**; an
  `unauthorized_access` row (severity `high`, `context.model=MerchantBranch`,
  `context.attempted_id={ulid}`, `auditable_id=null`) is written.
- `GET /api/v1/staff/{foreign-ulid}` and `POST /api/v1/staff-invitations/{foreign-ulid}/resend`
  → **404** + `unauthorized_access` (`StaffProfile` / `StaffInvitation`).
- `GET /api/v1/branches/01JZZ…` (never existed) → **404**, **no** audit row.
- Front-office of branch A → `GET /branches/{branch-B-same-merchant}` → **403
  `no_branch_scope`**.
- Suspended merchant admin → any operational route → **403 `merchant_suspended`**;
  `/me` still **200** (SPA renders the suspended state).
- Branch Manager `POST /branches` (in scope, lacks `branches.create`) → **403
  `permission_denied`** (not 404).

### Route protection (unchanged surface, still gated)
`php artisan route:list --path=api/v1` shows every `branches/*`, `staff/*`,
`staff-invitations/*`, and `merchant/dashboard` route under
`auth:sanctum + ResolveTenantContext + EnsureMerchantActive` (+ `EnsureBranchScope`
on `{branch}` + `EnsurePermission` on mutating branch routes). Public surface
(magic-link, self-register, invitation accept) is unchanged.

## 5. Design decisions / Plan interpretation

1. **Cross-branch *staff/invitation* access is 403 (policy), not 404.** Plan §8.4's
   404-on-cross-branch rows are operational data (payments/queue). Staff lifecycle
   is an authority concern already enforced by the Phase 8 policy, so route binding
   scopes by **merchant only** (cross-merchant → 404 + audit) and branch authority
   stays the policy/`EnsureBranchScope` (403). This keeps the Phase 8 authority
   tests green. Cross-*merchant* staff/invitation is a clean 404 + audit.
2. **Global scopes filter only when a merchant context is present.** No-context
   reads (login eligibility, self-registration, platform, and out-of-request test
   assertions) stay unfiltered; the **`creating` hook** is what guarantees writes are
   never unscoped.
3. **`ResolveTenantContext::terminate()` resets context** after each request —
   production hygiene and prevents a previous request's merchant bleeding into an
   out-of-request Eloquent query in tests.

## 6. Residual risk
- Branch-owned models without `merchant_id` rely on the branch→merchant subquery for
  merchant isolation; if a future branch-owned table is route-bound directly, it
  must add `BelongsToMerchant` (or gain a `merchant_id`) so its binding audits.
- Only `unauthorized_access` is audited so far; full §5.18 event coverage + hash-chain
  verification is **Phase 19**.
- Future-resource §8.4 rows (invoices/payments/exports/personnel queue) are
  permanent **skipped** tests in `FutureResourceIsolationTest` naming Phases 16/17/18/19.
