# Billing and Wallet Integration — Data Dictionary (Plan §13.9–§13.11, §59; Phases 20A–20D-W, 20E, 20F)

> **Architecture specification only.** This document defines future tables and columns for
> the Servana↔Wallet integration boundary. **No business migrations ship in the v4 plan-adoption
> PR.** Migrations are authored in their owning phases (20A, 20B, 20D-W) after Gate W where
> required.
>
> **Ownership:** Servana owns business-billing truth (plans, subscriptions, invoices,
> allocations, billing-status recovery). **Wallet owns money-movement truth** (provider
> credentials, STK/C2B, raw payloads, provider reconciliation, ledger postings). Servana never
> calls Safaricom/Daraja directly (ADR-012; Plan §9 rule 20).
>
> The v3 file `billing-and-mpesa.md` was never shipped; this file replaces that planned name.
> Historical `mpesa_callback_inbox` / `mpesa_reconciliation_events` names were removed before
> build (SUP-02).

---

## Controlling sources

- Plan §13.9–§13.11, §25.4 (subscription invoice + payment attempt machines), §49, §56–§58,
  §80.1–§80.2 (Gate W), ADR-012, ADR-014, ADR-015
- Plan §59 + §80 (Phase 20F compensation-plan setup / commission rules; Correction 19), Scope
  §12.1–§12.9 and §18.3 — the Phase 20F section below. Compensation configuration is
  HR-owned and **creates no earned financial fact**; the compensation subject `staff_profiles`
  is documented in `docs/architecture/data-dictionary/branches-and-staff.md`.
- `docs/architecture/adr/0012-wallet-by-citrus-payment-orchestration-boundary.md`
- `Wallet_by_Citrus_Platform_Project_Scope.md` (**not present in this repository** — contract
  pins deferred to External Gate W)

---

## Phase 20A — Platform billing configuration (no Wallet dependency)

> **Status: BUILT in Phase 20A.** The tables below ship as forward-only migrations on
> PostgreSQL 16 in the Phase 20A PR (branch `phase-20a-billing-catalogue-settings`). All five are
> **platform-scoped**: they carry **no `merchant_id` and no `branch_id`**, are registered as
> `PLATFORM_OWNED` in `App\Domain\Tenancy\Support\TenantOwnership`, and are reached only through
> Super-Admin `platform_mutation` routes (mandatory MFA + step-up). Money is **integer minor units**
> via the `Money` value object — never float. Timestamps are UTC; effective-date business logic is
> `Africa/Nairobi`. ULIDs are the only public identifiers; the internal bigint `id` is never exposed.
> Controlling sources: Plan §13.9, §13.10, §20, §47, §49, §50, ADR-011 (price sole-source), ADR-005
> (round-half-up).

| Table | Owner phase | Purpose |
|---|---|---|
| `platform_billing_settings` | 20A | Billing mode, trial/grace defaults, currency, effective dating |
| `subscription_plans` | 20A | Plan catalogue (non-price metadata) |
| `subscription_plan_prices` | 20A | Sole price source (ADR-011) |
| `plan_entitlements` | 20A | Entitlement limits per plan |
| `preferred_personnel_fee_rules` | 20A | Effective-dated fixed/percentage rules; expand-and-contract from legacy `services.preferred_personnel_fee_minor` |

Canonical enums (single vocabulary across PHP enum ↔ PG CHECK ↔ API validation ↔ OpenAPI ↔
generated TS ↔ seed/fixtures ↔ screen options ↔ audit context; parity-guarded):

- **`BillingMode`** (`App\Domain\Billing\Enums\BillingMode`): `fixed_amount`,
  `percentage_on_merchant_client_invoice`, `fixed_amount_plus_percentage_on_merchant_client_invoice`.
  Default launch mode is `fixed_amount` (§50).
- **`BillingInterval`** (`App\Domain\Billing\Enums\BillingInterval`): `weekly`, `bi_weekly`,
  `monthly`, `quarterly`, `annual` (§47/§49). The interval type is defined in 20A and consumed by
  20B renewal/date math — 20A creates **no** renewal behaviour.

---

### `platform_billing_settings` (Plan §13.9; migration `2026_07_10_000001`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK; never exposed |
| `ulid` | char(26) | no | public id + route key; `UNIQUE` |
| `billing_mode` | varchar | no | CHECK ∈ `BillingMode` (3 canonical values) |
| `default_trial_days` | int | no | `CHECK (default_trial_days >= 0)` |
| `grace_days` | int | no | `CHECK (grace_days >= 0)` |
| `currency` | char(3) | no | `CHECK (currency = upper(currency))`; KES at launch |
| `updated_by` | bigint | no | FK `users(id)` ON DELETE RESTRICT (actor) |
| `effective_from` | timestamptz | no | version validity start; a new active version is a new row |
| `settings` | jsonb | no | documented keys only; `CHECK (jsonb_typeof(settings) = 'object')`; default `'{}'` |
| `created_at`/`updated_at` | timestamptz | no | |

- **Single effective active row at an instant.** Versioned by `effective_from`; the *current* settings
  row is the one with the greatest `effective_from <= now()`. Enforced by (a) a partial unique index
  guaranteeing at most one row per `effective_from` instant and (b) the update action inserting a new
  version rather than mutating a prior one. Historical versions are retained (audit/history);
  superseded versions are never edited.
- **Documented `settings` keys only** — no undocumented financial rule may hide in arbitrary JSON.
  Financial primitives (mode, trial, grace, currency) are first-class columns, not JSON.
- **Immutability:** a shipped version's financial fields are never overwritten; changes append a new
  effective-dated version.
- **Indexes:** `UNIQUE(ulid)`; `UNIQUE(effective_from)`; index `(effective_from DESC)` for
  current-version lookup.
- **Audit:** `platform_billing.settings_changed` (high). **Retention:** permanent (financial config
  history). **Factory:** `PlatformBillingSettingsFactory` (fixed_amount default; synthetic trial/grace).
- **Positive tests:** current-version resolves to greatest `effective_from<=now`; update creates a new
  version leaving history intact; canonical mode/currency accepted. **Negative:** second row at the
  same `effective_from` rejected; non-canonical `billing_mode` rejected (DB CHECK); lowercase currency
  rejected; negative trial/grace rejected; non-object `settings` rejected.

### `subscription_plans` (Plan §13.9; migration `2026_07_10_000002`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | public id + route key; `UNIQUE` |
| `key` | varchar | no | stable machine key; `UNIQUE` |
| `name` | varchar | no | display name |
| `description` | text | yes | |
| `tier` | varchar | yes | non-price tier metadata |
| `metadata` | jsonb | no | non-price limit metadata; `CHECK (jsonb_typeof(metadata)='object')`; default `'{}'` |
| `status` | varchar | no | CHECK ∈ (`active`,`retired`) |
| `sort_order` | int | no | default 0 |
| `created_at`/`updated_at` | timestamptz | no | |

- **No monetary/price columns** — price lives solely in `subscription_plan_prices` (ADR-011). No
  production commercial names/limits are invented here; fixtures use explicit synthetic values.
- **Retirement preserves history:** `active → retired` is a status change; the row and its prices are
  never deleted; retired plans keep prices/entitlements for historical/subscription reference (20B).
- **Indexes:** `UNIQUE(ulid)`, `UNIQUE(key)`, index `(status, sort_order)`.
- **Audit:** `platform_plan.created` (info), `platform_plan.metadata_changed` (info),
  `platform_plan.retired` (high). **Retention:** permanent. **Factory:** `SubscriptionPlanFactory`.
- **Positive:** create/update metadata/retire; retire preserves prices+entitlements. **Negative:**
  duplicate `key` rejected; non-canonical `status` rejected; no price column exists (schema test).

### `subscription_plan_prices` (Plan §13.9; ADR-011 sole price source; migration `2026_07_10_000003`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | public id + route key; `UNIQUE` |
| `plan_id` | bigint | no | FK `subscription_plans(id)` ON DELETE RESTRICT |
| `amount_minor` | bigint | no | `CHECK (amount_minor >= 0)`; integer minor units |
| `currency` | char(3) | no | `CHECK (currency = upper(currency))` |
| `billing_interval` | varchar | no | CHECK ∈ `BillingInterval` (5 values) |
| `effective_from` | date | no | inclusive start |
| `effective_to` | date | yes | exclusive end; null = open-ended |
| `created_by` | bigint | no | FK `users(id)` ON DELETE RESTRICT |
| `created_at`/`updated_at` | timestamptz | no | |

- **Sole price source (ADR-011).** No price is duplicated onto `subscription_plans`.
- **No overlapping effective ranges** per `(plan_id, billing_interval, currency)` — enforced by a
  PostgreSQL `EXCLUDE USING gist` constraint on
  `(plan_id WITH =, billing_interval WITH =, currency WITH =, daterange(effective_from, effective_to, '[)') WITH &&)`
  (requires the `btree_gist` extension). `CHECK (effective_to IS NULL OR effective_to > effective_from)`.
  Adjacent ranges (`[a,b)` then `[b,c)`) are permitted; only true overlaps are rejected.
- **Deterministic current-price resolution:** the row for a `(plan, interval, currency)` whose
  `daterange` contains the query date (default today, `Africa/Nairobi`). Historical prices are
  preserved; changing a price **creates a new effective-dated row** (or a documented lifecycle
  transition) — an effective historical price is **never** destructively edited, and no issued
  financial snapshot is overwritten (20B invoices capture `price_id` at issuance).
- **Concurrency:** create/schedule run in a transaction taking `SELECT … FOR UPDATE` on the plan row
  before the pre-overlap check; the `EXCLUDE` constraint is the final arbiter so two concurrent
  inserts cannot both create an overlap (loser → `409 plan_price_overlap`).
- **Indexes:** `UNIQUE(ulid)`; GiST exclusion (above); index `(plan_id, billing_interval, currency, effective_from)`.
- **Audit:** `platform_plan_price.created` (high), `platform_plan_price.scheduled` (high),
  `platform_plan_price.cancelled` (high). **Retention:** permanent.
  **Factory:** `SubscriptionPlanPriceFactory` (uppercase currency; non-overlapping ranges by default).
- **Positive:** all five intervals; current + historical resolution; adjacent ranges allowed;
  cancel a future (not-yet-effective) price. **Negative:** overlapping range rejected by PG; lowercase
  currency rejected; negative amount rejected; `effective_to <= effective_from` rejected; destructive
  edit of an effective price is not an available action (source-scan/behaviour test).

### `plan_entitlements` (Plan §13.9, §20; migration `2026_07_10_000004`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `plan_id` | bigint | no | FK `subscription_plans(id)` ON DELETE RESTRICT |
| `entitlement_key` | varchar | no | e.g. `merchant.branch.count`, `personnel.sms` |
| `limit_int` | int | yes | null = unlimited when `enabled`; `CHECK (limit_int IS NULL OR limit_int >= 0)` |
| `enabled` | boolean | no | default false |
| `created_at`/`updated_at` | timestamptz | no | |

- `UNIQUE(plan_id, entitlement_key)`. This is the §20 resolver/gate substrate.
- **No `merchant_subscriptions` in 20A.** The merchant→plan binding (which plan a merchant is on) is
  Phase 20B; the 20A resolver takes the plan through an explicit `PlanContextResolver` interface with a
  documented 20B handoff and **fabricates no subscription rows**. The 20A resolver still proves:
  enabled entitlement allows; disabled/absent denies; limit boundary (at/over) behaviour;
  downgrade-compatible no-data-loss semantics at the service level.
- **Gate attachment:** the entitlement gate is attached only to already-existing routes whose canonical
  permission metadata carries an active `entitlement_key`; no future entitlement is bolted onto an
  unrelated route to demonstrate the gate.
- **Indexes:** `UNIQUE(plan_id, entitlement_key)`; index `(plan_id)`.
- **Audit:** `platform_plan_entitlement.changed` (info). **Retention:** permanent.
  **Factory:** `PlanEntitlementFactory`.
- **Positive:** enabled allows; at-limit boundary; per-plan isolation. **Negative:** disabled denies;
  absent denies; over-limit denies; duplicate `(plan,key)` rejected; negative limit rejected.

