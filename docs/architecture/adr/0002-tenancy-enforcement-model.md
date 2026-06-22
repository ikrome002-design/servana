# ADR-002 — Tenancy & Branch Enforcement Model

- **Status:** Accepted (Phase R5, REM-TEN-001).
- **Date:** 2026-06-22.
- **Plan refs:** §2.1, §4 finding 8, §5.3, §8 (ADR-002), §9, §13.1–§13.6, §79 R5.

## Context and proven as-built defect

Phase 9 (PR #9) shipped tenant isolation for branch-owned tables by deriving the
merchant through a `branch_id → merchant_branches` subquery (`BranchScope`),
without a direct `merchant_id` column. Phase V confirmed (and `\d` re-proved on
this branch) that five branch-owned tables carried `branch_id` but **no
`merchant_id`**: `branch_user_assignments`, `branch_operating_hours`,
`branch_calendar_exceptions`, `branch_day_records`, `branch_cash_ups`. Two
tenant-owned tables also lacked it: `staff_history` and
`merchant_user_permission_overrides` (Plan §13.5 lists `merchant_id` for the
latter).

The subquery isolates reads, but the structure is incomplete: there is no
database-level guarantee that a row's merchant matches its branch, and any
future directly-route-bound branch-owned table without `merchant_id` could be
resolved by ULID outside merchant scope. R5 makes ownership **structural**.

## Decision

### Tenant-owned vs branch-owned
- **Tenant-owned** → non-null `merchant_id` (+ FK + index). Model uses
  `BelongsToMerchant` (global `MerchantScope`, create-time auto-fill, scoped route
  binding).
- **Branch-owned** → non-null `merchant_id` **and** `branch_id`. Model uses
  `BelongsToMerchant` + `BelongsToBranch` (global `BranchScope`).

The single source of truth for every existing table's classification (and every
exemption + reason) is `app/Domain/Tenancy/TenantOwnership.php`.

### `merchant_id` + `branch_id` structural rule & DB consistency
Every branch-owned table carries `merchant_id`, an index beginning with
`merchant_id`, a `merchant_id → merchants` FK (`RESTRICT` — merchants are
deactivated, never deleted), and a **composite FK
`(branch_id, merchant_id) → merchant_branches(id, merchant_id)`**
(`ON DELETE CASCADE ON UPDATE CASCADE`). The composite FK is the database
guarantee that `merchant_id` can never disagree with the parent branch —
application checks alone are insufficient (Plan §9). Parents
(`merchant_branches`, `staff_profiles`, `merchant_users`) gained
`UNIQUE (id, merchant_id)` to serve as the composite-FK target. History tables
use the same pattern against their authoritative parent
(`staff_history → staff_profiles`, `merchant_user_permission_overrides →
merchant_users`). The original single-column `branch_id` CASCADE FK is retained,
so branch deletion still cascades children.

### Global scopes
`MerchantScope` filters `merchant_id = TenantContext::merchantId()` when a
merchant is resolved (no-op without context — explicit predicates govern, and the
`creating` hook is what guarantees a write is never unscoped). `BranchScope`
restricts a branch-scoped role to its assigned branches, and a merchant-wide role
to every branch of the resolved merchant.

`branch_user_assignments` uses `BelongsToMerchant` **only**, NOT `BelongsToBranch`:
it is the branch-assignment authority that *resolves* `TenantContext::branchIds`,
so applying `BranchScope` to it would be circular. Its structural protection is
`merchant_id` + the composite FK; branch access to it is governed by
`EnsureBranchScope` / policies. This exemption is recorded centrally in
`TenantOwnership::MODELS`.

### Scoped ULID binding & isolation posture
`resolveRouteBinding` resolves a ULID within merchant scope: a foreign-tenant
ULID returns **404** (no existence leak) and writes an `unauthorized_access`
audit row. Same-tenant out-of-branch access keeps the documented **403**
(`no_branch_scope`) posture. R5 does not change this contract.

### Create-time ownership
`merchant_id` derives from the branch/parent, never from arbitrary request input.
Authenticated-route creations auto-fill `merchant_id` from `TenantContext`; the
no-context path (`AcceptStaffInvitation`, public invitee) sets it explicitly from
the invitation. The composite FK fails closed if a wrong merchant is ever
supplied.

### Job tenant context
Tenant-aware jobs bind a merchant context (Plan §8.3); `withoutTenancy()` is the
only sanctioned scope escape, restricted by `NoWithoutTenancyOutsidePlatformRule`
to Platform / Tenancy / audited-job code.

### Expand / backfill / constrain migration strategy
Forward-only, three migrations: (1) parent `UNIQUE (id, merchant_id)`; (2) branch
tables — add nullable `merchant_id` → backfill from the parent branch
(parameterized cursor, idempotent) → fail safely if any row is unresolved (never
guess a merchant) → NOT NULL → index → FKs; (3) the two history tables, same
pattern against their parent. No shipped migration edited; no destructive
production rollback relied upon (each `down()` only drops the additions).

### Static-analysis & schema-coverage controls
`TenantColumnCoverageTest` inspects the live PostgreSQL schema and fails on a
missing/nullable ownership column, a missing FK/index, a missing consistency FK,
or an unclassified table. `ModelTenancyTraitCoverageTest` fails when an owned
model lacks its required trait. `RouteBindingTenantSafetyTest` fails when a
route-bound owned model lacks `BelongsToMerchant`. The existing
`TenancyStaticAnalysisTest` (no `withoutGlobalScope` outside Tenancy/Platform, no
unscoped `find`, no concatenated raw SQL) is retained.

### Cross-cutting exception policy
`audit_logs` (per-merchant **and** platform chain) and `idempotency_keys`
(platform/webhook scopes) legitimately carry a nullable/forensic `merchant_id`
and remain EXEMPT — `idempotency_keys` stays cross-cutting infrastructure (R4),
not a tenant-owned model. Every exemption has a written reason in
`TenantOwnership::EXEMPT`.

## Rollout, forward repair & limitations
Additive and forward-only; the backfill is idempotent and fails safely on
orphaned data rather than guessing. Limitation: the migration assumes every
branch-owned row has a resolvable parent branch; genuinely orphaned data must be
resolved by an operator before re-running (the migration throws an actionable
error). R5 hardens **existing** tables only.

## R6 boundary & future-table obligations
Session/token/link/invitation/cache revocation and per-request membership/role
freshness are **R6** (REM-SESS-001), not R5. Every **future** tenant table must
add `merchant_id`; every future branch table must add `merchant_id` + `branch_id`;
the owning phase updates the data dictionary before the migration and extends
`TenantOwnership` only when a classification genuinely changes. No undocumented
exemption is permitted.
