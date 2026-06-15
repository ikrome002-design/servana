# Phase 7 Proof — Branches, memberships, invitations

**Branch:** `phase-7-branches-memberships-invitations` (based on merged `main`: PR #1–#6)
**Date:** 2026-06-15
**Plan sections implemented:** §7.1/§7.2 (branch + staff schema), §8.1/§8.2
(tenant + branch scope), §9.1 (eligibility check 6), §10.2 (authority), §27
Phase 7. **Scope:** §2.3, §3.3, §3.4.

---

## 1. Evidence of requirement (Prove the Problem)

Plan §27 Phase 7: branch entity+scope, staff invitations, lifecycle. DB:
`merchant_branches`, `branch_user_assignments`, `staff_invitations`,
`staff_profiles`, `staff_history`. Backend: branch CRUD (admin-only create),
`EnsureBranchScope`, atomic invitation accept (token → user+membership+assignment
+staff_profile, pending→active), `StaffLifecycleService` (suspend/deactivate =
sessions+tokens revoked), resend/revoke. Acceptance: duplicate active email/phone
blocked (DB partial uniques); suspend a logged-in user → next request denied.

Phase 6 deferred to Phase 7 (closed here): full branch CRUD/lifecycle,
`branch_user_assignments`, invitations + accept/resend/revoke,
`staff_profiles`/`staff_history`, branch-assignment eligibility (check 6),
session/token revocation, `EnsureBranchScope`.

---

## 2. Files created / modified

### Schema (forward-only; `merchant_branches` expanded, NOT recreated)
- `..._expand_merchant_branches_for_phase_7.php` — `status_reason`, `suspended_at`, `archived_at`, `updated_by`.
- `..._create_branch_user_assignments_table.php` — partial unique active per (member, branch).
- `..._create_staff_invitations_table.php` — hashed token; partial unique pending per (merchant,email,role,branch).
- `..._create_staff_profiles_table.php` — partial unique phone among active staff.
- `..._create_staff_history_table.php` — append-only.
- `..._create_branch_operating_hours_table.php`, `..._create_branch_calendar_exceptions_table.php`,
  `..._create_branch_day_records_table.php`, `..._create_branch_cash_ups_table.php` (seam).

### Backend — Domain
- `Branches/Models/{MerchantBranch (expanded), BranchUserAssignment, BranchOperatingHour, BranchCalendarException, BranchDayRecord, BranchCashUp}`.
- `Branches/Enums/{BranchUserAssignmentStatus, BranchDayStatus, CalendarExceptionType, CashUpStatus}`.
- `Branches/Services/{BranchClosureGuard, BranchDebtGate}`; `Branches/Actions/{CreateBranch, UpdateBranch, ArchiveBranch, OpenBranchDay, CloseBranchDay}`; `Branches/Exceptions/BranchClosureBlockedException`.
- `Hr/Models/{StaffInvitation, StaffProfile, StaffHistory}`; `Hr/Enums/{StaffInvitationStatus, StaffEmploymentType, StaffEmploymentStatus, StaffHistoryField}`.
- `Hr/Actions/{CreateStaffInvitation, AcceptStaffInvitation, ResendStaffInvitation, RevokeStaffInvitation}`; `Hr/Services/StaffLifecycleService`; `Hr/Notifications/StaffInvitationNotification`; `Hr/Exceptions/{StaffLifecycleException, InvalidStaffInvitationException}`.
- `Merchants/Models/MerchantUser` (branchAssignments, staffProfile, isBranchScoped, hasActiveBranchAssignment, activeBranchIds); `Tenancy/TenantContext` (+ branch scope + `reset()`); `Tenancy/TenantContextResolver` (reset on populate); `Auth/Services/LoginEligibilityService` (check 6); `Auth/Services/MagicLinkTokenService` (invalidateUnconsumedForEmail).

### Backend — HTTP
- Middleware `EnsureBranchScope`.
- Controllers `Branches/{BranchController, BranchOperatingHoursController, BranchDayController}`, `Hr/{StaffInvitationController, StaffInvitationAcceptController, StaffController}`.
- Requests `Branches/{CreateBranchRequest, UpdateBranchRequest, UpdateOperatingHoursRequest}`, `Hr/{CreateStaffInvitationRequest, AcceptStaffInvitationRequest}`.
- Resources `BranchResource, StaffInvitationResource, StaffProfileResource`; `Auth/AuthenticatedUserResource` (+ `branch_ids`); `routes/api.php`.

### Frontend
- Pages `branch/{BranchList, BranchCreate, BranchDetail, OperatingHours}`, `hr/{StaffList, StaffInvitations, StaffInvitationAccept, StaffProfile}`.
- Stores `branchStore` (CRUD + hours), `staffStore` (invite/resend/revoke/accept/lifecycle); `authStore` (branch_ids, isMerchantAdmin).
- Routes `branch.ts`, `hr.ts` (+ public `/staff/accept`); `types/models.ts`.

---

## 3. Security & branch-isolation properties

| Property | Mechanism | Test |
|---|---|---|
| Raw invitation token never stored | only `token_hash` (SHA-256) persisted | StaffInvitationTest, live §5 |
| Raw token never logged | token only on the notification/email | (no token in logs; redacted channel) |
| 72-hour expiry | `expires_at = now()+72h` | StaffInvitationTest |
| Atomic accept | conditional `UPDATE … WHERE status=pending AND expires_at>now()` returning 1 | StaffInvitationAcceptTest |
| Expired/revoked/accepted not acceptable | claim matches 0 rows → uniform 422 | StaffInvitationAcceptTest |
| Duplicate pending blocked | partial unique index + request check | StaffInvitationTest |
| Resend rotates token, no duplicate row | `rotateAndSend` increments count in place | StaffInvitationResendRevokeTest |
| Check 6: no assignment → no Magic Link | `hasRequiredBranchAssignment` | BranchAssignmentEligibilityTest |
| Check 6: assignment → Magic Link sent | active `branch_user_assignment` | BranchAssignmentEligibilityTest |
| Admin needs no assignment | `isBranchScoped()` false for admin | BranchAssignmentEligibilityTest |
| Foreign branch ULID → 404 (no leak) | EnsureBranchScope merchant check | BranchRouteBindingTest |
| Missing assignment → 403 `no_branch_scope` | EnsureBranchScope | BranchRouteBindingTest |
| Duplicate active staff phone/email blocked | partial unique index / users.email unique | DuplicateActiveStaffTest |
| Suspend/deactivate revokes sessions + links | `StaffLifecycleService::revokeAccess` | StaffLifecycleRevocationTest |
| Suspended staff → next request denied | membership inactive → no tenant context | StaffSuspensionTest |
| Sole active admin cannot be orphaned | `guardNotSoleAdmin` | StaffSuspensionTest |

---

## 4. Test results (Quality Analyst)

### Backend — branches/hr/isolation groups (Docker, PostgreSQL 16)
`docker compose exec app php artisan test --group=branches,hr,isolation` → **51 passed (146 assertions)**:
- `BranchCrudTest` (6) — admin create; non-admin 403; duplicate code 422; own-merchant list; update; foreign branch 404.
- `BranchClosureGuardTest` (4) — clean archive; unclosed-day blocker; cash-up-discrepancy blocker; debt-gate consulted (0).
- `BranchOperatingHoursTest` (3) — upsert; idempotent per weekday; weekday-range validation.
- `BranchDayLifecycleTest` (3) — open; close; reopen (single row per date).
- `StaffInvitationTest` (5) — invite+email; hash-only storage; admin role boundary; duplicate-pending; foreign-branch reject.
- `StaffInvitationAcceptTest` (5) — atomic provisioning; unknown/expired/revoked rejected; cannot accept twice.
- `StaffInvitationResendRevokeTest` (4) — resend rotates+counts; revoke blocks accept; cannot resend accepted; foreign 404.
- `StaffSuspensionTest` (6) — suspend; lose access next request; reactivate; activate needs assignment; sole-admin guard; deactivate-with-second-admin.
- `StaffLifecycleRevocationTest` (2) — sessions+links revoked; assignments+pending-invites revoked.
- `BranchAssignmentEligibilityTest` (4, auth) — check 6 cases.
- `BranchRouteBindingTest` (5, isolation) — foreign/unknown 404; admin all branches; unassigned 403; assigned 200.
- `DuplicateActiveStaffTest` (4, security) — phone/email/membership uniqueness; phone reuse after inactive.

### Backend — full suite
- `docker compose exec app php artisan test` → **160 passed (817 assertions)**.
- `docker compose exec app php artisan test --parallel` → **160 passed**.
- Phase 5/6 suites still green after check-6 enforcement + `/me` `branch_ids`
  (the Phase 6 "branch_manager without assignment" eligibility test was updated
  to the new Phase 7 contract — see §7).

### Quality gates
- `composer pint -- --test` → **PASS (199 files)**.
- `composer stan` → **No errors (Larastan level 8)**.
- `npm run typecheck` → **0 errors**.
- `npm run test` (Vitest) → **71 passed** (17 files; +20 vs Phase 6).
- `npm run build` → **built**.
- `npm run e2e` (Playwright) → **27 passed** (auth 5 + branches/staff 7 + foundation 11 + onboarding 4).
- `gitleaks detect --no-git --redact` → **no leaks**.
- `npm audit --audit-level=high` → **0**.
- `composer audit` → **1 documented-ignored** (CVE-2026-48019, carried since Phase 1).

### Frontend tests added
- `branchStore.spec` (CRUD + operating hours), `staffStore.spec` (invite/resend/revoke/accept/suspend).
- `BranchList.spec` (own-merchant list + admin action), `BranchCreate.spec` (validate+submit+server errors).
- `StaffInvitations.spec` (validate+submit), `StaffInvitationAccept.spec` (valid/invalid/no-token), `StaffList.spec` (status badges).

---

## 5. Live evidence (Docker stack, Mailpit :8025)

### Route list — branch + staff surface; no platform branch-creation route
```
GET|POST   api/v1/branches                              (index/store)
GET|PATCH  api/v1/branches/{branch}                     (show/update)
POST       api/v1/branches/{branch}/archive
GET|PUT    api/v1/branches/{branch}/operating-hours
POST       api/v1/branches/{branch}/day/{open,close}
GET|POST   api/v1/staff-invitations                     (index/store)
POST       api/v1/staff-invitations/accept              (public)
POST       api/v1/staff-invitations/{invitation}/{resend,revoke}
GET        api/v1/staff , api/v1/staff/{staff}
POST       api/v1/staff/{staff}/{suspend,activate,deactivate}
```

### Live invitation → Mailpit
Created an active merchant + admin + branch, then ran `CreateStaffInvitation`:
```
INVITATION ulid=01KV5GTYY7… status=pending token_hash_len=64
DB row: { …, "token_hash":"578926e3…f868f", "status":"pending", … }   ← only the hash, no raw token
Mailpit total=1
  Subject: You're invited to join Proof Salon P7 on Servana  ->  p7-manager@example.com
  Body contains accept link: /staff/accept?token=<raw>   (raw token only in the email)
```
This proves: invitation email delivered through the real mail pipeline; the raw
token travels only in the emailed link; the database stores only the 64-char hash.

### Suspended staff loses access (browser-equivalent, feature-proven)
`StaffSuspensionTest > makes a suspended staff member lose merchant access on the
next request`: an assigned Front Office user GETs `/api/v1/branches` → 200; after
`StaffLifecycleService::suspend` their next `/api/v1/branches` → **403
`no_tenant_context`** (membership no longer active). Plus
`StaffLifecycleRevocationTest` asserts the DB session rows are deleted and unused
Magic Links invalidated. (The SPA preview has no backend, so the stubbed E2E
covers the UI flows; this server-side behaviour is the authoritative proof.)

---

## 6. Branch closure protection (Scope §3.3) — explicit, never silent

`BranchClosureGuard::blockers()` returns a named list. Enforced now:
`unclosed_branch_day` (reads `branch_day_records`), `unresolved_cash_up_discrepancy`
(reads `branch_cash_ups`). Explicit named stubs returning `false` until their
owning phase: `active_queue_entries`/`in_progress_sessions`/
`pending_appointment_check_ins` (Phase 16), `unpaid_invoices` (17),
`pending_payment_validations`/`unissued_receipts` (18), plus
`outstanding_platform_fee_debt` via `BranchDebtGate` (Phase 20). Tests prove the
two live blockers fire and the debt gate is consulted (returns 0).

---

## 7. Defects found & fixed (Bug Fix Protocol)

**Defect A — DB-default status not hydrated on create.** *Observed:*
`BranchResource`/`StaffInvitationResource` threw "Attempt to read property value
on null" (`$model->status->value`) on a freshly created row. *Root cause:* the
`status` DB default is applied server-side, so a just-`create()`d model instance
has no `status` in memory until refresh. *Fix:* mirror the default via
`protected $attributes = ['status' => …]` on `MerchantBranch` and `StaffInvitation`.
*Result:* create responses serialize correctly.

**Defect B — stale `TenantContext` across a reused scoped instance.** *Observed:*
`StaffSuspensionTest` — after suspension a second request still returned 200.
*Root cause:* `TenantContext` is a `scoped` binding; the test client reuses the
container across sub-requests, and `TenantContextResolver::populate` only *set*
context (never cleared it), so a stale merchant from request 1 lingered. *Fix:*
added `TenantContext::reset()` and call it at the start of `populate()` so the
context is rebuilt each resolution. *Result:* suspended user → 403 on next request.
(Latent in prod too — any double-resolve would have leaked.)

**Contract change (not a defect).** Phase 6's "branch_manager without a branch
assignment still gets a link (check 6 deferred)" test was updated to assert **no
link** now that check 6 is enforced; detailed coverage in
`BranchAssignmentEligibilityTest`.

---

## 8. Skipped / deferred (owning future phase)

See `docs/PROGRESS.md` Phase 7 "Work skipped". Headlines: permission registry →
**Phase 8**; tenant trait hardening + PHPStan rule → **Phase 9**; API pagination →
**Phase 10**; real closure blockers (queue/session/appointment/invoice/payment/
receipt) → **16–18**; branch-fee debt → **20**; cash-up workflow → **18**;
profile-photo upload → **23**.

---

## 9. Commands

```bash
# Backend (Docker)
docker compose exec app php artisan migrate:fresh
docker compose exec app php artisan test                  # 160 passed
docker compose exec app php artisan test --parallel       # 160 passed
docker compose exec app php artisan test --group=branches,hr,isolation  # 51 passed
docker compose exec app composer pint -- --test           # PASS (199 files)
docker compose exec app composer stan                     # level 8, 0 errors
docker compose exec app php artisan route:list            # branch/staff routes; no platform branch route

# Frontend (host)
npm run typecheck   # 0 errors
npm run test        # 71 passed
npm run build       # built
npm run e2e         # 27 passed

# Security
gitleaks detect --source . --no-git --redact   # no leaks
npm audit --audit-level=high                     # 0
docker compose exec app composer audit           # 1 documented-ignored (CVE-2026-48019)
```

---

## 10. Context for Phase 8 (Roles & permissions)

Build the §10.3 registry (`roles`, `permissions`, `role_permission_assignments`,
`merchant_user_permission_overrides`) + `PermissionSeeder`, TenantContext
permission resolution (cached per request), `EnsurePermission` middleware, and
model policies. Then replace the coarse inline `assert*` role checks in the
Branch/Staff controllers with permission gates and populate `permissions` in
`/api/v1/me`.
