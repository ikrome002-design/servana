# Phase 8 — Roles & permissions — Proof

**Branch:** `phase-8-roles-permissions` (based on merged `main`, PR #1–#7).
**Plan sections implemented:** §10 (authorization/roles/permissions), §10.2
(authority boundaries), §10.3 (permission matrix), §10.4 (policies), §22.2
(audit recorder — minimal), §27 Phase 8.

---

## 1. Prove the problem (Manifesto §1)

Phase 7 enforced authority with **coarse inline role checks** in the Branch and
Staff controllers (`assertAdmin`, `assertManages`, `assertCanInvite` comparing
`MerchantUserRole`), and `/api/v1/me` returned `permissions: []`. The Plan
requires the §10.3 registry, `EnsurePermission`, policies, and an audit
foundation before the financial phases. Evidence of the gap:

- `git grep "assert"` in `app/Http/Controllers/Api/V1` → role-equality checks.
- `AuthenticatedUserResource` returned a hard-coded empty `permissions` array.
- No `permissions`/`roles`/`audit_logs` tables existed.

## 2. What was built (Manifesto §2–§3)

### Schema (forward-only; `merchant_users` untouched)
| Migration | Table |
|---|---|
| `2026_06_16_000001` | `permissions` (key, category, description, is_mutating) |
| `2026_06_16_000002` | `roles` (key, name, scope CHECK merchant\|platform, is_read_only) |
| `2026_06_16_000003` | `role_permission_assignments` (unique role+permission) |
| `2026_06_16_000004` | `merchant_user_permission_overrides` (effect CHECK grant\|deny, unique member+permission) |
| `2026_06_16_000005` | `audit_logs` (severity CHECK, hash chain, **append-only trigger**) |

### Registry & resolution
- `PermissionRegistry` — the canonical §10.3 matrix: **54 permission keys × 8
  roles**, with default grants (✓/scoped cells) and grantable overrides (◐ cells)
  declared explicitly. Two ambiguous source cells resolved by the §10.2 hard
  rules and documented in the class:
  - `platform_fees.* … ✓ (audit)` → audit's single ✓ is the **read** key
    `platform_fees.view` (audit is read-only everywhere).
  - `audit.flag` is audit's one in-domain write (explicitly granted); kept.
- `PermissionSeeder` materialises the registry (idempotent `updateOrCreate` +
  `sync`): **54 permissions, 8 roles, 82 default grants**.
- `PermissionResolver` — role defaults ± per-membership overrides; **deny beats
  grant**; suspended/deactivated/invited membership → **empty**; read-only
  `audit` can never gain a mutating key via override.
- `TenantContext` caches the resolved set per request (`setPermissions`);
  `TenantContextResolver` populates it; `/api/v1/me` returns `permissions[]`.

### Enforcement (backend boundary — Manifesto §3, guardrail §6.2)
- `EnsurePermission` middleware (`…:branches.create` etc.): missing key → **403
  `permission_denied`**. Wired on mutating Branch routes (create/archive →
  `branches.create`; profile/operating-hours → `branch.profile.manage`; day
  open/close → `day.open_close`). Foreign/out-of-scope branch still **404/403
  via `EnsureBranchScope` first** (no existence leak).
- Policies (Plan §10.4): `Merchant`, `MerchantBranch`, `MerchantUser`,
  `StaffInvitation`, `StaffProfile`, `BranchOperatingHour`, `BranchDayRecord`.
- Branch/Staff/Invitation controller `assert*` role checks **removed** and
  replaced by middleware + policies. Invitation target-role boundary (§3.2/§3.4)
  now derives from capabilities, not raw roles.

### Audit foundation (Plan §22.2)
- `AuditRecorder` contract + `DatabaseAuditRecorder` (SHA-256 hash chain, append
  inside a locked transaction). `audit_logs` is immutable — a PostgreSQL trigger
  raises on UPDATE/DELETE (guardrail §6.5).
- Recorded: `permission.override.created/updated/revoked` (high);
  `permission.override.denied_self_escalation`, `permission.write_denied`
  (warning).

### Override + preview API
- `POST`/`DELETE /api/v1/staff/{staff}/permissions` (admin merchant-wide; HR
  own-branch operational staff; anti-self-escalation; audited).
- `GET /api/v1/hr/permission-preview?role=…` and
  `GET /api/v1/staff/{staff}/permissions` (read-only; staff-manager gated).

### Frontend (UX only — never a boundary)
- `permissionStore` sourced from `/me`; `useCan` composable; `PermissionGate`
  component; HR `PermissionPreview` page; branch "Add branch" gated on
  `branches.create`.

## 3. Tests (Manifesto §4) — named per the assignment

`tests/Feature/Auth/`: `PermissionMatrixTest`, `AuthorityBoundariesTest`,
`HrSelfEscalationTest`, `AuditReadOnlyTest`, `PermissionOverrideAuditTest`,
`PermissionMiddlewareTest`, `PermissionPreviewTest`, `MePermissionsBootstrapTest`.

Key assertions proven:
- Every §10.3 cell matches the seeded grants — **zero mismatches** (iterates all
  54×8 cells against an independent transcription of the Plan).
- DB seeded grants == `PermissionRegistry` (no drift).
- Deny override beats a default grant; grant override only where ◐-grantable.
- HR cannot self-escalate (target=self → 403 + audited); HR cannot grant a key it
  does not hold; HR can manage an in-scope subordinate (deny) — proving authority.
- Audit role denied on branch create / staff suspend / override write; the
  override-write denial is audited (`permission.write_denied`).
- `audit_logs` UPDATE and DELETE both throw `QueryException` (append-only).
- Merchant Admin cannot configure services/commissions/payments; can invite only
  branch_manager/hr. Branch Manager cannot manage staff. Finance cannot bypass
  branch scope. Front Office lacks `payments.validate`/`receipts.reissue`.
  **Personnel holds no `exports.*` key and no contact-export route exists.**
- `/me` returns the role's resolved permissions; suspended membership → `[]`.

## 4. Demonstrate resolution (Manifesto §5)

```
docker compose exec app php artisan migrate:fresh --seed
  → 26 migrations OK (PostgreSQL 16; +5 for Phase 8)
  → PermissionSeeder: permissions=54 roles=8 assignments=82

php artisan test                       → 197 passed (959 assertions)
php artisan test --parallel            → 197 passed (4 processes)
php artisan test tests/Feature/Auth/   → 72 passed
composer pint -- --test                → PASS (236 files)
composer stan                          → No errors (Larastan level 8)
npm run typecheck                      → 0 errors
npm run test                           → 72 passed (17 files)
npm run lint                           → 0 errors (28 pre-existing stub warnings)
npm run build                          → built
npm run e2e                            → 27 passed (axe clean)
gitleaks detect --no-git --redact      → no leaks
npm audit --audit-level=high           → 0 vulnerabilities
composer audit                         → 1 documented-ignored advisory (GHSA-5vg9-5847-vvmq, since Phase 1)
```

The full seeded matrix is committed at
[`docs/proof/phase8-matrix.txt`](phase8-matrix.txt) (`PermissionMatrixTest`
regenerates it on every run).

### Denial transcript (representative)
- Branch Manager `POST /api/v1/branches` → `403 { error.code: permission_denied }`
  (lacks `branches.create`).
- Merchant Admin `PUT /api/v1/branches/{ulid}/operating-hours` →
  `403 permission_denied` (matrix assigns `branch.profile.manage` to Branch Manager).
- HR `POST /api/v1/staff/{self}/permissions` → `403 permission_denied` +
  `audit_logs` row `permission.override.denied_self_escalation`.
- Admin grants finance `refunds.approve` → `200`, `/me`-style resolved set gains
  the key, `audit_logs` row `permission.override.created` (severity `high`).

## 5. Defect found & fixed during verification (Bug Fix Protocol)

- **Observed problem:** 7 Phase 7 branch tests failed (403) after the matrix
  enforcement landed.
- **Evidence:** `BranchOperatingHoursTest`/`BranchDayLifecycleTest`/
  `BranchCrudTest` acted as Merchant Admin on profile/hours/day routes.
- **Root cause:** §10.3 assigns `branch.profile.manage` and `day.open_close` to
  **Branch Manager**, not Merchant Admin; Phase 7 had made these admin-only via a
  coarse role check. The new `EnsurePermission` correctly denies the admin.
- **Why root cause (not symptom):** the registry/seed match the Plan; the tests
  encoded the old coarse rule.
- **Correct fix:** updated the tests to act as an assigned Branch Manager and
  added explicit admin-denied cases (the new authorization is the assertion).
- **Tests:** `BranchOperatingHoursTest`, `BranchDayLifecycleTest`, `BranchCrudTest`.
- **Result:** all branch/hr tests green; full suite 197 passed.
- **Remaining risk:** the operating model (admin creates/archives; BM edits
  profile/hours/day) should be confirmed by the owner — flagged in PROGRESS risks.

## 6. Residual risk
- Most seeded keys are not yet attached to routes (their endpoints arrive in
  Phases 15–20); registry/seed/resolver are complete so those phases only add
  routes + policies.
- General unauthorized-attempt logging (`LogUnauthorizedAttempt`) and full §5.18
  audit coverage + chain verification are **Phase 9 / Phase 19** — only the
  override endpoints audit denials today.
- Tenant trait hardening + PHPStan tenancy rule → **Phase 9**.
