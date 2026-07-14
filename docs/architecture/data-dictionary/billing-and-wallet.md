# Billing and Wallet Integration — Data Dictionary (Plan §13.9–§13.11; Phases 20A–20D-W)

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
