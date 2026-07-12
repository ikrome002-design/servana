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
- **Indexes:** index `(subscription_invoice_id)`.
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