### `preferred_personnel_fee_rules` (Plan §13.10, launch-active; migration `2026_07_10_000005`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | public id + route key; `UNIQUE` |
| `calculation_type` | varchar | no | CHECK ∈ (`fixed_amount`,`percentage`) |
| `fixed_amount_minor` | bigint | yes | required iff fixed; `CHECK (fixed_amount_minor IS NULL OR fixed_amount_minor >= 0)` |
| `percentage_basis_points` | int | yes | required iff percentage; `CHECK (percentage_basis_points IS NULL OR percentage_basis_points BETWEEN 0 AND 10000)` |
| `currency` | char(3) | yes | required iff fixed; `CHECK (currency IS NULL OR currency = upper(currency))` |
| `calculation_basis` | varchar | no | CHECK ∈ (`service_item_net_amount`,`service_item_gross_amount`) |
| `scope` | varchar | no | CHECK ∈ (`platform_default`,`service`) |
| `service_id` | bigint | yes | FK `services(id)` ON DELETE RESTRICT; required iff scope=`service`, null iff `platform_default` |
| `effective_from` | date | no | inclusive start |
| `effective_to` | date | yes | exclusive end |
| `status` | varchar | no | CHECK ∈ (`draft`,`scheduled`,`active`,`superseded`,`expired`,`cancelled`) |
| `created_by` | bigint | yes | FK `users(id)` ON DELETE RESTRICT; **NULL = system/migration legacy backfill** (…000006, no acting user); interactive create actions always set it |
| `approved_by` | bigint | yes | FK `users(id)` ON DELETE RESTRICT |
| `approved_at` | timestamptz | yes | |
| `change_reason` | text | no | non-empty; `CHECK (length(btrim(change_reason)) > 0)` |
| `created_at`/`updated_at` | timestamptz | no | |

- **Value-shape CHECKs (DB-authoritative):** fixed ⇒ `fixed_amount_minor` + `currency` present and
  `percentage_basis_points` null; percentage ⇒ `percentage_basis_points` present and
  `fixed_amount_minor` + `currency` null (exactly one calculation value).
- **Scope CHECKs:** `platform_default` ⇒ `service_id` null; `service` ⇒ `service_id` not null.
- **No overlapping active/scheduled ranges** per scope (and per `service_id` when scope=`service`) —
  `EXCLUDE USING gist` on
  `(scope WITH =, coalesce(service_id,0) WITH =, daterange(effective_from, effective_to, '[)') WITH &&) WHERE (status IN ('active','scheduled'))`.
  `CHECK (effective_to IS NULL OR effective_to > effective_from)`.
- **Immutability + supersede:** an `active` rule's monetary terms are immutable; a change **supersedes**
  with a new version (`active → superseded`) — never an in-place edit. `approved_at`/`approved_by` set
  at approval/activation. Cancellation of a not-yet-effective `draft`/`scheduled` rule → `cancelled`.
- **Percentage arithmetic:** round-half-up to integer minor units (ADR-005) on the resolved item basis.
- **Resolution precedence:** at finalization the effective rule is the `active` rule whose daterange
  contains the finalization date, preferring `scope=service` (matching `service_id`) over
  `platform_default`. Snapshotted onto the invoice; **existing finalized invoices are never
  recalculated** when a rule changes.
- **Lifecycle spec:** `docs/architecture/state-machines/preferred-personnel-fee-rule.md`.
- **Indexes:** `UNIQUE(ulid)`; GiST exclusion (above); index `(scope, service_id, status, effective_from)`.
- **Audit:** `preferred_personnel_fee_rule.created` (info), `.approved` (high), `.superseded` (high),
  `.cancelled` (high). **Retention:** permanent. **Factory:** `PreferredPersonnelFeeRuleFactory`
  (fixed + percentage states).
- **Positive:** fixed/percentage validation; platform-default + service-override resolution; effective-
  date selection; round-half-up; supersede-not-edit; cancel future; backfill equality; future
  finalization uses the effective rule; branch read-only visibility. **Negative:** fixed with basis
  points rejected; percentage with fixed/currency rejected; `platform_default` with service_id rejected;
  `service` without service_id rejected; overlapping active/scheduled rejected by PG; editing active
  monetary terms is not an available action; over-range basis points rejected.

### Legacy preferred-fee expand-and-contract (Plan §13.10; migration `2026_07_10_000006`)

Phase 17 finalized invoices used the legacy fixed seam `services.preferred_personnel_fee_minor`
(resolved by `LegacyPreferredPersonnelFeeResolver`). Phase 20A performs a **prospective**
expand-and-contract:

1. **Backfill:** one `fixed_amount`, `scope=service`, `status=active` rule per service whose
   `preferred_personnel_fee_minor` is **non-null** (including 0). Migrated `fixed_amount_minor` equals
   the legacy minor-unit value **exactly**; `currency` = the service's currency;
   `calculation_basis = service_item_net_amount`; `created_by` = a deterministic system/platform actor;
   `change_reason = 'Phase 20A legacy preferred-personnel-fee backfill'`.
2. **Cutover (deterministic, product-owner-fixed):** `effective_from` = the immutable literal
   **`DATE '2026-07-10'`** — **never** `now()`, `today()`, `CURRENT_DATE`, `Carbon::today()`, or any
   deployment-time input (those differ per environment and make the migration non-deterministic). The
   migration produces identical `effective_from` values in every environment. This is **not
   retroactive** — Plan §13.10 and the Phase-17 note require changing only **future** finalization; the
   application begins resolving the new rule only after Phase 20A is deployed. A test asserts every
   backfilled row uses exactly `2026-07-10`. Recorded in `docs/proof/phase-20a.md`.
3. **Resolver swap:** `App\Providers\AppServiceProvider` rebinds the Invoicing
   `PreferredPersonnelFeeResolver` from `LegacyPreferredPersonnelFeeResolver` to a rule-backed resolver
   delegating to `App\Domain\Billing\Queries\ResolveEffectivePreferredPersonnelFee`. Finalization
   semantics are unchanged (session honoured-gating identical); only the fee **source** changes.
4. **Legacy column read-only:** `services.preferred_personnel_fee_minor` is **retained** and made
   read-only through application paths (no write path); it is **not dropped** in this deploy (contract
   step deferred to a later authorized migration with a compatibility proof + removal owner).
5. **Invoice stability:** already-finalized invoices keep their `preferred_personnel_fee_snapshot_minor`
   and are **never** rewritten.

**Equivalence proof:** a migration/feature test asserts, for every backfilled service, the resolved
rule fee equals the legacy value, and that a new finalization resolves via the rule while a prior
finalized invoice snapshot is unchanged.

---

## Phase 20B — Subscriptions and invoices (nullable Wallet projections only)

All five Phase 20B tables are **merchant-owned** (`merchant_id` present, `BelongsToMerchant`) but
carry **no `branch_id`** (subscriptions/billing are merchant-level, not branch-level). ULIDs are the
external route keys; foreign IDs 404 across tenants. Money is integer minor units. Statuses are
backed enums + PostgreSQL CHECKs; transitions go through the state machines in
`docs/architecture/state-machines/`.

Migration files (forward-only; added to `docs/architecture/migrations/manifest.yaml`):
`2026_07_11_000001_create_merchant_subscriptions_table`,
`_000002_create_scheduled_plan_changes_table`,
`_000003_create_subscription_invoices_table`,
`_000004_create_subscription_invoice_items_table`,
`_000005_create_billing_escalation_events_table`,
`_000006_expand_invoice_number_sequences_add_subscription_invoice_scope` (expand).

### `merchant_subscriptions` (Plan §13.9, §22, §48; migration `2026_07_11_000001`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | public id + route key; `UNIQUE` |
| `merchant_id` | bigint | no | FK `merchants(id)` ON DELETE RESTRICT; `BelongsToMerchant` |
| `plan_id` | bigint | no | FK `subscription_plans(id)` ON DELETE RESTRICT |
| `price_id` | bigint | no | FK `subscription_plan_prices(id)` ON DELETE RESTRICT; **captured at binding** (ADR-011 sole price source) |
| `status` | varchar | no | CHECK ∈ (`trialing`,`active`,`read_only_grace`,`overdue`,`suspended_billing`,`cancelled`,`expired`); `MerchantSubscriptionStatus` |
| `billing_interval` | varchar | no | CHECK ∈ (`weekly`,`bi_weekly`,`monthly`,`quarterly`,`annual`); `BillingInterval`; must equal `price.billing_interval` at binding |
| `trial_days_snapshot` | int | no | `CHECK (trial_days_snapshot >= 0)`; snapshot of effective `platform_billing_settings.default_trial_days` at binding — later settings changes never rewrite it |
| `trial_started_at` | timestamptz | no | **= Merchant-Administrator creation timestamp** (Gate B1 anchor) |
| `trial_ends_at` | timestamptz | no | `= trial_started_at + trial_days_snapshot` (Nairobi day math); `CHECK (trial_ends_at >= trial_started_at)` |
| `current_period_start` | date | no | |
| `current_period_end` | date | no | `CHECK (current_period_end > current_period_start)` |
| `high_value_payout_threshold_minor` | bigint | yes | `CHECK (… IS NULL OR … >= 0)` |
| `cancelled_at` | timestamptz | yes | effective terminal boundary for `cancelled` (B2: projection to `suspended_billing` only at/after this) |
| `expired_at` | timestamptz | yes | effective terminal boundary for `expired` |
| `created_at`/`updated_at` | timestamptz | no | |

- **One current non-terminal subscription per merchant:** partial unique index
  `UNIQUE(merchant_id) WHERE status NOT IN ('cancelled','expired')`. Terminal history is retained
  (no destructive delete).
- **Plan↔price consistency:** enforced in the binding action (price belongs to plan; interval
  matches) — proven by tests; FK RESTRICT prevents plan/price deletion while referenced.
- **Access authority is `merchants.billing_status`**, projected transactionally from this record by
  the billing-status projection service (§22); request authorization never reads
  `merchant_subscriptions.status` directly.
- **Trial anchor (Gate B1):** `trial_started_at` preserves the Merchant-Administrator creation time
  even though the row is created during first-time setup once plan+price are chosen. Binding is
  idempotent (no duplicate active subscription under replay/concurrency).
- **Indexes:** `UNIQUE(ulid)`; index `(merchant_id)`, `(status)`; partial unique (above).
- **Lifecycle spec:** `docs/architecture/state-machines/merchant-subscription.md` +
  `docs/architecture/state-machines/merchant-billing-status.md`.
- **Audit:** `subscription.created`, `subscription.trial_started`, `subscription.activated`,
  `subscription.read_only_grace_entered`, `subscription.overdue`, `subscription.suspended_billing`,
  `subscription.cancelled`, `subscription.expired`, `subscription.recovered`,
  `merchant.billing_status_changed` (final canonical names confirmed against `AuditEvent` in
  Increment 5). **Retention:** permanent. **Factory:** `MerchantSubscriptionFactory` (per-status).
- **Positive:** trial anchor = MA creation time; trial-days snapshot; each valid transition;
  transactional projection incl. terminal→`suspended_billing`; one-current-subscription. **Negative:**
  duplicate active subscription rejected; interval≠price rejected; invalid transition → 422; settings
  change does not rewrite an existing trial; subscription status alone never grants access.

### `scheduled_plan_changes` (Plan §13.9, §48; migration `2026_07_11_000002`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | `UNIQUE` |
| `merchant_id` | bigint | no | FK `merchants(id)` ON DELETE RESTRICT; `BelongsToMerchant` |
| `merchant_subscription_id` | bigint | no | FK `merchant_subscriptions(id)` ON DELETE RESTRICT |
| `target_plan_id` | bigint | no | FK `subscription_plans(id)` ON DELETE RESTRICT |
| `target_price_id` | bigint | no | FK `subscription_plan_prices(id)` ON DELETE RESTRICT; target price must belong to target plan (action-enforced) |
| `effective_at` | date | no | next-cycle boundary (no proration) |
| `status` | varchar | no | CHECK ∈ (`scheduled`,`applied`,`cancelled`); `ScheduledPlanChangeStatus` |
| `applied_at` | timestamptz | yes | set when `applied` |
| `cancelled_at` | timestamptz | yes | set when `cancelled` |
| `created_by` | bigint | no | FK `users(id)` ON DELETE RESTRICT |
| `created_at`/`updated_at` | timestamptz | no | |

- **One applicable scheduled change per subscription/cycle:** partial unique index
  `UNIQUE(merchant_subscription_id, effective_at) WHERE status = 'scheduled'`.
- **No proration; applied only at next cycle.** `applied`/`cancelled` rows are immutable
  (transitions only via the state machine; no in-place edit of a terminal row).
- **Indexes:** `UNIQUE(ulid)`; index `(merchant_id)`, `(merchant_subscription_id, status)`; partial
  unique (above).
- **Lifecycle spec:** `docs/architecture/state-machines/scheduled-plan-change.md`.
- **Audit:** `subscription.plan_change_scheduled`, `.plan_change_applied`, `.plan_change_cancelled`.
  **Retention:** permanent. **Factory:** `ScheduledPlanChangeFactory`.
