# Phase R5 — Tenant & Branch Schema Hardening — Proof of Resolution

**Requirement:** REM-TEN-001 (C0, PRE_FEATURE) · Plan §2.1, §4 finding 8, §8
ADR-002, §9, §13.1–§13.6, §79 R5.
**Branch:** `phase-r5-tenant-branch-schema-hardening` · **Base:** merged `main`
`1288f48` (PR #16, R4). **Date:** 2026-06-22.

No private tenant data or secrets appear in this document.

---

## 1. Proven before-state (live PostgreSQL)

`merchant_id`/`branch_id` presence per table (before R5), from
`information_schema`:

```
branch_calendar_exceptions   merchant_id=f  branch_id=t   <- branch-owned, MISSING merchant_id
branch_cash_ups              merchant_id=f  branch_id=t   <- branch-owned, MISSING merchant_id
branch_day_records           merchant_id=f  branch_id=t   <- branch-owned, MISSING merchant_id
branch_operating_hours       merchant_id=f  branch_id=t   <- branch-owned, MISSING merchant_id
branch_user_assignments      merchant_id=f  branch_id=t   <- branch-owned, MISSING merchant_id
staff_history                merchant_id=f  branch_id=f   <- tenant history, MISSING merchant_id
merchant_user_permission_overrides  merchant_id=f         <- tenant-owned, MISSING merchant_id
staff_invitations            merchant_id=t  branch_id=t   <- already compliant (Phase 7)
audit_logs / idempotency_keys merchant_id=t (nullable)    <- cross-cutting (exempt)
```

Models: `BranchOperatingHour/CalendarException/DayRecord/CashUp` used
`BelongsToBranch` but NOT `BelongsToMerchant`; `BranchUserAssignment` used
neither. This matches Plan §4 finding 8 (branch-owned tables isolate via a
`branch→merchant` subquery, no `merchant_id` column).

---

## 2. Table ownership matrix

| Table | Domain | Classification | merchant_id | branch_id | Model traits | Route-bound | R5 action |
|---|---|---|---|---|---|---|---|
| `merchant_branches` | branches | tenant_owned | NN (P6) | — | BelongsToMerchant | yes (`{branch}`) | + UNIQUE(id,merchant_id) |
| `branch_user_assignments` | branches | branch_owned* | **+NN** | NN | BelongsToMerchant† | no | +merchant_id, composite FK |
| `branch_operating_hours` | branches | branch_owned | **+NN** | NN | BelongsToMerchant+Branch | no | +merchant_id, composite FK |
| `branch_calendar_exceptions` | branches | branch_owned | **+NN** | NN | BelongsToMerchant+Branch | no | +merchant_id, composite FK |
| `branch_day_records` | branches | branch_owned | **+NN** | NN | BelongsToMerchant+Branch | no | +merchant_id, composite FK |
| `branch_cash_ups` | branches | branch_owned | **+NN** | NN | BelongsToMerchant+Branch | no | +merchant_id, composite FK (Phase 18B owns behaviour) |
| `staff_invitations` | hr | branch_owned | NN (P7) | NN | BelongsToMerchant+Branch | no | none |
| `staff_profiles` | hr | tenant_owned | NN (P7) | (primary_branch_id) | BelongsToMerchant+Branch | no | + UNIQUE(id,merchant_id) |
| `staff_history` | hr | tenant_owned (append-only) | **+NN** | — | BelongsToMerchant | no | +merchant_id, composite FK |
| `merchant_profiles` / `merchant_status_histories` / `merchant_users` | merchants | tenant_owned | NN (P6) | — | BelongsToMerchant | no | merchant_users +UNIQUE(id,merchant_id) |
| `merchant_user_permission_overrides` | auth | tenant_owned | **+NN** | — | BelongsToMerchant | no | +merchant_id, composite FK |
| `merchants` | merchants | EXEMPT (tenant root) | — | — | — | no | none |
| `users`, `magic_login_tokens`, `mfa_*` | auth | EXEMPT (user-owned) | — | — | — | no | none |
| `permissions`, `roles`, `role_permission_assignments` | auth | EXEMPT (platform-global) | — | — | — | no | none |
| `audit_logs` | audit | EXEMPT (cross-cutting; platform chain) | nullable | nullable | — | yes (read) | none |
| `idempotency_keys` | idempotency | EXEMPT (cross-cutting; platform/webhook) | nullable | nullable | — | no | none |
| framework (`migrations`, `sessions`, `cache*`, `jobs*`, `failed_jobs`, `password_reset_tokens`, `personal_access_tokens`) | — | EXEMPT (framework) | — | — | — | — | none |

\* `branch_user_assignments` has branch-owned **columns** but is **BranchScope-exempt**
(it resolves `TenantContext::branchIds`; BranchScope would be circular).
† BelongsToMerchant only (documented in `TenantOwnership::MODELS`).

Central registry + reasons: `app/Domain/Tenancy/TenantOwnership.php`. Coverage is
asserted by `TenantColumnCoverageTest` ("no undocumented table").

---

## 3. Post-R5 schema evidence (`\d branch_day_records`)

```
 merchant_id | bigint | not null          <- added, NOT NULL
Indexes:
  branch_day_records_merchant_branch_index (merchant_id, branch_id)
Foreign-key constraints:
  branch_day_records_branch_id_foreign        (branch_id) → merchant_branches(id) CASCADE     [retained]
  branch_day_records_merchant_id_foreign      (merchant_id) → merchants(id) RESTRICT
  branch_day_records_branch_merchant_foreign  (branch_id, merchant_id) → merchant_branches(id, merchant_id) CASCADE
```

Parents gained the composite-FK target, e.g.
`merchant_branches_id_merchant_id_unique UNIQUE (id, merchant_id)`. All three R5
migrations are forward-only (`2026_06_23_000001/2/3`) and applied (`migrate:status`
= Ran). No shipped migration edited.

---

## 4. Backfill evidence (upgrade path)

`TenantBackfillMigrationTest` rolls the three R5 migrations back to the pre-R5
schema, seeds legacy rows across **two** merchants, re-applies R5, and asserts:
- each branch row's `merchant_id` == its branch's merchant (no cross-contamination);
- the `staff_history` row's `merchant_id` == its staff profile's merchant;
- zero null `merchant_id` after migration; `migrate` exit 0.

Post-`migrate:fresh --seed` null check (production seed path):

```
branch_user_assignments  merchant_id IS NULL = 0
staff_history            merchant_id IS NULL = 0
branch_day_records       merchant_id IS NULL = 0
```

The migration fails safely (actionable `RuntimeException`) if any row's parent is
orphaned — it never guesses a merchant.

---

## 5. Consistency-constraint evidence (DB rejects mismatch)

`TenantBranchConsistencyConstraintTest` (inserts via `DB::table`, bypassing model
auto-fill, so the DATABASE is proven to be the boundary):
- branch-owned row with `merchant_id` ≠ branch owner → `QueryException` (composite FK);
- matching `merchant_id` → accepted;
- `staff_history` with wrong merchant → rejected;
- `merchant_user_permission_overrides` with wrong merchant → rejected.

---

## 6. Model / trait coverage

`ModelTenancyTraitCoverageTest`: every tenant-owned model uses
`BelongsToMerchant`; every branch-scoped model also uses `BelongsToBranch`; the
registry stays consistent with the DB lists; and a deliberate offender (a
branch-owned model missing `BelongsToMerchant`) is shown to be caught.

`RouteBindingTenantSafetyTest`: every directly route-bound tenant/branch-owned
model (today: `merchant_branches`) uses `BelongsToMerchant`, so `resolveRouteBinding`
runs inside merchant scope.

---

## 7. Cross-tenant & cross-branch isolation

- `CrossTenantBranchOwnedModelTest`: a branch-owned model is constrained to the
  resolved merchant; create auto-fills `merchant_id` from context; create with no
  context throws `MissingTenantContext`.
- Existing suites still green (no contract change): `CrossTenantAccessTest`,
  `CrossBranchAccessTest`, `RouteBindingTest`, `BranchRouteBindingTest`,
  `UnauthorizedAccessAuditTest` (foreign-tenant ULID → 404 + `unauthorized_access`
  audit; same-tenant out-of-branch → 403 `no_branch_scope`).

---

## 8. Static / schema coverage result

`TenantColumnCoverageTest` (live schema), `ModelTenancyTraitCoverageTest`,
`RouteBindingTenantSafetyTest`, and the retained `TenancyStaticAnalysisTest` all
pass. A deliberate violation was demonstrated failing during development (a
branch-owned model without `BelongsToMerchant`, and an unclassified table) and
removed before commit; the trait test embeds a self-contained offender to prove
the rule bites.

---

## 9. Full quality results

```
php artisan migrate:status ............ all R5 migrations Ran
php artisan migrate:fresh --seed ...... DONE; 0 null merchant_id rows
php artisan test ...................... 370 passed, 4 skipped
php artisan test --parallel ........... pass (4 processes)
php artisan audit:verify-chain ........ OK (no chains on fresh DB)
composer validate --strict ............ valid
composer pint -- --test ............... clean (auto-fixed once)
composer stan (Larastan L8) ........... no errors
composer audit --locked ............... 0 advisories
npm run lint .......................... 0 errors (24 pre-existing warnings)
npm run typecheck ..................... clean
npm run test (vitest) ................. 77 passed
npm run build ......................... built
npm run e2e (playwright) .............. 30 passed (after 1 documented flake + 1 webServer-timeout rerun)
npm audit --audit-level=high .......... 0 vulnerabilities
gitleaks detect --no-git --redact ..... no leaks
docker build php.Dockerfile  --target dev ... exit 0
docker build nginx.Dockerfile --target prod . exit 0
```

### Initial failures (recorded, not erased)
- **Circular scope** — first run added `BelongsToBranch` to `BranchUserAssignment`;
  because that table resolves `TenantContext::branchIds`, `BranchScope` became
  circular and 12 auth/branch/HR tests failed. Root cause fixed: it uses
  `BelongsToMerchant` only (merchant isolation + composite FK), documented as a
  BranchScope-exempt authority table; rerun green.
- **Pest `toContain` is variadic** — passing a failure message as a 2nd arg made
  it a second required needle; removed the messages (no behaviour change).
- **Pint** auto-fixed import ordering/strict-types. **Larastan** clean.
- **e2e** the known `auth-magic-link` check-email flake + one webServer-startup
  timeout (dev-server port contended during a concurrent Docker build); clean
  rerun 30/30. R5 changed no frontend.

---

## 10. Work skipped & owning phases

- Session/token/Magic-Link/invitation/cache revocation + per-request
  membership/role freshness → **R6** (REM-SESS-001).
- Readiness/environment parity → **R7** (REM-OPS-001).
- Migration manifest + full route-classification/OpenAPI contract → **Phase 10**.
- Future tenant/branch tables' ownership columns → **each owning feature phase**.
- Phase 9 skipped isolation tests for invoices/payments/exports/queues/personnel →
  **Phases 16–19** (those tables do not exist yet).
- Phase 18B cash-up behaviour — only the existing seam's ownership columns were
  hardened; the workflow remains 18B.

---

## 11. Remaining risks

- The composite FK assumes every branch-owned row has a resolvable parent branch;
  genuinely orphaned legacy data must be resolved by an operator (the migration
  fails safely rather than guessing).
- `merchant_id` auto-fill on authenticated routes relies on `TenantContext`; the
  composite FK is the hard backstop if context and branch ever disagree
  (fail-closed FK violation, never a silent mis-assignment).
- Timestamps remain `timestamp(0)` (no tz), consistent with sibling tables;
  project-wide tz reconciliation is not owned by R5.

---

## 12. REM-TEN-001 status

`local_complete`. Promotion to `verified_complete` requires the R5 PR merged, CI
green, and required review or a truthful PR-specific governance exception — not
asserted here.
