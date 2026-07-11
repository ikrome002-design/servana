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

| Table / column | Owner phase | Purpose |
|---|---|---|
| `merchant_subscriptions` | 20B | Subscription lifecycle; `merchants.billing_status` is access authority |
| `scheduled_plan_changes` | 20B | Next-cycle plan changes (no proration) |
| `subscription_invoices` | 20B | Issued invoice financial snapshot |
| `subscription_invoice_items` | 20B | Line items (plan fee, rollups, adjustments) |
| `subscription_invoices.wallet_payment_id` | 20B | Nullable; Wallet payment resource ID (populated 20D-W) |
| `subscription_invoices.wallet_registration_status` | 20B | `unregistered` \| `pending` \| `registered` \| `failed` |
| `subscription_invoices.wallet_registered_at` | 20B | Nullable timestamp |
| `subscription_invoices.account_reference` | 20B | Nullable until Wallet registration; immutable `SRV-PAY-*` once set (ADR-014) |

**Explicit non-deliverable in 20B:** no `RegisterInvoicePayment` outbox table, no registration
consumer, no Wallet HTTP client routes. Registration runtime is Phase 20D-W only (Correction 14.5).

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