- **Positive:** schedule at next cycle; cancel scheduled; apply exactly once (concurrent-apply
  protected by row lock); target plan/price consistency; history retained. **Negative:** second
  scheduled change for the same cycle rejected; target price not on target plan rejected; applying an
  already-applied change is a no-op/422.

### `subscription_invoices` (Plan §13.9, §49, ADR-014; migration `2026_07_11_000003`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | `UNIQUE` |
| `merchant_id` | bigint | no | FK `merchants(id)` ON DELETE RESTRICT; `BelongsToMerchant` |
| `plan_id` | bigint | no | FK `subscription_plans(id)` ON DELETE RESTRICT (snapshot) |
| `price_id` | bigint | no | FK `subscription_plan_prices(id)` ON DELETE RESTRICT (captured price) |
| `invoice_number` | varchar | no | per-merchant unique; allocated from `invoice_number_sequences` scope `subscription_invoice` (Gate B3) |
| `period_start` | date | no | |
| `period_end` | date | no | `CHECK (period_end > period_start)` |
| `subtotal_minor` | bigint | no | `CHECK (subtotal_minor >= 0)` |
| `discount_minor` | bigint | no | default 0; `CHECK (discount_minor >= 0)`; 0 in 20B (no promotions until 20C) |
| `total_minor` | bigint | no | `CHECK (total_minor >= 0)`; `CHECK (total_minor = subtotal_minor - discount_minor)` |
| `currency` | char(3) | no | `CHECK (currency = upper(currency))` |
| `balance_minor` | bigint | no | `CHECK (balance_minor >= 0)`; at issuance = `total_minor`; driven only by verified Wallet events in 20D-W |
| `status` | varchar | no | CHECK ∈ (`draft`,`issued`,`pending_payment`,`partially_paid`,`paid`,`overdue`,`payment_failed`,`reconciliation_required`,`void`); `SubscriptionInvoiceStatus` |
| `account_reference` | varchar | yes | Wallet `SRV-PAY-<ULID26>`; **null until 20D-W**; immutable once set (ADR-014) |
| `wallet_payment_id` | varchar | yes | `UNIQUE`; **null in 20B** |
| `wallet_registration_status` | varchar | no | CHECK ∈ (`unregistered`,`pending`,`registered`,`failed`) default `unregistered`; `WalletRegistrationStatus`; **`unregistered` in 20B** |
| `wallet_registered_at` | timestamptz | yes | **null in 20B** |
| `issued_at` | timestamptz | yes | set at `issued` |
| `due_at` | timestamptz | yes | computed from interval math (§49) |
| `created_at`/`updated_at` | timestamptz | no | |

- **Numbering (Gate B3):** `invoice_number` allocated gap-free per merchant under
  `SELECT … FOR UPDATE` on `invoice_number_sequences (merchant_id, scope='subscription_invoice')` —
  an independent counter from merchant-client invoices. `UNIQUE(merchant_id, invoice_number)`.
- **Issued immutability:** once `status` leaves `draft` the financial fields
  (`plan_id, price_id, invoice_number, period_*, subtotal_minor, discount_minor, total_minor,
  currency, issued_at, due_at`) are immutable. The Wallet registration fields are an **orthogonal
  technical projection**, not part of the financial snapshot, and never block issuance (ADR-014).
- **Cancellation terminology:** subscription invoices use **`void`** only (never `cancelled`).
- **Wallet forward-compatibility (20B):** ships the four nullable projection columns at their
  defaults; **no** registration call, outbox intent, table, or consumer. PDF renders "Payment
  reference pending — see your billing dashboard" while `account_reference` is null.
- **Fail-closed mode (Gate B5):** issuance asserts effective `billing_mode = fixed_amount`; a
  percentage-requiring mode raises `billing_mode_not_supported` (422) and issues nothing.
- **PDF (Phase 10F; migration `2026_07_11_000009`):** additive `file_id` (nullable FK
  `uploaded_files` RESTRICT) + `pdf_version` (int default 0) link the invoice to its current generated
  PDF (purpose `billing_invoice_pdf`). Technical projection columns — **not** part of the immutable
  financial snapshot (so `GenerateSubscriptionInvoicePdf` may update them post-issue). Each
  regeneration writes a new `uploaded_files` version and revokes the prior one. Generation is blocked
  in `read_only_grace`/`suspended_billing` (billing-status gate); existing PDFs stay downloadable.
- **Indexes:** `UNIQUE(ulid)`; `UNIQUE(merchant_id, invoice_number)`; `UNIQUE(wallet_payment_id)`;
  index `(merchant_id, status)`.
- **Lifecycle spec:** `docs/architecture/state-machines/subscription-invoice.md`.
- **Audit:** `subscription_invoice.issued`, `.overdue`, `.voided`, `.pdf_generated`.
  **Retention:** permanent (financial). **Factory:** `SubscriptionInvoiceFactory`.
- **Positive:** fixed-mode total = captured plan price; per-merchant number allocation; idempotent
  issuance; Wallet columns default/null; issued fields immutable; PDF placeholder when unregistered;
  overdue transition; void terminology; tenant isolation. **Negative:** editing issued financial
  fields not available; percentage-mode issuance fails closed; cross-tenant read 404; no percentage
  ledger row created; no Wallet call.

### `subscription_invoice_items` (Plan §13.9, §49; migration `2026_07_11_000004`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `subscription_invoice_id` | bigint | no | FK `subscription_invoices(id)` ON DELETE RESTRICT |
| `description` | varchar | no | non-empty |
| `amount_minor` | bigint | no | `CHECK (amount_minor >= 0)` |
| `type` | varchar | no | CHECK ∈ (`plan_fee`,`platform_fee_rollup`,`sms_rollup`,`adjustment`) |
| `created_at`/`updated_at` | timestamptz | no | |

- **Immutable line items:** created at issuance, never edited/deleted (append-only via the invoice
  workflow). 20B fixed mode issues a single `plan_fee` line equal to the captured price; it fabricates
  **no** `platform_fee_rollup` (20E), `sms_rollup` (21S), promotion, or Wallet amounts.
- **Phase 20E — platform-fee rollup cycle guard (expand `2026_07_13_000008`):** a partial
  `UNIQUE (subscription_invoice_id) WHERE type='platform_fee_rollup'` guarantees **at most one**
  `platform_fee_rollup` line per subscription invoice. Combined with the one-invoice-per-`(merchant,
  period)` idempotency in `IssueSubscriptionInvoice` (serialized on the `MerchantSubscription` row lock),
  this is the DB-level cycle guard that prevents two concurrent workers from issuing two platform-fee
  rollups for the same merchant/subscription/currency/period (Increment 5A; not an application-only
  pre-check). The rollup amount = Σ of the eligible earned/pending
  `platform_fee_ledger_entries.merchant_liability_minor` for the period; it is non-negative (so the
  `type≠'adjustment' ⇒ amount_minor ≥ 0` CHECK holds). The shipped 20B migration is not edited.
- **Phase 20E — future-cycle correction `adjustment` line (backend closure; no migration):** pending
  `reversal`/`adjustment` ledger rows whose ORIGINAL earned entry was already invoiced
  (`original.subscription_invoice_item_id IS NOT NULL`) are swept into **one signed `type='adjustment'`
  line per cycle** = Σ of the consumed corrections' paired `platform_fee_adjustments.amount_minor` (the
  canonical signed source; `adjustment` is the only line type the sign CHECK lets be negative). Selection is
  `status='pending' AND subscription_invoice_item_id IS NULL AND (billable_at Nairobi date) < period_end`
  under `FOR UPDATE`; consumed rows transition `pending → aggregated → invoiced`. The applied negative net is
  capped so `subscription_invoices.total_minor ≥ 0` can never be breached; an un-applied correction stays
  `pending` and carries to a later cycle (whole-entry — an immutable row is never split). No Wallet credit
  (Phase 20D-W). No per-invoice unique guard is added for the correction line (a broad `type='adjustment'`
  unique would block unrelated adjustments); double-consumption is prevented by the `FOR UPDATE` selection +
  single FK + terminal `invoiced` state + one-invoice-per-`(merchant,period)`.
- **Indexes:** index `(subscription_invoice_id)`; partial-unique rollup guard (above).
- **Positive:** one `plan_fee` line = captured price; sum(items) = `subtotal_minor`. **Negative:**
  item mutation not available; no non-plan_fee lines fabricated in 20B.

### `billing_escalation_events` (Plan §13.15, §54; migration `2026_07_11_000005`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | `UNIQUE` |
| `merchant_id` | bigint | no | FK `merchants(id)` ON DELETE RESTRICT; `BelongsToMerchant` |
| `subscription_invoice_id` | bigint | yes | FK `subscription_invoices(id)` ON DELETE RESTRICT |
| `merchant_subscription_id` | bigint | no | FK `merchant_subscriptions(id)` ON DELETE RESTRICT |
| `event_type` | varchar | no | CHECK ∈ (`reminder`,`grace_entered`,`overdue`,`suspended_billing`,`recovered`); `BillingEscalationEventType` |
| `from_billing_status` | varchar | yes | prior `merchants.billing_status` |
| `to_billing_status` | varchar | yes | new `merchants.billing_status` |
| `reason` | text | yes | redacted human reason |
| `period_boundary` | date | no | **Gate B4** — the current-period boundary the event pertains to |
| `created_at` | timestamptz | no | **append-only** (no `updated_at`; no UPDATE/DELETE) |

- **Durable idempotency (Gate B4):** `UNIQUE(merchant_subscription_id, event_type, period_boundary)`
  — idempotency is enforced by the constraint, **never** by `created_at`. A replayed escalation for
  the same subscription/event/period is a no-op (ON CONFLICT DO NOTHING within the action).
- **Append-only:** no application UPDATE/DELETE path; history preserved.
- **Indexes:** `UNIQUE(ulid)`; `UNIQUE(merchant_subscription_id, event_type, period_boundary)`; index
  `(merchant_id)`, `(merchant_subscription_id)`.
- **Lifecycle spec:** `docs/architecture/state-machines/billing-escalation.md`.
- **Audit:** `billing_escalation.reminder`, `.grace_entered`, `.overdue`, `.suspended`, `.recovered`.
  **Retention:** permanent. **Factory:** `BillingEscalationEventFactory`.
- **Positive:** one row per `(subscription, event_type, period_boundary)`; append-only; feeds
  Super-Admin overdue-escalation reporting. **Negative:** duplicate `(subscription, event, period)`
  rejected by PG; UPDATE/DELETE not available.

### `invoice_number_sequences` scope expand (Gate B3; migration `2026_07_11_000006`)

Forward-only **expand**: drop and recreate the shipped scope CHECK to add `'subscription_invoice'`:

```text
scope CHECK in ('merchant_client_invoice','subscription_invoice')
```

The shipped `2026_..._invoice_number_sequences` migration is **not edited** (guardrail 12). Merchant-
client invoice allocation is unchanged. Subscription invoices allocate under a **separate** counter
row `(merchant_id, scope='subscription_invoice')` — gap-free, row-locked, never reused, never
crossing merchant-client numbers. No new sequence table is invented. `unique(merchant_id, scope)`
already scopes the counters.

### `merchants.billing_status_reason` (Gate B2)

`merchants.billing_status_reason` already exists (Plan §13 merchants DDL, `text` nullable). The
billing-status projection service writes the distinct terminal reasons `subscription_cancelled` /
`subscription_expired` (and other billing reasons) here + in audit context. No schema change unless
Increment 2 finds the column absent, in which case an additive expand migration adds it (documented
here before migration).

**Explicit non-deliverable in 20B:** no `RegisterInvoicePayment` outbox table, no registration
consumer, no Wallet HTTP client routes, no payment/attempt/receipt/reversal/reconciliation/credit
tables. Registration + all payment runtime is Phase 20D-W only (Correction 14.5).

---

## Phase 20C — Promotions and free-period offers (platform; Plan §53)

Platform-governed financial configuration. Four **platform-scoped** tables (no `merchant_id`/
`branch_id` on the parents — `TenantOwnership::EXEMPT`; some *target rows* point at a merchant, but
the offer itself is global configuration). Targets are **explicit normalized rows** — never JSON
(§53). At most **one** promotional discount and **one** free-period offer apply per subscription
issuance; a promotion and a free-period offer solve different concerns and may coexist. Selected
terms are **snapshotted** onto the subscription/invoice and never recomputed (§53).

**Gate decisions (recorded here + in `docs/proof/phase-20c.md`):**

- **C1 (effective-date naming + target ULID):** the effective window uses **`effective_from` (date) /
  `effective_to` (date, nullable)** — the established billing convention (`subscription_plan_prices`,
  §53). No `starts_at` column is introduced; §53's `starts_at` shorthand ≡ `effective_from`. Both
  target tables carry an **immutable unique `ulid`** so §53's target-ULID tie-break is executable.
  Global (`all_new_merchants`) candidates have **no** target row — global ties break on parent
  `effective_from` then parent `ulid`.
