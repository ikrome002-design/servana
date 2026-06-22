# Branches & Staff — Data Dictionary

Canonical DDL authority for the branch/staff tables (Plan §13.2, §13.6). Phase R5
(REM-TEN-001) hardens **tenant/branch ownership** on the existing as-built tables:
every branch-owned table gains a non-null `merchant_id` plus a composite foreign
key that forces `merchant_id` to match the parent branch. This documents the R5
target; it does not redesign Phase 6–9 behaviour or Phase 18B cash-up logic.

Ownership classifications and the central exemption list live in
`app/Domain/Tenancy/TenantOwnership.php` (read by the coverage tests).

Structural rule (Plan §2.1, §13.1): tenant-owned → `merchant_id`; branch-owned →
`merchant_id` + `branch_id`, with DB-level consistency.

---

## Branch-owned tables (merchant_id + branch_id; R5 adds merchant_id)

All five reference `merchant_branches(id)` via `branch_id` (existing, `ON DELETE
CASCADE`). R5 adds: `merchant_id bigint NOT NULL`, backfilled from the parent
branch; an index beginning with `merchant_id`; and a **composite FK
`(branch_id, merchant_id) → merchant_branches(id, merchant_id)`** (`ON DELETE
CASCADE ON UPDATE CASCADE`, named `{table}_branch_merchant_foreign`) so a row's
`merchant_id` can never disagree with its branch. Models use `BelongsToMerchant`
+ `BelongsToBranch`.

| Table | Key columns | Notable constraints (unchanged) | R5 ownership target |
|---|---|---|---|
| `branch_user_assignments` | `merchant_user_id`, `branch_id`, `status` | partial-unique `(merchant_user_id, branch_id) WHERE status='active'` | + `merchant_id` NN, index `(merchant_id, branch_id)`, composite FK |
| `branch_operating_hours` | `branch_id`, `weekday` | unique `(branch_id, weekday)` | + `merchant_id` NN, index, composite FK |
| `branch_calendar_exceptions` | `branch_id`, `date`, `type` | unique `(branch_id, date, type)` | + `merchant_id` NN, index, composite FK |
| `branch_day_records` | `branch_id`, `business_date`, `status` | unique `(branch_id, business_date)` | + `merchant_id` NN, index, composite FK |
| `branch_cash_ups` | `branch_id`, `branch_day_record_id`, `status` | money in integer minor units | + `merchant_id` NN, index `(merchant_id, branch_id)`, composite FK (Phase 18B owns behaviour) |

`merchant_branches` itself is **tenant-owned** (has `merchant_id`, no `branch_id`);
R5 adds `UNIQUE (id, merchant_id)` so it can be the target of the composite FKs.

---

## Tenant-owned tables (merchant_id; R5 adds where missing)

| Table | Ownership | merchant_id | R5 change |
|---|---|---|---|
| `merchant_profiles` | tenant_owned | present (Phase 6) | none |
| `merchant_status_histories` | tenant_owned (append-only) | present (Phase 6) | none |
| `merchant_users` | tenant_owned | present (Phase 6) | + `UNIQUE (id, merchant_id)` (composite-FK target) |
| `merchant_branches` | tenant_owned | present (Phase 7) | + `UNIQUE (id, merchant_id)` (composite-FK target) |
| `staff_profiles` | tenant_owned (+ `primary_branch_id`) | present (Phase 7) | + `UNIQUE (id, merchant_id)` (composite-FK target) |
| `staff_history` | tenant_owned (append-only) | **missing** | + `merchant_id` NN backfilled via `staff_profiles`; index `(merchant_id, created_at)`; composite FK `(staff_profile_id, merchant_id) → staff_profiles(id, merchant_id)` |
| `merchant_user_permission_overrides` | tenant_owned | **missing** | + `merchant_id` NN backfilled via `merchant_users`; index `(merchant_id)`; composite FK `(merchant_user_id, merchant_id) → merchant_users(id, merchant_id)` |

`staff_invitations` is **branch-owned** and already carries `merchant_id` +
`branch_id` (Phase 7) — no R5 change.

Append-only: `merchant_status_histories`, `staff_history` (history rows are never
mutated; R5 only adds the ownership column + constraint).

Route-binding exposure: only `merchant_branches` (`{branch}`) is directly
route-bound today and uses `BelongsToMerchant` (foreign ULID → 404 + audit). The
branch-owned child tables are reached via nested `{branch}`-scoped routes
(`EnsureBranchScope`), not by their own ULID.

---

## Migration order (forward-only; no shipped migration edited)

1. `…_add_composite_unique_to_tenant_parents` — `UNIQUE (id, merchant_id)` on
   `merchant_branches`, `staff_profiles`, `merchant_users`.
2. `…_add_merchant_id_to_branch_owned_tables` — expand → backfill → verify →
   NOT NULL → index → composite FK for the five branch tables.
3. `…_add_merchant_id_to_tenant_history_tables` — same for `staff_history` and
   `merchant_user_permission_overrides`.

Backfill is idempotent (re-running sets the same value); the migration fails
safely (actionable error) if any row would remain null/orphaned rather than
guessing a merchant.
