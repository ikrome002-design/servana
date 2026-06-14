# Phase 6 Proof — Account & tenant model

**Branch:** `phase-6-account-tenant-model` (based on merged `main`: PR #1–#5)
**Date:** 2026-06-15
**Plan sections implemented:** §7.1 (identity/tenancy schema), §8.1 (tenant
resolution), §9.1 (eligibility checks 2 & 4), §27 Phase 6. **Scope:** §2.3,
§3.1, §3.2, §5.1.

---

## 1. Evidence of requirement (Prove the Problem)

Plan §27 Phase 6 objective: "merchants, self-registration, first-time setup" —
`RegisterMerchant` (user + merchant `pending_setup` + owner membership,
transactional), `CompleteFirstTimeSetup` (tier, profile, ≥1 branch, initial
Branch+HR invites with auto-branch-select, welcome mails, status → active),
`ResolveTenantContext`, `EnsureMerchantActive`. Scope §3.1/§3.2 fix the onboarding
model: the Merchant Administrator **self-registers**; the system creates the
tenant and makes that user the **Merchant Owner / Merchant Administrator**; there
is **no** Super Admin approval and **no** KYC.

Gaps closed (deferred by Phase 5, recorded in `docs/PROGRESS.md`): merchant
self-registration, automatic tenant creation, owner=admin ownership, membership
table for eligibility checks 2 & 4, pending→active transition, profile setup,
service-fee-tier selection.

---

## 2. Scope correction enforced (no Super-Admin-created onboarding)

- **No route** to create merchants / first admins / approve registration exists.
  Proof: `php artisan route:list` (see §5) shows only
  `merchant-registration/self-register` + `first-time-setup`; no
  `platform/merchants` write route, no `kyc`/`compliance`/`approve`/`activate`.
- **No KYC/compliance** fields or routes; `RegisterMerchantRequest` accepts only
  `owner_name`, `email`, `business_name`.
- **Owner == Administrator** (never split): the registering user gets one
  `merchant_users` row, role `merchant_admin`, status `active`.
- **Dashboard not held for approval**: merchant starts `pending_setup` and the
  owner reaches the dashboard immediately after completing first-time setup —
  no Super Admin gate. Asserted in `NoPlatformMerchantCreationTest`.

---

## 3. Files created / modified

### Schema (forward-only migrations; shipped migrations untouched)
- `..._add_platform_staff_to_users_table.php` — `is_platform_staff` (eligibility check 2).
- `..._create_merchants_table.php` — tenant root; status + service_fee_tier DB CHECKs.
- `..._create_merchant_profiles_table.php` — 1:1 profile (logo_path metadata only).
- `..._create_merchant_branches_table.php` — **minimal Phase 6 seam** (Phase 7 expands).
- `..._create_merchant_users_table.php` — membership; role + status DB CHECKs; unique(merchant,user).
- `..._create_merchant_status_histories_table.php` — append-only status trail.

### Backend — Domain
- `Merchants/Models/{Merchant,MerchantProfile,MerchantUser,MerchantStatusHistory}.php`.
- `Branches/Models/MerchantBranch.php` (minimal seam).
- `Merchants/Enums/{MerchantStatus,MerchantUserStatus,MerchantUserRole,ServiceFeeTier}.php`,
  `Branches/Enums/BranchStatus.php`.
- `Onboarding/Actions/{RegisterMerchant,CompleteFirstTimeSetup}.php`,
  `Onboarding/Data/FirstTimeSetupData.php`,
  `Onboarding/Services/FirstTimeSetupProgress.php`,
  `Onboarding/Notifications/StaffWelcomeNotification.php` (safe, tokenless).
- `Tenancy/{TenantContext,TenantContextResolver}.php`,
  `Tenancy/Exceptions/TenantAccessException.php`.

### Backend — HTTP
- Middleware: `ResolveTenantContext`, `EnsureMerchantActive`, `EnsureFirstTimeSetupAccess`.
- Controllers: `Onboarding/MerchantRegistrationController`, `Onboarding/FirstTimeSetupController`,
  `Merchant/MerchantDashboardController`.
- Requests: `Onboarding/{RegisterMerchantRequest,CompleteFirstTimeSetupRequest}`.
- Resources: `MerchantResource`, `MerchantMembershipResource`; reshaped
  `Auth/AuthenticatedUserResource` (tenant-aware bootstrap).
- `routes/api.php` (self-register public; setup + dashboard behind auth +
  ResolveTenantContext + per-route gates).

### Backend — wiring
- `User.php` (`is_platform_staff`, `merchantUsers()`, `activeMembership()`,
  `hasTenantAccess()`); `UserFactory` (`platformStaff()`); merchant factories.
- `LoginEligibilityService` (checks 2 & 4 enforced; 6 deferred);
  `MagicLinkController::verify` populates tenant context post-login.
- `AppServiceProvider` (scoped `TenantContext`); `config/servana.php`
  (`enforce_tenancy_eligibility` default true); `.env.example`.

### Frontend
- `pages/auth/RegisterMerchant.vue`, `pages/onboarding/FirstTimeSetup.vue`
  (4-step stepper), `pages/merchant/Dashboard.vue` (shell).
- `stores/onboardingStore.ts`; rewired `authStore.ts` / `merchantStore.ts`;
  `types/models.ts` (BootstrapPayload, Merchant, MerchantMembership, SetupState).
- `router/index.ts` (global `beforeEach` awaits bootstrap), `router/guards.ts`
  (`requiresPendingSetup`, pending→wizard), `routes/auth.ts`, `routes/merchant.ts`.
- `pages/auth/Login.vue` + `Verify.vue` (register link; tenant-aware post-login routing).

---

## 4. Test results (Quality Analyst — Demonstrate Resolution)

### Backend — onboarding + tenancy groups (Docker, PostgreSQL 16)
`docker compose exec app php artisan test --group=onboarding,tenancy` → **40 passed (277 assertions)**:
- `MerchantSelfRegistrationTest` (6) — transactional create; owner Magic Link;
  email normalize; validation; no second merchant for existing email; rate limit.
- `FirstTimeSetupTest` (7) — progress; full transactional completion (tier,
  profile, 1 branch, 2 invited memberships auto-assigned the branch, welcome
  emails); tier required; invalid tier; BM==HR rejected; staff==owner rejected;
  second setup blocked (409).
- `NoPlatformMerchantCreationTest` (3) — self-register route exists; no
  platform/super-admin merchant-creation route; no kyc/compliance/approve route.
- `LoginEligibilityMerchantMembershipTest` (8) — link sent for active membership /
  pending owner / platform staff; **no** link for no-membership / suspended /
  deactivated membership; consume denied after membership suspension; branch_manager
  without assignment still eligible (check 6 deferred).
- `ResolveTenantContextTest` (5) — /me carries merchant+membership+setup; pending
  reports setup required; platform staff → no merchant; a user sees only its own
  merchant; guest 401.
- `PendingSetupAccessTest` (8) — pending owner can reach setup, blocked from
  dashboard (`pending_setup_only`); active owner reaches dashboard, blocked from
  setup (`setup_already_completed`); suspended/deactivated → `merchant_suspended`;
  no membership → `no_tenant_context`; non-admin membership → setup denied.
- `MerchantRegistrationEnumerationTest` (3, Security) — identical responses for
  new vs existing email; no duplicate user/merchant; no link on duplicate.

### Backend — full suite
- `php artisan test` → **109 passed (521 assertions)**.
- `php artisan test --parallel` → **109 passed (4 processes)**.
- Phase 5 auth suite still green after the eligibility flip + /me reshape:
  `--group=auth` → **28 passed (118 assertions)** (tests updated to the new
  contract — see §7).

### Quality gates
- `composer pint -- --test` → **PASS (126 files)**.
- `composer stan` → **No errors (Larastan level 8)**.
- `npm run typecheck` → **0 errors**.
- `npm run test` (Vitest) → **51 passed** (10 files; +13 vs Phase 5).
- `npm run build` → **built** (Vite, no errors).
- `npm run e2e` (Playwright) → **20 passed** (auth 5 + foundation 11 + onboarding 4).
- `gitleaks detect --no-git --redact` → **no leaks**.
- `npm audit --audit-level=high` → **0 vulnerabilities**.
- `composer audit` → **1 documented-ignored** (CVE-2026-48019, carried since Phase 1).

### Frontend tests (Vitest) covering the required behaviours
- `RegisterMerchant.spec.ts` — renders fields; submits + uniform success state;
  maps server 422 onto fields.
- `FirstTimeSetup.spec.ts` — 4-step stepper; blocks advance until tier chosen;
  tier persists to store; full payload submit → routes to dashboard.
- `merchantStore.spec.ts` — empty start; active bootstrap; pending detection; reset.
- `authStore.spec.ts` — /me bootstrap includes merchant + setup; pending owner;
  logout clears merchant.

---

## 5. Live evidence (Docker stack, http://localhost:8080 / Mailpit :8025)

### Route list — no platform/super-admin merchant creation
```
POST       api/v1/merchant-registration/self-register
GET|HEAD   api/v1/merchant-registration/first-time-setup
POST       api/v1/merchant-registration/first-time-setup
GET|HEAD   api/v1/merchant/dashboard
GET|HEAD   api/v1/me
POST       api/v1/auth/magic-link  |  .../verify  |  .../logout
```
No `platform/merchants`, `kyc`, `compliance`, `approve`, or `activate` route.

### Self-registration (uniform 202)
```
POST /api/v1/merchant-registration/self-register
{"owner_name":"Proof Owner","email":"proof-owner@example.com","business_name":"Proof Salon"}
→ HTTP 202
{"message":"If this is a new business, we have sent a sign-in link to continue setup. Please check your email."}
```

### Mailpit — owner sign-in link, then Branch + HR welcome emails
After self-registration (1 message), then completing first-time setup for the
same merchant (CompleteFirstTimeSetup) the Mailpit total was **3**:
```
- Your Servana sign-in link            -> proof-owner@example.com
- You've been added to Proof Salon ... -> proof-bm@example.com
- You've been added to Proof Salon ... -> proof-hr@example.com
```
Setup result: `status=active tier=split_tier completed_at=2026-06-14 22:45:33`.
No raw Magic Link token appears in logs (Phase 5 `MagicLinkTokenSecurityTest`
still green; welcome emails are tokenless by design).

---

## 6. Tenant boundaries (Plan §8.1) — security assertions

| Case | Result | Code | Test |
|---|---|---|---|
| pending_setup owner → dashboard | denied | `pending_setup_only` | PendingSetupAccessTest |
| pending_setup owner → setup | allowed | — | PendingSetupAccessTest |
| active owner → dashboard | allowed | — | PendingSetupAccessTest |
| active owner → setup | denied | `setup_already_completed` | PendingSetupAccessTest |
| suspended/deactivated merchant → dashboard | denied | `merchant_suspended` | PendingSetupAccessTest |
| no membership → dashboard | denied | `no_tenant_context` | PendingSetupAccessTest |
| non-admin membership → setup | denied | `no_tenant_context` | PendingSetupAccessTest |
| /me for user of merchant A | sees only A | — | ResolveTenantContextTest |
| no-membership user → Magic Link | no email, uniform 202 | — | LoginEligibility… |
| suspended membership → consume | denied | `invalid_or_expired_token` | LoginEligibility… |

All denials use the Phase 3 structured error envelope.

---

## 7. Defects found & fixed (Bug Fix Protocol)

**Defect A — verify bootstrap missing tenant context.**
*Observed:* `MagicLinkConsumeTest` expected `data.merchant`/`membership` but they
were null. *Root cause:* the verify route runs outside the `ResolveTenantContext`
middleware group, so the resource read an empty context. *Fix:* extracted
`TenantContextResolver` (shared by the middleware) and called it in
`MagicLinkController::verify` after login. *Result:* consume bootstrap carries
merchant/membership/setup; test green.

**Defect B — `merchant_id` not persisted on profile.**
*Observed:* `SQLSTATE 23502 null value in column "merchant_id"` during
self-registration. *Root cause:* `RegisterMerchant` mass-assigns
`MerchantProfile` but `merchant_id` was absent from `$fillable`. *Fix:* added
`merchant_id` to `MerchantProfile::$fillable`. *Result:* all onboarding tests green.

**Defect C — router guards raced the async `/me` bootstrap.**
*Observed:* onboarding E2E failed — a logged-in pending owner navigating to
`/onboarding/first-time-setup` (or `/merchant`) was bounced because guards
evaluated before `bootstrap()` resolved on hard navigation. *Root cause:*
bootstrap ran only in `App.vue onMounted`, which races route guards. *Fix:* added
a global `router.beforeEach` that awaits `auth.bootstrap()` once before guards;
`App.vue` keeps an idempotent fallback. *Result:* E2E **20 passed**.

**Contract evolution (not a defect) — `/me` shape + eligibility flip.** Phase 5
explicitly flagged that Phase 6 would (a) enforce eligibility checks 2 & 4 and
(b) fill `/me`. Both landed: `AUTH_ENFORCE_TENANCY_ELIGIBILITY` now defaults true
and `/me` returns `{ user, merchant, membership, memberships, permissions, setup }`.
Phase 5 backend tests (Consume/Reused/Request/SessionLifecycle/TokenSecurity) and
frontend tests (authStore/Verify) were updated to the new contract; an
`eligibleOwner()` Pest helper builds an eligible identity.

---

## 8. Plan↔Scope tension resolved (branch timing)

Plan §27 Phase 6 requires CompleteFirstTimeSetup to create "≥1 branch", but Plan
§7.2 assigns the full `merchant_branches` entity to Phase 7. Per the Phase 6
brief, Phase 6 creates a **minimal `merchant_branches` table + model** (identity,
code, profile, status only) so the initial Branch/HR staff have a branch to be
assigned to (Scope §3.2 steps 3 & 5, with auto-select when one branch exists).
Phase 7 **expands** this table forward-only (operating hours, calendar, day
records, cash-ups, closure protection, branch CRUD, `branch_user_assignments`).
This is the only Plan tension and it is resolved without editing shipped migrations.

---

## 9. Skipped / deferred (owning future phase)

See `docs/PROGRESS.md` Phase 6 "Work skipped" for the full list with reason / phase
/ risk. Headlines: full branch lifecycle + staff invitation accept/revoke/resend +
branch-assignment enforcement (check 6) + session revocation on staff lifecycle →
**Phase 7**; role/permission registry → **Phase 8**; tenant trait hardening →
**Phase 9**; API conventions/pagination → **Phase 10**; logo upload → **Phase 23**;
tier pricing maths → **Phase 17/20**.

---

## 10. Commands

```bash
# Backend (Docker)
docker compose exec app php artisan migrate:fresh
docker compose exec app php artisan test                       # 109 passed
docker compose exec app php artisan test --parallel            # 109 passed (4 proc)
docker compose exec app php artisan test --group=onboarding,tenancy  # 40 passed
docker compose exec app composer pint -- --test                # PASS
docker compose exec app composer stan                          # level 8, 0 errors
docker compose exec app php artisan route:list                 # no platform merchant route

# Frontend (host)
npm run typecheck      # 0 errors
npm run test           # 51 passed
npm run build          # built
npm run e2e            # 20 passed

# Security (host / Docker)
gitleaks detect --source . --no-git --redact   # no leaks
npm audit --audit-level=high                    # 0
docker compose exec app composer audit          # 1 documented-ignored (CVE-2026-48019)
```

---

## 11. Context for Phase 7 (Branches, memberships, invitations)

Expand `merchant_branches` forward-only and add `branch_user_assignments`,
`staff_invitations`, `staff_profiles`, `staff_history`. Implement admin-only
branch CRUD, `EnsureBranchScope`, the invitation accept flow (token → activate the
`invited` merchant_users row → create branch assignment → status `active`),
`StaffLifecycleService` (suspend/deactivate revokes sessions + unused Magic Links),
and resend/revoke. Then implement Magic Link eligibility **check 6** in
`LoginEligibilityService::hasRequiredBranchAssignment` (currently always-true seam).