- **C2 (normalized targets):** parent `target_scope ∈ {all_new_merchants, selected_merchants,
  selected_plans, billing_mode}`; target `target_type ∈ {merchant, plan, billing_mode}`; exactly one
  of `merchant_id`/`subscription_plan_id`/`billing_mode` set and matching `target_type`;
  `all_new_merchants` has zero targets; duplicate parent/target rows forbidden.
- **C3 (eligibility instant):** free-period/trial eligibility resolves at the Merchant-Administrator
  creation anchor (Gate B1) with the setup-selected plan + effective platform billing mode; promotion
  eligibility resolves at the invoice issuance business date against the invoice merchant/plan/billing
  mode; issued invoices are never re-resolved.
- **C4 (snapshot persistence):** forward-only expand columns (below) on the 20B tables; applied days
  stay in `merchant_subscriptions.trial_days_snapshot`, applied discount stays in
  `subscription_invoices.discount_minor`; no JSON target blob; no backfill onto existing
  trials/invoices.
- **C5 (fixed-discount cap — product-owner decision 2026-07-12):** a fixed promotional discount is
  **capped at the invoice subtotal**: `applied_discount_minor = min(configured_fixed_minor,
  subtotal_minor)`, `total_minor = subtotal_minor − applied_discount_minor` (may be 0, never
  negative). No merchant credit / carry-forward / refund / residual value is created. **Both** the
  configured value (`subscription_invoices.promotion_value_snapshot`) and the applied amount
  (`subscription_invoices.discount_minor`) are snapshotted. The configured promotion is never mutated
  — capping is invoice-specific. Currency must match before calculation. Percentage discounts use
  basis points + ADR-005 round-half-up and can never exceed the subtotal. The existing
  `subscription_invoices` CHECKs (`discount_minor <= subtotal_minor`, `total_minor = subtotal_minor −
  discount_minor`, all `>= 0`) are the database backstop for this invariant.
- **C6 (approval):** reuse the Phase 20A platform-configuration approval pattern — Super-Administrator,
  MFA, fresh step-up, high-severity audit; **no** separate maker/checker role. Drafts + targets
  editable only while `draft`; approval records `approved_by`/`approved_at`; approved financial terms
  and targets are immutable (supersede via a new record); pause/resume changes availability only;
  cancel only from documented pre-active states.

### `promotional_discounts` (Plan §53; migration `2026_07_12_000001`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK (never external) |
| `ulid` | char(26) | no | public id + route key; `UNIQUE` |
| `name` | varchar(120) | no | `CHECK (char_length(btrim(name)) > 0)` |
| `type` | varchar(16) | no | CHECK ∈ (`percentage`,`fixed_amount`); `PromotionalDiscountType` |
| `value` | bigint | no | `CHECK (value > 0)`; **percentage** = basis points (`value <= 10000` ⇒ ≤100%); **fixed_amount** = minor units |
| `currency` | char(3) | yes | percentage ⇒ NULL; fixed ⇒ uppercase ISO 3-char |
| `target_scope` | varchar(24) | no | CHECK ∈ (`all_new_merchants`,`selected_merchants`,`selected_plans`,`billing_mode`); `PromotionTargetScope` |
| `effective_from` | date | no | window start (Gate C1) |
| `effective_to` | date | yes | `CHECK (effective_to IS NULL OR effective_to > effective_from)` |
| `status` | varchar(16) | no | CHECK ∈ (`draft`,`scheduled`,`active`,`paused`,`expired`,`cancelled`); `PromotionStatus` |
| `created_by` | bigint | no | FK `users(id)` ON DELETE RESTRICT |
| `approved_by` | bigint | yes | FK `users(id)` ON DELETE RESTRICT |
| `approved_at` | timestamptz | yes | |
| `change_reason` | text | yes | sanitized reason for the latest state-changing action |
| `created_at`/`updated_at` | timestamptz | no | |

- **Value/currency coherence CHECK:** `((type='percentage' AND currency IS NULL AND value <= 10000)
  OR (type='fixed_amount' AND currency IS NOT NULL AND currency = upper(currency) AND
  char_length(currency)=3))`.
- **Approval/status coherence CHECKs:** (a) `((approved_by IS NULL AND approved_at IS NULL) OR
  (approved_by IS NOT NULL AND approved_at IS NOT NULL))`; (b) `((status='draft' AND approved_by IS
  NULL) OR (status IN ('scheduled','active','paused','expired') AND approved_by IS NOT NULL) OR
  (status='cancelled'))` — `cancelled` may be reached from `draft` (unapproved) or `scheduled`
  (approved).
- **No hard delete:** no application DELETE path; retention permanent (financial configuration).
- **Indexes:** `UNIQUE(ulid)`; index `(status, effective_from, effective_to)` (resolution/window);
  index `(target_scope)`.
- **Lifecycle spec:** `docs/architecture/state-machines/promotional-discount.md`.
- **Audit:** `promotion.created`, `.draft_updated`, `.approved`, `.activated`, `.paused`, `.resumed`,
  `.expired`, `.cancelled` (high severity on state changes). **Factory:** `PromotionalDiscountFactory`
  (per-status + per-type).
- **Positive:** percentage/fixed create; approve→scheduled(future)/active(current); pause/resume;
  expiry; draft edit. **Negative:** value ≤ 0 rejected; percentage >100% rejected; percentage with
  currency rejected; fixed without currency rejected; `effective_to <= effective_from` rejected;
  editing approved terms rejected; invalid transition → 422; hard delete unavailable.

### `promotional_discount_targets` (Plan §53; migration `2026_07_12_000002`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | **immutable** public id; `UNIQUE`; **Gate C1 tie-break key** |
| `promotional_discount_id` | bigint | no | FK `promotional_discounts(id)` ON DELETE RESTRICT |
| `target_type` | varchar(16) | no | CHECK ∈ (`merchant`,`plan`,`billing_mode`); `PromotionTargetType` |
| `merchant_id` | bigint | yes | FK `merchants(id)` ON DELETE RESTRICT |
| `subscription_plan_id` | bigint | yes | FK `subscription_plans(id)` ON DELETE RESTRICT |
| `billing_mode` | varchar(56) | yes | CHECK ∈ (`fixed_amount`,`percentage_on_merchant_client_invoice`,`fixed_amount_plus_percentage_on_merchant_client_invoice`) when set; canonical `BillingMode` |
| `created_at` | timestamptz | no | append-only (no `updated_at`; targets replaced, never edited) |

- **Exactly-one-target CHECK:** `((target_type='merchant' AND merchant_id IS NOT NULL AND
  subscription_plan_id IS NULL AND billing_mode IS NULL) OR (target_type='plan' AND
  subscription_plan_id IS NOT NULL AND merchant_id IS NULL AND billing_mode IS NULL) OR
  (target_type='billing_mode' AND billing_mode IS NOT NULL AND merchant_id IS NULL AND
  subscription_plan_id IS NULL))`.
- **Duplicate-target rejection:** three partial unique indexes (NULLs would otherwise defeat a single
  composite unique): `UNIQUE(promotional_discount_id, merchant_id) WHERE target_type='merchant'`;
  `UNIQUE(promotional_discount_id, subscription_plan_id) WHERE target_type='plan'`;
  `UNIQUE(promotional_discount_id, billing_mode) WHERE target_type='billing_mode'`.
- **Target mutability:** rows may be added/removed only while the parent is `draft` (action-enforced);
  once the parent is approved they are immutable (supersede the parent with a new record).
- **Indexes:** `UNIQUE(ulid)`; the three partial uniques; resolution indexes `(merchant_id)`,
  `(subscription_plan_id)`, `(billing_mode)`, and `(promotional_discount_id)`.
- **Factory:** `PromotionalDiscountTargetFactory`.
- **Positive:** merchant/plan/billing_mode targets insert; billing_mode targets for all three canonical
  modes. **Negative:** two target fields set → rejected; field≠type → rejected; duplicate
  parent/target → rejected; raw-SQL cannot bypass the exactly-one CHECK.

### `free_period_offers` (Plan §53; migration `2026_07_12_000003`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | public id + route key; `UNIQUE` |
| `name` | varchar(120) | no | `CHECK (char_length(btrim(name)) > 0)` |
| `free_period_days` | int | no | `CHECK (free_period_days BETWEEN 1 AND 365)` |
| `target_scope` | varchar(24) | no | CHECK ∈ (`all_new_merchants`,`selected_merchants`,`selected_plans`,`billing_mode`); `PromotionTargetScope` |
| `effective_from` | date | no | |
| `effective_to` | date | yes | `CHECK (effective_to IS NULL OR effective_to > effective_from)` |
| `status` | varchar(16) | no | CHECK ∈ (`draft`,`scheduled`,`active`,`paused`,`expired`,`cancelled`); `FreePeriodOfferStatus` |
| `created_by` | bigint | no | FK `users(id)` ON DELETE RESTRICT |
| `approved_by` | bigint | yes | FK `users(id)` ON DELETE RESTRICT |
| `approved_at` | timestamptz | yes | |
| `change_reason` | text | yes | sanitized reason for the latest state-changing action |
| `created_at`/`updated_at` | timestamptz | no | |

- **Approval/status coherence CHECKs:** identical shape to `promotional_discounts` — approval moves
  `draft → scheduled` only (the free-period machine has **no** direct `draft → active`; §12);
  `scheduled → active` is a separate activation. `cancelled` reachable from `draft` or `scheduled`.
- **No hard delete;** retention permanent.
- **Indexes:** `UNIQUE(ulid)`; index `(status, effective_from, effective_to)`; index `(target_scope)`.
- **Lifecycle spec:** `docs/architecture/state-machines/free-period-offer.md`.
- **Audit:** `free_period_offer.created`, `.draft_updated`, `.approved`, `.activated`, `.paused`,
  `.resumed`, `.expired`, `.cancelled` (high severity). **Factory:** `FreePeriodOfferFactory`.
- **Positive:** create; approve→scheduled; activate→active; pause/resume; expiry. **Negative:** days
  <1 or >365 rejected; `effective_to <= effective_from` rejected; `draft → active` rejected (422);
  editing approved terms rejected; hard delete unavailable.

### `free_period_offer_targets` (Plan §53; migration `2026_07_12_000004`)

Identical structure and protections to `promotional_discount_targets`, parented by
`free_period_offer_id` (FK `free_period_offers(id)` ON DELETE RESTRICT). Immutable `ulid` tie-break
key; exactly-one-target CHECK; three partial unique indexes
(`WHERE target_type='merchant'|'plan'|'billing_mode'`); resolution indexes on `merchant_id`,
`subscription_plan_id`, `billing_mode`, `free_period_offer_id`. **Factory:**
`FreePeriodOfferTargetFactory`.

### Snapshot expands on 20B tables (Gate C4; forward-only — shipped 20B migrations never edited)

**`subscription_invoices` promotion snapshot (migration `2026_07_12_000005`):** additive nullable
columns capturing which promotion applied and its terms, alongside the existing `discount_minor`
(the applied, capped amount). Existing issued invoices keep NULL (no backfill, no recalculation).

| Column | Type | Null | Notes |
|---|---|---|---|
| `promotional_discount_id` | bigint | yes | FK `promotional_discounts(id)` ON DELETE RESTRICT; null ⇒ no promotion applied |
| `promotion_type` | varchar(16) | yes | CHECK (`promotion_type IS NULL OR promotion_type IN ('percentage','fixed_amount')`); snapshotted type |
| `promotion_value_snapshot` | bigint | yes | `CHECK (… IS NULL OR … > 0)`; **configured** value at resolution (bps for percentage, minor for fixed) |
| `promotion_currency` | char(3) | yes | fixed-amount snapshot currency (uppercase); null for percentage/none |
| `promotion_resolved_at` | timestamptz | yes | resolution instant |

- The **applied** discount is `discount_minor` (existing; capped per C5); the **configured** value is
  `promotion_value_snapshot`. For a fixed promotion both are minor units and `discount_minor =
  min(promotion_value_snapshot, subtotal_minor)`. Snapshot coherence CHECK:
  `((promotional_discount_id IS NULL AND promotion_type IS NULL AND promotion_value_snapshot IS NULL
  AND promotion_currency IS NULL AND promotion_resolved_at IS NULL) OR (promotional_discount_id IS NOT
  NULL AND promotion_type IS NOT NULL AND promotion_value_snapshot IS NOT NULL AND promotion_resolved_at
  IS NOT NULL))`.
- Immutability: these join the invoice's immutable financial snapshot once `status` leaves `draft`;
  later promotion edits/pause/cancel/expiry never change an issued invoice (FK RESTRICT + no update
  path). Index `(promotional_discount_id)`.

**`merchant_subscriptions` free-period snapshot (migration `2026_07_12_000006`):** additive nullable
columns capturing which free-period offer set the trial length. Applied days stay in
`trial_days_snapshot`. Existing trials keep NULL (no backfill).

| Column | Type | Null | Notes |
|---|---|---|---|
| `free_period_offer_id` | bigint | yes | FK `free_period_offers(id)` ON DELETE RESTRICT; null ⇒ platform default trial days |
| `free_period_resolved_at` | timestamptz | yes | resolution instant |

- Provenance is `free_period_offer_id` (which offer) + `trial_days_snapshot` (the applied days) +
  `free_period_resolved_at`. Snapshot coherence CHECK: `((free_period_offer_id IS NULL AND
  free_period_resolved_at IS NULL) OR (free_period_offer_id IS NOT NULL AND free_period_resolved_at IS
  NOT NULL))`. Later offer edits/pause/cancel/expiry never change an existing trial (FK RESTRICT + no
  rewrite). Index `(free_period_offer_id)`.

### Target-resolution algorithm (both offer kinds; Plan §53)

1. consider only `active` records whose effective window contains the business instant (C3);
   exclude `draft`/`scheduled`/`paused`/`expired`/`cancelled`;
2. collect candidates matching merchant / plan / billing_mode targets and the global
   `all_new_merchants` scope (where applicable);
3. choose the single winner by precedence **merchant > plan > billing_mode > global**;
4. within the winning precedence class, break ties by **latest `effective_from`**, then **ascending
   target `ulid`**; global ties break by parent `effective_from` then parent `ulid`;
5. return a typed immutable resolution result, or explicit `none`;
6. never stack two discounts or two free-period offers.

### Ownership, retention, lifecycle

- **Platform-scoped:** registered `EXEMPT` in `app/Domain/Tenancy/TenantOwnership.php`; no
  `merchant_id`/`branch_id` on parents. Target rows referencing a merchant do **not** make the offer
  merchant-owned.
- **Retention:** permanent; no hard-delete path (append-only lifecycle via state machine).
- **Lifecycle scheduler:** `ProcessPromotionLifecycle` (Nairobi business time; §67 conventions) —
  activates due `scheduled` records, expires due `active` records; idempotent; row-locked; one audit
  event per real transition; never edits snapshots.

---

## Phase 20D-W — Wallet integration (requires Gate W)

| Table | Owner phase | Purpose |
|---|---|---|
| `wallet_merchant_account_links` | 20D-W | Servana merchant ↔ Wallet merchant-account ID |
| `subscription_payment_attempts` | 20D-W | User/product-initiated attempts (STK); includes `submission_unknown` state |
| `subscription_payments` | 20D-W | Confirmed Wallet payments (including direct C2B without attempt row) |
| `subscription_payment_receipts` | 20D-W | Append-only partial receipt/allocation child rows (Correction 14.10) |
| `wallet_webhook_inbox` | 20D-W | Verified first-seen `wallet_event_id` only |
| `billing_reconciliation_exceptions` | 20D-W | Servana-side reconciliation cases (masked provider refs) |
| `subscription_invoice_payment_locks` | 20D-W | Bounded cooldown/lock for `submission_unknown` retries |
| `merchant_billing_credits` | 20D-W | Overpayment credits (A-10) |

### Payment attempt states (Plan §25.4; Correction 14.6)

Includes: `initiated`, `submitting_to_wallet`, `submitted_to_wallet`, **`submission_unknown`**,
`prompt_sent`, `confirmed`, `applied_to_invoice`, plus terminal failure/cancel states.

**`submission_unknown`:** entered on Wallet submission timeout or ambiguous transport failure.
Must retain the **original idempotency key**, prevent duplicate attempts under a new key, retry/query
with the original identity under a bounded lock, and resolve through authoritative Wallet status —
timeout ≠ proof the request was not accepted.

### Direct C2B (Correction 14.9)

- **`subscription_payment_attempts`:** user/product-initiated attempts (STK).
- **`subscription_payments`:** confirmed Wallet payments, **including direct C2B**.
- Direct C2B has **no attempt row** unless Wallet correlates to an existing product-created attempt.
- Do **not** fabricate `initiated_by_user_id`, `initiated_by_role_snapshot`, or Servana initiation
  idempotency keys for orphan C2B.

### Partial payments (Correction 14.10)

- One **`subscription_payments` aggregate row per Wallet payment** (`wallet_payment_id` unique on
  the aggregate).
- Multiple partial receipts via append-only **`subscription_payment_receipts`** with unique
  `confirming_wallet_event_id`.
- If Gate W requires: `unique(wallet_payment_id, wallet_receipt_sequence)` on child rows.

### Webhook ordering (Correction 14.8)

Wallet contract must publish a monotonic per-resource field (`resource_version` or
`state_sequence` — **exact name pinned at Gate W**). Servana applies only strictly newer versions;
`occurred_at` and event ID alone are not ordering authority.

### Webhook verification (Correction 14.7)

Unverified requests must not insert into the canonical verified `wallet_event_id` uniqueness
constraint. Failed verification → security audit + metrics; no ad-hoc rejection table in adoption PR.

---

## Phase 20E — Percentage Platform-Fee Engine (Plan §§13.10, 51, 52; Corrections 2/4/8)

> Launch-capable, **inert until a percentage component is configured**. Money is integer minor units;
> currency `char(3)` uppercase; percentage rates integer basis points (0–10000); rounding ADR-005
> round-half-up + largest-remainder residual. Ledger entries are created at **Finance validation**
> (billability authority), never at recording; `settled` is **not** a Phase 20E state (Wallet/20D-W).
> See gate decisions E1–E9 in `docs/proof/phase-20e.md`.
>
> **Fee-basis amounts are server-owned, computed from the locked Phase 17 finalization data** (never the
> browser). Definitions:
> - `merchant_client_invoice_service_subtotal` = Σ service line nets (`invoices.subtotal_minor`; excludes
>   the preferred-personnel fee line and the Phase 20E client-shifted amount).
> - `merchant_client_invoice_total` = the **pre-platform-fee** invoice total, i.e. the Phase 17
>   `InvoiceTotalsCalculator` result (subtotal + tax − discount + preferred-personnel fee) **immediately
>   before** the Phase 20E client-shifted amount is added. The percentage fee is therefore **never**
>   computed on a total that already contains that same fee — no circular calculation.
> - `net_after_discount` = `subtotal_minor − discount_minor`.
> - `invoice_item_subtotal` = per-item `line_total_minor` (item-level basis → largest-remainder provenance).
> - `validated_paid_amount` = the newly validated amount on each `PaymentValidationEvent` (liability
>   recognition per event); see the tier-compatibility rule below.

### `platform_fee_configurations` (Plan §13.10; migration `2026_07_13_000001`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | `UNIQUE`; public ref |
| `billing_mode` | varchar | no | CHECK ∈ (`fixed_amount`,`percentage_on_merchant_client_invoice`,`fixed_amount_plus_percentage_on_merchant_client_invoice`) |
| `percentage_basis_points` | int | yes | `CHECK (percentage_basis_points BETWEEN 0 AND 10000)`; required in percentage modes |
| `fixed_component_minor` | bigint | yes | `CHECK (>=0)`; present for `fixed_amount_plus_percentage…` |
| `tier_behavior` | varchar | yes | CHECK ∈ (`customer_centric`,`shared`,`business_centric`); required in percentage modes |
| `shared_split_basis_points` | int | yes | `CHECK (BETWEEN 0 AND 10000)`; `CHECK (tier_behavior <> 'shared' OR shared_split_basis_points IS NOT NULL)` |
| `fee_basis_type` | varchar | yes | CHECK ∈ (`merchant_client_invoice_service_subtotal`,`merchant_client_invoice_total`,`net_after_discount`,`invoice_item_subtotal`,`validated_paid_amount`); required in percentage modes |
| `currency` | char(3) | no | `CHECK (currency = upper(currency))` |
| `effective_from` | date | no | business date (`Africa/Nairobi`) |
| `effective_to` | date | yes | `CHECK (effective_to IS NULL OR effective_to > effective_from)` |
| `status` | varchar | no | CHECK ∈ (`draft`,`scheduled`,`active`,`superseded`,`cancelled`); `PlatformFeeConfigurationStatus` |
| `created_by` | bigint | no | FK `users(id)` RESTRICT |
| `approved_by` | bigint | yes | FK `users(id)` RESTRICT |
| `approved_at` | timestamptz | yes | |
| `change_reason` | text | no | non-empty |
| `created_at`/`updated_at` | timestamptz | no | |

- **Scope:** platform (no `merchant_id`/`branch_id`) — `withoutTenancy()` service context only. Reuses the
  Phase 20A effective-dated platform-configuration conventions; the **active billing mode** remains
  `platform_billing_settings.billing_mode` (no duplicate source of truth).
- **Immutability:** approved monetary terms (`percentage_basis_points`, `fixed_component_minor`,
  `tier_behavior`, `shared_split_basis_points`, `fee_basis_type`, `currency`) immutable once `active`;
  changes **supersede** (new version). Draft terms editable in place.
- **Overlap:** `EXCLUDE USING gist` over `(billing_mode, currency, daterange(effective_from, effective_to))`
  for `active`+`scheduled` — the authoritative overlap guard.
- **Lifecycle spec:** `docs/architecture/state-machines/platform-fee-configuration.md`.
- **Audit:** `platform_fee.configuration_created/_updated/_approved/_superseded/_cancelled`.
  **Retention:** permanent. **Factory:** `PlatformFeeConfigurationFactory` (draft/scheduled/active/
  superseded states). **Backfill:** none (engine inert until configured).
- **Positive:** percentage valid; fixed-plus-percentage valid; supersede-not-edit; effective resolution by
  date. **Negative:** shared without split rejected; over-range bps rejected; missing tier in percentage
  mode rejected; overlapping windows rejected by PG; non-Super-Admin denied.

### `platform_fee_ledger_entries` (Plan §13.10, §51; migration `2026_07_13_000002`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | `UNIQUE`; public ref |
| `merchant_id` | bigint | no | FK `merchants(id)` RESTRICT; `BelongsToMerchant` |
| `branch_id` | bigint | yes | FK `branches(id)` RESTRICT; `BelongsToBranch` when set |
| `source_invoice_id` | bigint | no | FK `invoices(id)` RESTRICT (merchant-client invoice) |
| `source_invoice_item_id` | bigint | yes | FK `invoice_items(id)` RESTRICT (item-level provenance) |
| `entry_type` | varchar | no | CHECK ∈ (`earned`,`reversal`,`adjustment`); `PlatformFeeEntryType` |
| `status` | varchar | no | CHECK ∈ (`pending`,`aggregated`,`invoiced`,`reversed`,`adjusted`); `PlatformFeeLedgerStatus` |
| `billing_mode_snapshot` | varchar | no | mode at finalization |
| `service_fee_tier_snapshot` | varchar | no | CHECK ∈ (`customer_centric`,`shared`,`business_centric`) — mapped from `split_tier` |
| `fee_basis_type` | varchar | no | CHECK ∈ E2 vocabulary |
| `fee_basis_amount_minor` | bigint | no | `CHECK (>=0)` |
| `percentage_rate_snapshot` | int | no | basis points 0–10000 |
| `shared_split_snapshot` | int | yes | basis points when tier=shared |
| `gross_platform_fee_minor` | bigint | no | `CHECK (>=0)` |
| `client_shifted_amount_minor` | bigint | no | `CHECK (>=0)` |
| `merchant_absorbed_amount_minor` | bigint | no | `CHECK (>=0)` |
| `merchant_liability_minor` | bigint | no | `CHECK (merchant_liability_minor = gross_platform_fee_minor)` |
| `currency` | char(3) | no | uppercase; matches source + config |
| `effective_configuration_id` | bigint | no | FK `platform_fee_configurations(id)` RESTRICT |
| `subscription_invoice_item_id` | bigint | yes | FK `subscription_invoice_items(id)` RESTRICT (aggregation link) |
| `reversed_entry_id` | bigint | yes | self-FK RESTRICT (reversal/adjustment → original) |
| `source_validation_event_id` | bigint | yes | FK `payment_validation_events(id)` RESTRICT — the Phase 18B group validation event that made an `earned` liability billable (immutable validation-source identity; NULL for reversal/adjustment) |
| `idempotency_key` | varchar(191) | yes | replay guard (partial-unique). Earned: `earned:{invoice}:{event}[:{item}]`. Correction rows carry `ledger:` + the source correction event's key (`ledger:reversal:…` / `ledger:adjustment:…`), which pairs 1:1 with the signed `platform_fee_adjustments.idempotency_key` used by the future-cycle correction sweep. |
| `billable_at` | timestamptz | yes | `earned`: stamped at validation. `reversal`/`adjustment`: the correction's business date — the eligibility date for the future-cycle correction sweep. |
| `created_at` | timestamptz | no | append-only (no `updated_at`) |

- **Append-only:** BEFORE UPDATE trigger blocks changes to monetary/snapshot columns (permits only
  `status` + `subscription_invoice_item_id`); BEFORE DELETE trigger blocks all deletes. Reversal/adjustment
  are **additive** rows referencing the original via `reversed_entry_id`.
- **Future-cycle correction sweep (backend closure):** a `reversal`/`adjustment` row whose original was
  invoiced follows the same billing lifecycle as an earned row — `pending → aggregated → invoiced` — when it
  is swept into a later invoice's signed `type='adjustment'` line (its signed contribution = the paired
  `platform_fee_adjustments.amount_minor`). The applied negative net is capped so the invoice total stays
  non-negative; un-applied corrections stay `pending` for a later cycle. A correction of a never-invoiced
  original is never swept (its original was already dropped from the rollup by the `reversed`/`adjusted`
  marker).
- **Invariants:** `client_shifted + merchant_absorbed = gross` (DB CHECK); `merchant_liability = gross`
  (DB CHECK); currency coherence across source/config.
- **Idempotency:** partial-unique index on `(source_invoice_id, source_invoice_item_id, validation
  allocation)` for `earned` rows so a replayed validation creates no duplicate; one entry cannot be
  aggregated twice (a partial-unique/guard on `subscription_invoice_item_id`).
- **Indexes:** `UNIQUE(ulid)`; `(merchant_id, status)`; `(source_invoice_id)`; `(subscription_invoice_item_id)`.
- **Lifecycle spec:** `docs/architecture/state-machines/platform-fee-ledger-entry.md`.
- **Audit:** `platform_fee.original_recorded/_became_billable/_aggregated/_reversed/_adjusted`.
  **Retention:** permanent (financial). **Factory:** `PlatformFeeLedgerEntryFactory`. **Backfill:** none.
- **Positive:** created at validation; correct tier allocation; largest-remainder item sums reconcile;
  aggregated once. **Negative:** recording-only creates nothing; fixed-only creates nothing; monetary
  UPDATE/DELETE blocked; cross-tenant source 404; over-reversal rejected.

### `platform_fee_adjustments` (Plan §13.10; migration `2026_07_13_000003`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | `UNIQUE` |
| `merchant_id` | bigint | no | FK `merchants(id)` RESTRICT; `BelongsToMerchant` |
| `branch_id` | bigint | yes | FK `branches(id)` RESTRICT |
| `platform_fee_ledger_entry_id` | bigint | no | FK `platform_fee_ledger_entries(id)` RESTRICT |
| `adjustment_type` | varchar | no | CHECK ∈ (`reversal`,`partial_refund`,`correction`,`dispute_resolution`); `PlatformFeeAdjustmentType` |
| `amount_minor` | bigint | no | signed additive amount (integer minor units) |
| `currency` | char(3) | no | uppercase; matches the entry |
| `reason` | text | no | non-empty (sanitized) |
| `source_reference` | varchar | yes | void/refund/correction/dispute reference |
| `effective_date` | date | no | business date (`Africa/Nairobi`); period-lock enforced |
| `created_by` | bigint | no | FK `users(id)` RESTRICT |
| `approved_by` | bigint | yes | FK `users(id)` RESTRICT (maker/checker) |
| `created_at` | timestamptz | no | append-only |

- **Append-only:** fully immutable after insert (BEFORE UPDATE/DELETE trigger). Cannot exceed the remaining
  reversible balance of the target entry unless a separately approved correction. Idempotent per source
  correction event (partial-unique index). Period-lock enforced; no self-approval where maker/checker.
- **Retention:** permanent. **Factory:** `PlatformFeeAdjustmentFactory`. **Backfill:** none.
- **Positive:** reversal on void = full offset; partial refund = proportional. **Negative:** over-adjust
  rejected; locked period blocks; self-approval blocked.

### `platform_fee_disputes` (Plan §13.10 [Correction 3]; migration `2026_07_13_000004`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | `UNIQUE` |
| `merchant_id` | bigint | no | FK `merchants(id)` RESTRICT; `BelongsToMerchant` |
| `branch_id` | bigint | yes | FK `branches(id)` RESTRICT |
| `platform_fee_ledger_entry_id` | bigint | yes | FK `platform_fee_ledger_entries(id)` RESTRICT |
| `subscription_invoice_id` | bigint | yes | FK `subscription_invoices(id)` RESTRICT |
| `reason` | text | no | non-empty (sanitized); `CHECK (ledger entry OR subscription invoice present)` |
| `status` | varchar | no | CHECK ∈ (`open`,`under_review`,`resolved`,`rejected`); `PlatformFeeDisputeStatus` (**no** `escalated`) |
| `assigned_reviewer` | bigint | yes | FK `users(id)` RESTRICT |
| `evidence_file_id` | bigint | yes | FK `uploaded_files(id)` RESTRICT (private file domain) |
| `resolution_note` | text | yes | required on resolve/reject |
| `created_by` | bigint | no | FK `users(id)` RESTRICT |
| `resolved_by` | bigint | yes | FK `users(id)` RESTRICT |
| `resolved_at` | timestamptz | yes | |
| `created_at`/`updated_at` | timestamptz | no | |

- **Scope:** tenant/branch. Money-changing resolution creates a `platform_fee_adjustments` row and
  **never** edits the original ledger amount. Evidence uses the existing private file domain.
- **Lifecycle spec:** `docs/architecture/state-machines/platform-fee-dispute.md`.
- **Audit:** `platform_fee.dispute_created/_review_started/_resolved/_rejected`. **Retention:** permanent.
  **Factory:** `PlatformFeeDisputeFactory`.
- **Positive:** permitted actor creates; valid transitions; money-change resolution → adjustment.
  **Negative:** cross-tenant source 404; invalid skip rejected; Audit/Front-Office/Personnel denied.

### Additive percentage-fee snapshot columns on existing tables (expand)

- **`invoices` total-arithmetic CHECK (P17; migration `2026_07_13_000007`, expand):** the shipped
  `invoices_total_arithmetic_check` is dropped/recreated (shipped migration untouched) to add
  `+ COALESCE(platform_fee_client_shifted_minor, 0)`, so shared/business_centric percentage invoices may
  include the client-shifted amount in `total_minor` (the client pays it; payments validate against it).
  Backward-compatible — existing/fixed-only rows have NULL → 0.
- **`invoices` (P17; migration `2026_07_13_000005`, expand):** nullable
  `platform_fee_configuration_id` (FK RESTRICT), `platform_fee_billing_mode_snapshot`,
  `platform_fee_rate_bps_snapshot`, `platform_fee_tier_snapshot`, `platform_fee_basis_type_snapshot`,
  `platform_fee_shared_split_snapshot`, `platform_fee_currency`, `platform_fee_gross_minor`,
  `platform_fee_client_shifted_minor`, `platform_fee_resolved_at`. Snapshot-coherence CHECK (all-null or
  complete). Existing finalized invoices keep NULL; fixed-only leaves NULL. **No shipped P17 migration
  edited.**
- **`invoice_items` (P17; migration `2026_07_13_000006`, expand):** nullable
  `platform_fee_item_gross_minor`, `platform_fee_item_client_shifted_minor`,
  `platform_fee_item_absorbed_minor` (largest-remainder provenance). Item shares reconcile to the header
  snapshot (test-enforced).
- **`platform_fee_ledger_entries → subscription_invoice_items`:** the aggregation link lives on the ledger
  (`subscription_invoice_item_id`); the existing `platform_fee_rollup` line `type` (§13.10, shipped 20B)
  is reused — **no** schema change to `subscription_invoice_items`.

## Phase 20F — Compensation plan setup and commission rules (Plan §59, §80; Correction 19; HR)

**Configuration only.** These three tables define *how personnel will earn*. They create **no**
earned financial fact: no `salary_ledger`, no `commission_ledger`, no `compensation_adjustments`
(all **Phase 20G**), and no payout runs/items or earnings statements/queries (all **Phase 20H**).
They also carry no Wallet/provider concern (**Phase 20D-W**, Gate W CLOSED).

Sources: Plan §59 (Compensation-Plan Management), Plan §80 (Phase 20F entry), Scope §12.1–§12.9,
Scope §18.3. Gate decisions: `docs/proof/phase-20f.md` (F1–F10).
HR-domain cross-reference: `docs/architecture/data-dictionary/branches-and-staff.md`
(`staff_profiles` is the compensation subject).

**Ownership (F2):** all three are **branch-owned** — non-null `merchant_id` + `branch_id`,
composite FK → `merchant_branches(id, merchant_id)`, `BelongsToMerchant` + `BelongsToBranch`,
registered in `TenantOwnership::BRANCH_OWNED` / `::MODELS` (`'branch'`) / `::COMPOSITE_CONSISTENCY`.
Subject is `staff_profile_id` (Plan §59: "one active plan per staff profile, branch, and date";
Scope §12.9 hard rule: "one active compensation plan per personnel per branch at a time").

### `commission_rules` (Plan §59, Scope §12.7 Step 3A / §18.3; Phase 20F)

HR-controlled commission **configuration**. A **sibling** record referenced by
`personnel_compensation_plans.commission_rule_id` (Scope §18.3 is decisive) — **not** a child of the
plan, and **not** a ledger.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | public id + route key; `UNIQUE` |
| `merchant_id` | bigint | no | FK `merchants(id)`; tenant scope |
| `branch_id` | bigint | no | FK `merchant_branches(id)`; composite FK `(branch_id, merchant_id)` → `merchant_branches(id, merchant_id)` |
| `calculation_type` | varchar(16) | no | CHECK ∈ (`percentage`,`fixed_amount`) |
| `percentage_basis_points` | int | yes | required iff percentage; `CHECK (… IS NULL OR … BETWEEN 0 AND 10000)` |
| `fixed_amount_minor` | bigint | yes | required iff fixed; `CHECK (… IS NULL OR … >= 0)`; integer minor units (ADR-005) |
| `currency` | char(3) | yes | required iff fixed; `CHECK (currency IS NULL OR (currency = upper(currency) AND char_length(currency) = 3))` |
| `calculation_basis` | varchar(32) | no | CHECK ∈ (`service_price`,`invoice_item_total`,`paid_amount`,`net_after_discount`) — Scope §12.7 "Commission basis" |
| `applies_to` | varchar(24) | no | CHECK ∈ (`all_services`,`selected_services`,`service_category`) — Scope §12.7 "Applies to" |
| `service_category_id` | bigint | yes | FK `service_categories(id)` ON DELETE RESTRICT; required iff `applies_to='service_category'`, else null |
| `applies_to_preferred_personnel_fee` | boolean | no | **default `false`** — F6 basis-inclusion flag (Plan §59; Scope §969) |
| `effective_from` | date | no | inclusive start |
| `effective_to` | date | yes | exclusive end; NULL = ongoing |
| `status` | varchar(16) | no | CHECK ∈ (`draft`,`pending_approval`,`scheduled`,`active`,`superseded`,`expired`,`rejected`,`cancelled`) |
| `notes` | text | yes | internal HR note (Scope §12.7) |
| `change_reason` | text | no | non-empty; `CHECK (char_length(btrim(change_reason)) > 0)` |
| `created_by` | bigint | no | FK `users(id)` ON DELETE RESTRICT |
| `approved_by` | bigint | yes | FK `users(id)` ON DELETE RESTRICT |
| `approved_at` | timestamptz | yes | |
| `created_at`/`updated_at` | timestamptz | no | |

- **Value-shape CHECK (DB-authoritative, F4):** percentage ⇒ `percentage_basis_points` present and
  `fixed_amount_minor` + `currency` null; fixed ⇒ `fixed_amount_minor` + `currency` present and
  `percentage_basis_points` null. Exactly one calculation value. **Never float.**
- **Applies-to CHECK:** `service_category` ⇒ `service_category_id` not null; `all_services` /
  `selected_services` ⇒ `service_category_id` null. (`selected_services` membership is carried by the
  plan's configured selection surface; Phase 20F stores configuration only.)
- **Effective range:** `CHECK (effective_to IS NULL OR effective_to > effective_from)`.
- **F6 semantics:** `applies_to_preferred_personnel_fee = true` ⇒ the Phase 20A preferred-personnel
  fee **is included** in the future commission **basis**; `false` ⇒ **excluded**. It is a
  basis-inclusion flag only — **not** a separate basis, **not** a rate modifier, **not** a payout
  trigger, **not** an earned row. **Phase 20G** consumes it when earning commission.
- **Immutability + supersede (F7):** an `active`/`scheduled` rule's monetary terms are immutable;
  a change **supersedes** with a new version (`active → superseded`) — never an in-place edit.
  A previously active rule is **ended, not deleted** (Scope §12.7 Step 3C).
- **F4 residual:** Scope §12.7 mentions a "configured merchant/platform maximum" commission
  percentage; no such configuration exists anywhere in the repository/Plan/Scope and Plan §59 does
  not require one, so the structural bound `0..10000` bp is the enforced ceiling. See
  `docs/proof/phase-20f.md` §F4.
- **Phase 20F does NOT:** resolve a rule against a business event, compute a commission amount, or
  create an earned row. Earning happens **only** at Finance validation in **Phase 20G** (Plan §61).
- **Indexes:** `UNIQUE(ulid)`; `(merchant_id, branch_id)`; `(merchant_id, branch_id, status, effective_from)`.
- **Audit:** `commission_rule.created` (warn), `.updated_draft` (info), `.ended` (high).
  **Retention:** permanent. **Factory:** `CommissionRuleFactory` (percentage + fixed states).

### `personnel_compensation_plans` (Plan §59, Scope §12.2–§12.9 / §18.3; Phase 20F)

Compensation model per personnel per branch. **One active plan per personnel per branch.**

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | public id + route key; `UNIQUE` |
| `merchant_id` | bigint | no | FK `merchants(id)`; tenant scope |
| `branch_id` | bigint | no | FK `merchant_branches(id)`; composite FK `(branch_id, merchant_id)` → `merchant_branches(id, merchant_id)` |
| `staff_profile_id` | bigint | no | FK `staff_profiles(id)` ON DELETE RESTRICT; composite FK `(staff_profile_id, merchant_id)` → `staff_profiles(id, merchant_id)` — the compensation **subject** |
| `compensation_model` | varchar(24) | no | **F1** CHECK ∈ (`commission_only`,`salary_plus_commission`,`salary_only`). **Distinct from `staff_profiles.employment_type`** (Scope §12.2 forbids overloading) |
| `salary_amount_minor` | bigint | yes | integer minor units; `CHECK (… IS NULL OR … > 0)` (Scope §12.7 3B/3C "salary amount > 0") |
| `salary_currency` | char(3) | yes | `CHECK (… IS NULL OR (… = upper(…) AND char_length(…) = 3))` |
| `salary_period` | varchar(16) | yes | CHECK ∈ (`monthly`,`weekly`,`daily`,`hourly`,`per_shift`) — Plan §60 cadences; monthly recommended at launch |
| `salary_payout_day` | smallint | yes | optional (Scope §12.7 3B/3C); `CHECK (… IS NULL OR … BETWEEN 1 AND 31)` |
| `commission_rule_id` | bigint | yes | **F5** FK `commission_rules(id)` ON DELETE RESTRICT; composite FK `(commission_rule_id, merchant_id)` → `commission_rules(id, merchant_id)` |
| `effective_from` | date | no | inclusive start |
| `effective_to` | date | yes | exclusive end; NULL = ongoing |
| `status` | varchar(20) | no | **Scope §12.9** CHECK ∈ (`draft`,`pending_approval`,`scheduled`,`active`,`expired`,`superseded`,`rejected`,`cancelled`) |
| `is_backdated` | boolean | no | default `false`; **F8** set at submission when `effective_from` < current `Africa/Nairobi` business date |
| `supersedes_plan_id` | bigint | yes | FK `personnel_compensation_plans(id)` ON DELETE RESTRICT; set on a supersede version |
| `notes` | text | yes | internal HR note |
| `change_reason` | text | no | non-empty; `CHECK (char_length(btrim(change_reason)) > 0)` (Scope §12.7: HR must provide a reason) |
| `created_by` | bigint | no | FK `users(id)` ON DELETE RESTRICT — the maker |
| `submitted_by` | bigint | yes | FK `users(id)` ON DELETE RESTRICT |
| `submitted_at` | timestamptz | yes | |
| `approved_by` | bigint | yes | FK `users(id)` ON DELETE RESTRICT — the checker; **never equal to `submitted_by`** (maker/checker, F8) |
| `approved_at` | timestamptz | yes | |
| `rejected_by` | bigint | yes | FK `users(id)` ON DELETE RESTRICT |
| `rejected_at` | timestamptz | yes | |
| `created_at`/`updated_at` | timestamptz | no | |

- **Model-shape CHECKs (DB-authoritative, F1 — Plan §59):**
  - `commission_only` ⇒ `salary_amount_minor`/`salary_currency`/`salary_period` **null** **and**
    `commission_rule_id` **not null**;
  - `salary_only` ⇒ salary fields **not null** **and** `commission_rule_id` **null**;
  - `salary_plus_commission` ⇒ salary fields **not null** **and** `commission_rule_id` **not null**.

  This is the DB-level guarantee behind Plan §80's named test "salary-only has no commission rule"
  and Scope §12.5 ("no commission ledger entries are created for this personnel" — 20G honours it).
- **Maker/checker CHECK (F8):** `CHECK (approved_by IS NULL OR submitted_by IS NULL OR approved_by <> submitted_by)`
  — the submitter can never be recorded as their own approver.
- **One active plan per personnel per branch (F3 — Plan §59, Scope §12.9):**
  `EXCLUDE USING gist (staff_profile_id WITH =, branch_id WITH =, daterange(effective_from, effective_to, '[)') WITH &&) WHERE (status IN ('active','scheduled'))`.
  Half-open ⇒ **adjacent windows are legal**; `draft`/`pending_approval`/`superseded`/`expired`/
  `rejected`/`cancelled` **never block**. `CHECK (effective_to IS NULL OR effective_to > effective_from)`.
  `btree_gist` is already installed by the merged Phase 20A migration `2026_07_10_000005`.
- **Immutability (F7, DB-authoritative):** a `BEFORE UPDATE` trigger rejects any change to
  `merchant_id`, `branch_id`, `staff_profile_id`, `compensation_model`, `salary_amount_minor`,
  `salary_currency`, `salary_period`, `salary_payout_day`, `commission_rule_id`, `effective_from`, or
  `effective_to` **once `status <> 'draft'`**. Terms change **only** by supersede
  (new version + `compensation_plan_history` row + audit + reason + actor); the prior row moves to
  `superseded`. Never destructively edited; never deleted. Mid-period changes split by effective
  date (Plan §59) — **Phase 20G** performs the split arithmetic.
- **Configuration grants no access (Plan §59):** a compensation plan never grants login, role, branch
  assignment, availability, or service eligibility.
- **Lifecycle spec:** `docs/architecture/state-machines/personnel-compensation-plan.md`.
- **Indexes:** `UNIQUE(ulid)`; `UNIQUE(id, merchant_id)` (composite-FK target);
  `(merchant_id, branch_id)`; `(merchant_id, branch_id, staff_profile_id, status, effective_from)`
  (resolution index); `(commission_rule_id)`.
- **Audit:** `compensation.plan.created` (warn), `.updated_draft` (info), `.submitted` (warn),
  `.approved` (high), `.rejected` (warn), `.cancelled` (warn), `.superseded` (high),
  **`.backdated_change_approved` (critical)** — Plan §59 requires critical severity for a backdated
  change. **Retention:** permanent. **Factory:** `PersonnelCompensationPlanFactory` (one state per
  compensation model + lifecycle states).

### `compensation_plan_history` (Plan §59, §80; Scope §12 "compensation change history"; Phase 20F)

**Append-only** compensation change history. No UPDATE, no DELETE.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | public id + route key; `UNIQUE` |
| `merchant_id` | bigint | no | FK `merchants(id)`; tenant scope |
| `branch_id` | bigint | no | FK `merchant_branches(id)`; composite FK `(branch_id, merchant_id)` → `merchant_branches(id, merchant_id)` |
| `compensation_plan_id` | bigint | no | FK `personnel_compensation_plans(id)` ON DELETE RESTRICT; composite FK `(compensation_plan_id, merchant_id)` → `personnel_compensation_plans(id, merchant_id)` |
| `staff_profile_id` | bigint | no | FK `staff_profiles(id)` ON DELETE RESTRICT; denormalized subject for history reads |
| `event` | varchar(32) | no | CHECK ∈ (`created`,`updated_draft`,`submitted`,`approved`,`activated`,`rejected`,`cancelled`,`superseded`,`expired`) |
| `from_status` | varchar(20) | yes | null on `created` |
| `to_status` | varchar(20) | no | |
| `changed_fields` | jsonb | yes | masked field-level diff of configured terms (no secrets, no PII beyond the subject) |
| `was_backdated` | boolean | no | default `false` — F8 |
| `change_reason` | text | no | non-empty; `CHECK (char_length(btrim(change_reason)) > 0)` |
| `actor_user_id` | bigint | no | FK `users(id)` ON DELETE RESTRICT |
| `effective_from` | date | no | the effective date of the version this row describes |
| `created_at` | timestamptz | no | append-only; **no `updated_at`** |

- **Append-only:** a `BEFORE UPDATE OR DELETE` trigger raises — history is never rewritten
  (the `audit_logs` append-only precedent, Guardrail 5). Financial-configuration history is
  permanent.
- **`activated` (Increment 3 correction):** the `scheduled → active` boundary is a real transition
  and is the symmetric partner of `expired` (already listed). It was omitted from this table's
  original `event` list while the plan state machine defined the transition + its
  `compensation.plan.activated` audit event — a documentation omission that propagated into the
  Increment 2 CHECK. Recording activation as `approved` would collapse two distinct lifecycle
  moments; recording it as `updated_draft` would be false; omitting it would make activation
  invisible in compensation history. Enum, CHECK, both state-machine specs and this dictionary are
  now in parity, proven by `Phase20FEnumParityTest`. See `docs/proof/phase-20f.md` (Increment 3).
- **Written inside the same transaction** as the plan transition that produced it.
- **Not a ledger:** it records *configuration changes*, never money owed, accrued, earned, or paid.
- **Indexes:** `UNIQUE(ulid)`; `(merchant_id, branch_id)`;
  `(merchant_id, branch_id, staff_profile_id, created_at)`; `(compensation_plan_id)`.
- **Permission:** read via `compensation.history.view` (HR, branch-scoped; the canonical successor of
  the retired legacy `commissions.view`). **Factory:** `CompensationPlanHistoryFactory`.

### Not created by Phase 20F (owner phases)

| Table / concern | Owner |
|---|---|
| `salary_ledger`, `commission_ledger`, `compensation_adjustments`, earned commission rows, salary accrual scheduler | **20G** |
| `personnel_payout_runs`, `personnel_payout_items`, `personnel_earnings_queries`, earnings statements, mark-paid | **20H** |
| Merchant-Administrator compensation summary (`merchant.compensation_summary.view`) | **20H** |
| Wallet payment/settlement/collections | **20D-W** (Gate W) |

The existing Phase 18B `commission_handoff_events` seam is the durable hand-off Phase 20G will
consume. **Phase 20F does not modify it.**

---

## Phase 20G — Salary accrual and commission processing (Plan §60, §61, §13.12; Correction 19; financial)

Phase 20G creates the earned/accrued financial facts 20F configured. Money is integer minor units
(ADR-005; round-half-up + largest-remainder residual). All four tables are **branch-owned**
(merchant_id + branch_id; composite FK `(branch_id, merchant_id)` → `merchant_branches(id, merchant_id)`).
Ledgers + adjustments are **append-only at the database** (DELETE blocked; UPDATE limited to the
lifecycle `status` and the Phase 20H `payout_item_id` link, which is nullable + UN-CONSTRAINED until
20H adds its FK by expand migration — ADR-004).

### `commission_ledger` (Plan §61, §13.12 Correction 2.3; migration `2026_07_17_000002`)

Append-only earned/reversal commission facts. An `earned` row is created **only** at Finance
validation, driven by the durable `commission_handoff_events` outbox; the 20G consumer allocates the
validation event's validated amount across eligible invoice items (largest-remainder) and writes one
earned row per `(payment_validation_event_id, invoice_item_id, staff_profile_id)`, snapshotting
`compensation_plan_id`, `commission_rule_id`, `calculation_basis_minor`, `rate_basis_points` /
`fixed_rate_minor`, `currency`, and the source identities. `salary_only` plans never generate rows.
Corrections are additive: a `reversal` row is the **exact negative** of the original (never recomputed),
references it via `source_entry_id`, and carries a `reversal_reason`
(`invoice_voided|payment_reversed|refund_finalized|manual_adjustment|correction`); an already-paid
reversal is a negative `compensation_adjustments` row instead (paid history is never rewritten).
There is **at most one reversal per original** (`UNIQUE (source_entry_id) WHERE entry_type='reversal'`).
Because there is no immutable item-level refund attribution, the 20G consumer reverses a validation
event's earned rows **only once the entire validated allocation has been refunded** (cumulative
finalized refunds = validated amount); a partial refund is a valid no-effect event and an impossible
over-refund fails closed (Increment 4; product-owner resolution 2026-07-18). Invoice void does not
invalidate the validated allocation, so it produces no commission reversal.
`entry_type ∈ (pending_preview, earned, reversal, adjustment)` — `pending_preview` is carried for
canonical completeness (Phase 16C computes previews on the fly; 20G persists none). `status ∈
(pending, earned, included_in_payout, paid, reversed, adjusted, cancelled)`. Composite `(id,
merchant_id)` FKs to every parent (staff/plan/rule/invoice/invoice_item/session/payment_record/
validation_event/self) prevent any cross-merchant reference; the migration also adds the additive
`invoice_items_id_merchant_id_unique` index as an FK target. **Basis (G4):** computed against the
shipped 20F `CommissionCalculationBasis` enum (`service_price`, `invoice_item_total`, `paid_amount`,
`net_after_discount`); `applies_to_preferred_personnel_fee` includes the item's
`preferred_personnel_fee_minor` in the basis; per-item earned commission is capped at the item's
eligible validated allocation (G5). **Idempotency:** UNIQUE
`(payment_validation_event_id, invoice_item_id, staff_profile_id, entry_type) WHERE entry_type='earned'`;
UNIQUE `(source_entry_id) WHERE entry_type='reversal'`.

### `salary_ledger` (Plan §60, §13.12; migration `2026_07_17_000003`)

Append-only salary accrual facts. The scheduler creates one `accrual` per payable **pay-period
segment** in Africa/Nairobi under the **Actual/Actual calendar-day** convention (G8 product-owner
decision): monthly denominator = actual days in the Nairobi month (28–31); weekly = ISO Mon–Mon,
denominator 7; half-open plan windows `[effective_from, effective_to)`. `pay_period_start`/`_end`
store the **segment's** payable range; `pay_period_segment_key` is the deterministic segment id.
Mid-period plan changes, prospective suspension `pause`, resumption, and termination each split the
period into separate segments; the period total is rounded once (round-half-up), floored per segment,
and the residual allocated by largest remainder. `entry_type ∈ (accrual, adjustment, reversal)`;
`status ∈ (pending, included_in_payout, paid, reversed, adjusted)`. `source_entry_id` +
`pay_period_segment_key` extend the minimal §13.12 columns for reversal provenance + segment
idempotency (commission_ledger pattern). **daily/hourly/per_shift is NOT accrued** — no approved
attendance/shift source exists (G9); the domain guard fails closed. **Idempotency:** UNIQUE
`(compensation_plan_id, staff_profile_id, pay_period_segment_key, entry_type) WHERE entry_type='accrual'`;
UNIQUE `(source_entry_id) WHERE entry_type='reversal'`.

### `compensation_adjustments` (Plan §60/§61, §13.12; migration `2026_07_17_000004`)

Append-only additive adjustments. Two sources: a Finance **manual** adjustment
(`compensation.adjustment.create`, MFA + fresh step-up, high-severity audit) and a system negative
adjustment offsetting an **already-paid** ledger row (`paid_commission_reversal` / `paid_salary_reversal`
referencing the paid source; Plan §61 — paid history never rewritten). `amount_minor` is non-zero (may
be negative). `adjustment_type`, `created_by`, `source_*_ledger_id`, and the 20H `payout_item_id` link
extend the minimal §13.12 columns for provenance + idempotency. **Idempotency:** UNIQUE per
`source_commission_ledger_id` and per `source_salary_ledger_id` (one paid-reversal per paid source row).

### `commission_rule_services` (§9.1 product-owner decision; migration `2026_07_17_000001`)

Normalized selected-services membership substrate that closes the 20F seam (`applies_to =
'selected_services'` had no membership source). One immutable row per `(commission_rule_id,
service_id)`; **configuration only — no money.** Composite `(id, merchant_id)` FKs to
`commission_rules` and `services` enforce merchant consistency; a BEFORE INSERT trigger proves
`rule.branch = service.branch = membership.branch`. Membership is mutable **only while the rule is
`draft`** (guard trigger; supersede-not-edit), and a second trigger on `commission_rules` blocks a
`selected_services` rule from leaving draft with zero memberships. Finance validation earns commission
for a `selected_services` rule only when the item's `service_id` is in the membership set; a non-draft
rule with no substrate fails closed (never falls back to `all_services`). No JSON list.

### `personnel_compensation_plans.suspension_salary_policy` (Plan A-11; §60; G10; expand `2026_07_17_000005`)

Forward-only EXPAND adding the canonical §13.12 column the shipped Phase 20F migration omitted (the
20F migration is never edited — ADR-004). `varchar NOT NULL DEFAULT 'continue'`, CHECK
`('continue','pause')`. Settled default `continue` (A-11): salary accrues during suspension. A
prospective `pause` override is expressed by **superseding** the plan to a new effective-dated version
(never a retroactive edit), so the column is part of a plan version's frozen terms — a second BEFORE
UPDATE trigger (additive; the shipped F7 immutability trigger is untouched) blocks changing it once the
plan leaves draft. The salary segmenter treats a `pause` version window as non-payable. Backed by the
`SuspensionSalaryPolicy` enum (Phase20GEnumParityTest).

**Accrual cadence & cutoff (§6.3):** the `compensation:accrue-salary` scheduler accrues at the CLOSED
pay-period boundary — only a period whose exclusive end has arrived in Africa/Nairobi is processed, so
no future day and no provisional row; all segments (incl. mid-period plan-change splits) accrue together
at close. Lock order: staff subject → existing salary-ledger identity rows → insert → audit. Commission
is NOT scheduled — it is earned by the `commission_handoff_events` consumer at Finance validation.

---

## Phase 20H — Payout runs and earnings (Plan §62, §63, §13.12, §25.4/§25.5; Correction 19; financial)

Consumes the 20G ledgers into an internal payout workflow + personnel earnings surfaces. **Servana
moves no money** — mark-paid records an EXTERNAL payment; no provider/Wallet call; no dependency on
Gate W (CLOSED). Three new branch-owned tables + three EXPAND FKs on the 20G ledgers.

### `personnel_payout_runs` (Plan §62, §13.12; migration `2026_07_20_000001`)

Branch-owned internal payout run. HR drafts/submits (freeze); Finance verifies/approves-standard/
marks-paid; Merchant Admin approves high-value. `status` CHECK (8):
`draft/submitted/finance_verified/pending_merchant_admin_approval/approved/paid/rejected/cancelled`
(state machine: `personnel-payout-run.md`; enum `PayoutRunStatus`). `high_value_threshold_snapshot_minor`
(bigint nullable, CHECK null-or-`>=0`) is snapshotted at creation from
`merchant_subscriptions.high_value_payout_threshold_minor` (Phase 20A — never hardcoded; null ⇒
ordinary approval). **`currency` char(3)** completes the §13.12 summary so a run is single-currency
(no-cross-currency invariant). `gross_total_minor` bigint is signed (clawbacks may net negative).
Actor columns `created_by`(HR)/`submitted_by`/`verified_by`/`approved_by`/`paid_by`/`rejected_by`;
`rejection_reason`; `external_payment_reference_encrypted` (encrypted at rest, never logged);
`paid_at`. Composite `(branch_id, merchant_id)` FK; `(id, merchant_id)` unique = the payout-item FK
target. CHECKs: status enum, currency uppercase, `period_end >= period_start`, threshold null-or-`>=0`.
No append-only trigger (the run is mutable through its lifecycle; freeze is enforced by the state
machine + the item guard).

### `personnel_payout_items` (Plan §62, §13.12; migration `2026_07_20_000002`)

Branch-owned FROZEN snapshot line; one per `(payout_run, staff_profile, currency)` (UNIQUE). Snapshots
eligible unpaid 20G ledger facts (`salary_ledger`, `commission_ledger`, approved
`compensation_adjustments`) into `salary_amount_minor`/`commission_amount_minor`/`adjustment_amount_minor`
(all signed) with `gross_amount_minor = sum` (DB CHECK); `source_ledger_refs` jsonb holds the exact
snapshotted row ids `{salary:[…], commission:[…], adjustment:[…]}`. **Never recomputed** from current
plans/rules. `currency` = run currency. `status` mirrors the run (enum `PayoutItemStatus`; same 8
values). Composite `(branch_id, merchant_id)`, `(payout_run_id, merchant_id)` RESTRICT,
`(staff_profile_id, merchant_id)` FKs; `(id, merchant_id)` unique = the ledger `payout_item_id` FK
target. **Freeze guard:** DELETE allowed only while `status='draft'`; UPDATE blocks all snapshot columns
(only `status`/`updated_at` transition). Integer minor units.

### `earnings_queries` (Plan §63, §13.12; migration `2026_07_20_000003`)

Branch-owned + personnel own-scope (`staff_profile_id` from membership; arbitrary ids rejected). Query
against one own fact: `subject_type` CHECK `commission_ledger/salary_ledger/payout_item` + `subject_id`
(validated in-scope by the action; no polymorphic FK). `query_type` CHECK
`commission_disagreement/salary_disagreement/payout_missing/payout_amount/statement_request/other`
drives `assigned_role` (`finance`/`hr`) routing; the resolution permission is always
`earnings_query.respond` (Finance). `status` CHECK `open/assigned/resolved/rejected` (state machine:
`earnings-query.md`; enum `EarningsQueryStatus`). **Resolution never mutates a ledger** — a monetary
correction is a separate `compensation_adjustments` row referenced by `resolved_adjustment_id`
(nullable FK). `assigned_to`/`responded_by` (users), `resolution_note`, `responded_at`. Composite
`(branch_id, merchant_id)` + `(staff_profile_id, merchant_id)` FKs; CHECKs on all enums + non-empty body.

### `personnel_payout_items.earnings_statement_file_id` EXPAND (migration `2026_07_20_000007`; Increment 4)

Nullable FK `→ uploaded_files(id)` (`nullOnDelete`) linking a PAID payout item to its generated
earnings-statement PDF (Plan §63/§65; 10F private file domain). Deliberately **outside** the Increment-2
item freeze guard's `ROW()` comparison, so `GenerateEarningsStatement` sets it once on a paid item while
every snapshot column stays frozen. Once set it is never rewritten — the statement is **idempotent +
immutable** (a later correction is a new adjustment + a future statement, never a rewrite). The statement
file is `purpose = earnings_statement` with `owner_user_id` = the personnel user, so download is
own-scope-authorised by `FileAccessService` (no extra permission).

### Ledger `payout_item_id` EXPAND FKs (migrations `2026_07_20_000004/000005/000006`)

Phase 20G created `commission_ledger.payout_item_id`, `salary_ledger.payout_item_id`, and
`compensation_adjustments.payout_item_id` as nullable, UN-CONSTRAINED columns (their append-only guards
already permit only `status`/`payout_item_id` to transition). Phase 20H adds the composite FK
`(payout_item_id, merchant_id) → personnel_payout_items(id, merchant_id)` on each by **expand** (the
shipped 20G migrations are never edited; ADR-004). PostgreSQL MATCH SIMPLE skips the FK while
`payout_item_id` is NULL (unlinked earned/pending/approved row), so no backfill is needed. **Claim** =
setting `payout_item_id` at submit; **release** = clearing it on reject/cancel; ledger **status**
advances forward only (`earned/pending → included_in_payout → paid`) at mark-paid.

---

## Forbidden in Servana (never assign to Servana schema)

| Forbidden concern | Owner |
|---|---|
| Provider credentials / OAuth | Wallet |
| Raw provider callbacks / payloads | Wallet |
| Provider identifiers (checkout request IDs, etc.) | Wallet |
| Provider receipt-uniqueness enforcement | Wallet |
| Provider reconciliation ledger rows | Wallet |
| `services.mpesa` configuration keys | — (must not exist) |
| Direct Daraja/Safaricom API hostnames in executable config | — |

**Permitted terminology:** `mpesa_offline` as a **merchant-client payment method** enum value
(Phase 18A) — not platform billing provider integration.

---

## Retention and audit

Billing and reconciliation rows follow financial retention (Plan §13). Masked provider references
only in Servana; full provider detail remains in Wallet.

---

## Migration manifest

Entries are added to `docs/architecture/migrations/manifest.yaml` when each owning phase ships
its forward-only migrations — not in the adoption PR.
