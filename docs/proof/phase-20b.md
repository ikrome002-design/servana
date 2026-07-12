# Phase 20B — Subscription Lifecycle and Subscription Invoices — Proof

> Lifecycle: **in_progress** (branch `phase-20b-subscription-lifecycle-invoices`, based on
> `origin/main` = `6813690ef5fa9f7d782532b49e2bca43c2afc112` = the Phase 20A PR #35 squash
> merge). One reviewed PR at the end; **not** `verified_complete` until that PR merges with
> green CI and truthful governance evidence. Proof appended per increment. Controlling
> sources: Plan §§13.9, 13.14–13.16, 20, 21, 22, 25 (§25.2/§25.4), 47, 48, 49, 50, 54, 65,
> 67, 70–71, 80 (Phase 20B), 81–82, 85; ADR-005 (round-half-up), ADR-011 (price sole
> source), ADR-014 (Wallet reference nullable/immutable). Exclusions per §9 assignment.

## Phase 20A merge reconciliation (recorded)

- **PR #35** "Phase 20A: Implement billing catalogue settings and fee rules" — **MERGED**
  into `main`. Squash merge `6813690ef5fa9f7d782532b49e2bca43c2afc112`; implementation head
  `a31cd000f84a0a19f1d8b526a4fdf5d01aefc090` (recorded once in the PR); final PR head
  `56a81bd305aacf3a7fb2ffa976d9a089591e3f41`; merged **2026-07-11 07:56:09Z**.
- CI: Backend / Frontend / Docker / Security / E2E—Playwright all **SUCCESS**.
- `reviewDecision`: **blank** — documented solo-maintainer governance condition, **not**
  independent reviewer approval.
- Reconciled `docs/proof/phase-20a.md` (header + lifecycle), `docs/PROGRESS.md`,
  `docs/CHANGELOG.md`, `docs/remediation/register.yaml` (REM-ENUM-001), and
  `docs/traceability/servana-requirements.csv` (SRV-BILLING-CAT-001) from
  `local_complete → verified_complete`. Phase 20E percentage-fee **ledger** kept as a
  separate 20E obligation. No historical failure/checkpoint evidence erased.

## Baseline verification (independent, at resume)

Branch `phase-20b-subscription-lifecycle-invoices`; HEAD == origin/main ==
merge-base(origin/main, HEAD) == `6813690…`; working tree clean; `git fsck --full` clean;
Docker Engine healthy; 10 Compose services running (`app, file-worker, mailpit, meilisearch,
minio, nginx, postgres, redis, scheduler, worker`); Laravel 12.62.0 executable; `migrate:status`
connects to PostgreSQL 16.

---

## Increment 1 — Specification gates, data dictionary, state machines, traceability skeleton

### Specification-gate decision table

| Gate | Question | Decision | Authority |
|---|---|---|---|
| **B1** | Trial anchor + plan binding timing | Trial anchor = **Merchant-Administrator creation timestamp** written to `merchant_subscriptions.trial_started_at`; the subscription is **created/bound during first-time setup** once plan+price are chosen; `trial_days_snapshot` captured from the effective `platform_billing_settings.default_trial_days` at binding; later settings changes never rewrite an existing trial; idempotent (no duplicate active subscription on replay/concurrency). | Plan §48; §13.9 line 907 ("Trial starts at Merchant Admin creation"); §25.2 Merchant Setup (`pending_setup → setup-complete`; first-time-setup routes only while pending); §13.9 (`plan_id`/`price_id` captured at issuance). **Resolved from evidence.** |
| **B2** | Terminal subscription (`cancelled`/`expired`) → which `merchants.billing_status`? | **Both → `suspended_billing`**, distinct reason codes `subscription_cancelled` / `subscription_expired` in `merchants.billing_status_reason` + audit context. `cancelled` projected **only at the effective terminal boundary** (a cancel-at-period-end request does not suspend early). Never auto-project terminal→`active`; recovery needs an explicit authorized re-subscription/recovery workflow (no payment/Wallet route fabricated in 20B). `read_only_grace` is transitional and never represents a terminal record. | `merchants.billing_status` CHECK = `{trialing, read_only_grace, active, overdue, suspended_billing}` (Plan line 732); §25.2 machine has no terminal arrow; §13.9 line 904 ("appropriate `merchants.billing_status`" unnamed). **Resolved by explicit product-owner decision** (Gate B2, Phase 20B Increment 1). |
| **B3** | Subscription invoice numbering substrate | **Reuse `invoice_number_sequences`** via a forward-only **expand** migration adding scope value `'subscription_invoice'` (drop+recreate the scope CHECK; never edit the shipped `2026_..._invoice_number_sequences` migration). Allocate gap-free per-merchant numbers under `SELECT … FOR UPDATE` keyed `(merchant_id, scope='subscription_invoice')` — an **independent counter**, never reusing merchant-client numbers, no invented table. | §13.15 line 1156 (`invoice_number_sequences` is scope-partitioned, `unique(merchant_id, scope)`); guardrail 12 (expand/contract, never edit shipped). **Resolved (owned via DD completion), documented.** |
| **B4** | Escalation idempotency boundary | Add **`period_boundary date NOT NULL`** to `billing_escalation_events` + **`UNIQUE(merchant_subscription_id, event_type, period_boundary)`**. Idempotency is enforced by the constraint, never by `created_at`. `period_boundary` = the computed current-period boundary date the event pertains to. | §54 / §13.15 line 1167 require idempotency per `(merchant_subscription_id, event_type, period boundary)` but the summary DDL omits the field. New table → resolved additively under change control. **Resolved (owned), documented.** |
| **B5** | Non-fixed billing mode before 20E | `IssueSubscriptionInvoice` **fails closed**: it asserts the effective `billing_mode == fixed_amount`; a `percentage_on_merchant_client_invoice` or `fixed_amount_plus_percentage_on_merchant_client_invoice` mode raises a typed error (`billing_mode_not_supported`, 422) and issues **nothing** — never a silently undercharged invoice. | §50 (fixed is the supported 20B mode); §51/§52 (percentage/fixed-plus-percentage → 20E, activated only when configured); Manifest §5 (no silent undercharge); guardrail "no silent failure handling". **Resolved (derived).** |

### Enum / DB mapping summary (from evidence)

- `merchant_subscriptions.status` CHECK ∈ `{trialing, active, read_only_grace, overdue, suspended_billing, cancelled, expired}` (§13.9 line 901).
- `merchants.billing_status` CHECK ∈ `{trialing, read_only_grace, active, overdue, suspended_billing}` (Plan line 732) — the request-authorization access authority (§22).
- **Projection map** (B2 applied): trialing→trialing · active→active · read_only_grace→read_only_grace · overdue→overdue · suspended_billing→suspended_billing · cancelled→suspended_billing (`subscription_cancelled`) · expired→suspended_billing (`subscription_expired`).
- `scheduled_plan_changes.status` ∈ `{scheduled, applied, cancelled}` (§13.9 line 909).
- `subscription_invoices.status` ∈ `{draft, issued, pending_payment, partially_paid, paid, overdue, payment_failed, reconciliation_required, void}`; `void` only, never `cancelled` (§13.9/§25.4).
- `subscription_invoice_items.type` ∈ `{plan_fee, platform_fee_rollup, sms_rollup, adjustment}` (§13.9 line 920). 20B fixed mode issues `plan_fee` only.
- `billing_escalation_events.event_type` ∈ `{reminder, grace_entered, overdue, suspended_billing, recovered}` (§13.15 line 1166).
- `subscription_invoices.wallet_registration_status` ∈ `{unregistered, pending, registered, failed}` default `unregistered` (§13.9 line 915).
- `billing_interval` ∈ `{weekly, bi_weekly, monthly, quarterly, annual}` (reuses Phase 20A `BillingInterval`).

### Interval date math (§49 — canonical, `Africa/Nairobi`)

weekly +7d · bi_weekly +14d · monthly +1 calendar month (end-of-month clamp) · quarterly +3
calendar months (same clamp) · annual +1 year (leap clamp Feb 29→Feb 28). Anchor day = issuance
day-of-month, preserved and clamped to the shortest month. One calculator used for period
boundaries, next-cycle plan changes, invoice periods, due dates, reminder/escalation boundaries.

### Wallet forward-compatibility (§49, ADR-014) — 20B ships columns only

`subscription_invoices` carries `account_reference` (null), `wallet_payment_id` (null, unique),
`wallet_registration_status` (`unregistered`), `wallet_registered_at` (null). **No** Wallet HTTP
client, **no** `RegisterInvoicePayment` outbox intent/table/consumer, **no** registration call.
Unregistered invoice PDFs render **"Payment reference pending — see your billing dashboard"**.
Registration + regeneration is Phase 20D-W.

### Permissions plan

- **Activate** (planned→active, real 20B routes): `merchant.subscription.view`,
  `merchant.subscription.plan_change`, `merchant.subscription.invoice.view`,
  `merchant.subscription.invoice.download`, `platform.registration_monitor.view`,
  `platform.merchant.view`, `platform.merchant.suspend`, `platform.merchant.reactivate`,
  `platform.merchant.deactivate`.
- **Reconcile legacy** (owning_phase 20B, canonical successor now live):
  `merchant.tier.update` → `merchant.subscription.plan_change`; `platform.merchants.govern`
  → `platform.merchant.suspend`.
- **Do NOT activate** (20D-W): `merchant.subscription.pay`, `merchant.subscription.pay_from_branch`,
  `merchant.subscription.pay_simple`, `subscription.payment_attempts.view`,
  `merchant.billing_attempts.view_detailed`. (`platform_fees.view`/`platform_fees.dispute`
  are null-successor percentage/dispute keys → left for 20E.)

### Frontend screens (inventory.json, phase == Phase 20B)

Merchant: `subscription-dashboard`, `plan-management`, `subscription-invoices`.
Platform: `platform-registration-monitoring`. Each needs a §27.1 spec before "implemented".

### Increment 1 deliverables (this increment)

- [x] Phase 20A merge reconciliation (proof-20a header+lifecycle, PROGRESS, CHANGELOG,
  register REM-ENUM-001, traceability SRV-BILLING-CAT-001) → `verified_complete`.
- [x] Specification-gate decision table (this file) — B1/B3/B4/B5 resolved from evidence/ownership;
  B2 resolved by product-owner decision.
- [x] Data dictionary Phase 20B column-level entries (`billing-and-wallet.md` §20B — five tables +
  `invoice_number_sequences` scope expand + `billing_status_reason` note).
- [x] State-machine specs: `merchant-subscription.md`, `merchant-billing-status.md`,
  `scheduled-plan-change.md`, `subscription-invoice.md`, `billing-escalation.md`.
- [x] Traceability skeleton rows: `SRV-SUBSCRIPTION-001`, `SRV-PLATFORM-GOVERNANCE-001`.
- [x] Phase 20B section in `docs/PROGRESS.md`.

**Increment 1 status: COMPLETE (documentation increment; no code, no migrations yet). No targeted
test run applies to Increment 1 — its doc-consuming guards (`MigrationManifestTest`,
`DataDictionary*`, `screenInventory`) run in Increment 2+ once the migrations/enums land.**

### Exclusions honoured (owner phases)

promotions/free-periods → 20C; Wallet account sync/registration/STK/PayBill/webhooks/payment
application/reversals/reconciliation/credits → 20D-W (after Gate W); percentage & fixed-plus-
percentage ledger/adjustments/disputes → 20E; compensation config → 20F; commission/salary
ledgers → 20G; payouts/earnings → 20H; R&E runtime → 21R-A/21R-B; notifications/reports → 21N;
personnel SMS → 21S; search → 22; release-wide security/export/responsive/dark/a11y hardening →
23; performance → 24; deployment/centralized alerting/runbooks → 25. `mpesa_offline` merchant-
client terminology preserved.

---

## Increment 2 — Migrations, enums, schema/constraint tests, PostgreSQL fresh-build proof (COMPLETE, green on PG16)

### Eight forward-only migrations (no collision; after `2026_07_10_000006`)

Ordered so dependencies exist before dependents:

1. `2026_07_11_000001_add_id_plan_id_unique_to_subscription_plan_prices` — **expand** of the 20A
   table: additive `UNIQUE(id, plan_id)` so 20B tables can enforce **price-belongs-to-plan** via
   composite FK (repository composite-key pattern; not a trigger). Shipped migration not edited.
2. `2026_07_11_000002_add_billing_status_to_merchants` — **expand**: `billing_status varchar(20)
   NOT NULL default 'trialing'` (CHECK 5 values, indexed) + `billing_status_reason text NULL`.
   Existing merchants receive the Plan-defined default (safe backfill). Deferred from Phase 17.
3. `…000003_create_merchant_subscriptions_table` — 7-status CHECK; interval CHECK; trial/period
   date-order CHECKs; `trial_days_snapshot>=0`; high-value threshold null-or-≥0; composite FK
   price↔plan; **partial `UNIQUE(merchant_id) WHERE status IN (non-terminal)`** = one current
   subscription; `UNIQUE(id, merchant_id)` composite-FK target.
4. `…000004_create_scheduled_plan_changes_table` — status CHECK; composite FK subscription↔merchant
   + target price↔plan; **partial `UNIQUE(merchant_subscription_id, effective_at) WHERE
   status='scheduled'`**.
5. `…000005_create_subscription_invoices_table` — 9-status + 4-wallet-status CHECKs; money
   non-negative + `discount≤subtotal` + `total=subtotal-discount` + `balance≤total`; uppercase
   currency; period order; **Wallet null/status coherence CHECK** (proves 20B `unregistered`
   defaults, ADR-014); composite FK price↔plan; per-merchant partial unique invoice-number;
   `UNIQUE(id, merchant_id)`.
6. `…000006_create_subscription_invoice_items_table` — type CHECK; sign CHECK (only `adjustment`
   negative); non-blank description; composite FK `(subscription_invoice_id, merchant_id)`.
7. `…000007_create_billing_escalation_events_table` — **append-only** (`created_at` only);
   event_type CHECK; `period_boundary date NOT NULL`; **`UNIQUE(merchant_subscription_id,
   event_type, period_boundary)`** = Gate B4 idempotency (never `created_at`); composite FKs.
8. `…000008_expand_invoice_number_sequences_add_subscription_invoice_scope` — **expand** scope
   CHECK to add `subscription_invoice` (Gate B3); independent per-merchant counter; shipped
   migration not edited; safe `down()`.

### Enums (backed, `values()` + parity guard)

`app/Domain/Billing/Enums/`: `MerchantSubscriptionStatus`(7), `MerchantBillingStatus`(5 — no
cancelled/expired), `ScheduledPlanChangeStatus`(3), `SubscriptionInvoiceStatus`(9),
`BillingEscalationEventType`(5), `WalletRegistrationStatus`(4), plus `SubscriptionInvoiceItemType`(4)
for the item cast. Reuses 20A `BillingInterval`(5). CHECK values are **hardcoded literals** in the
migrations (matching the 20A convention that satisfies the `servana.security.rawSqlConcat` Larastan
rule); `Phase20BEnumParityTest` parses `pg_get_constraintdef` and asserts DB CHECK ↔ PHP-enum parity.

### Models + factories + tenancy registration

Five models in `app/Domain/Billing/Models/` (`MerchantSubscription`, `ScheduledPlanChange`,
`SubscriptionInvoice`, `SubscriptionInvoiceItem`, `BillingEscalationEvent`) — all `BelongsToMerchant`,
ULID route keys, enum casts; `BillingEscalationEvent` sets `UPDATED_AT = null` (append-only). Five
matching factories build composite-FK-consistent parents. All five tables added to
`TenantOwnership::TENANT_OWNED` and their models to `MODELS` as `'tenant'` (**not** platform-exempt).
Eight manifest entries added (`docs/architecture/migrations/manifest.yaml`; 77 business migrations).

### Tests (green on PostgreSQL 16)

- `Phase20BSchemaTest` — **46 tests / 104 assertions**: existence, tenant-ownership (merchant_id
  NOT NULL + index, no branch_id, TENANT_OWNED not EXEMPT), one-current-subscription, terminal
  history coexistence, scheduled uniqueness, price↔plan composite FK, invoice arithmetic,
  uppercase currency, Wallet coherence (unregistered nulls; registered requires fields),
  per-merchant invoice-number uniqueness, item type/sign/tenant, escalation period-boundary
  idempotency, append-only (no `updated_at`), `merchants.billing_status` default + reason codes,
  independent number counters, and **no Wallet runtime tables**.
- `Phase20BEnumParityTest` — **9 tests / 21 assertions**: DB CHECK ↔ enum parity for all seven
  enums + scope expand + the "billing_status has no cancelled/expired" invariant.
- Guards: `TenantColumnCoverageTest`, `ModelTenancyTraitCoverageTest`, `MigrationManifestTest`,
  `FileMigrationManifestTest` — **18 tests / 307 assertions** green.
- No regression: `--group=billing,phase20a-schema` **142 passed / 295 assertions**.
- **Disposable PG16 proof:** `servana_p20b_check` created → `migrate:fresh --seed` (81 migrations +
  PermissionSeeder) green → dropped; dev `servana` untouched (forward `migrate` = additive; existing
  data preserved, `billing_status` defaulted). **Pint clean (1067)**; **Larastan L8 "No errors"**;
  `git diff --check` clean.

### Increment-2 failures → root cause → fix → rerun (recorded)

1. **Pint** `fully_qualified_strict_types` on `MerchantBillingStatus`/`SubscriptionInvoiceItem`
   (FQCN in docblocks). → imported `MerchantSubscriptionStatus`/`Carbon`, used short names →
   Pint auto-fixed, clean.
2. **Larastan** `servana.security.rawSqlConcat` (9 errors) — enum values interpolated into
   `DB::statement` CHECK strings flagged as an SQL-injection vector. Root cause: string
   interpolation in raw SQL (even from trusted enums) is disallowed; the 20A convention hardcodes
   literals. → replaced every `implode(...)` CHECK with hardcoded literal value lists (parity kept
   green by `Phase20BEnumParityTest`) → down to 0.
3. **Larastan** `missingType.iterableValue` on `MerchantSubscriptionStatus::nonTerminalValues()`.
   → added `@return list<string>` → "No errors".

### Increment 2 status: COMPLETE — schema unit green on PG16. No commit (partial phase preserved).

---

## Increment 3 — Interval calculator, lifecycle, projection, billing gate, scheduled changes, onboarding wiring, scheduler (COMPLETE + green)

### Delivered (all green on PG16)

- **Dev DB truthfulness:** `migrate:status` confirms all 8 Phase 20B migrations `Ran` (batch 1) on the
  local dev `servana` DB — none pending. `migrate:fresh` never run against dev.
- **`BillingIntervalCalculator`** (`app/Domain/Billing/Services/`) — the sole `Africa/Nairobi` date-math
  source: weekly +7d, bi_weekly +14d, monthly +1mo, quarterly +3mo, annual +1yr, all with drift-free
  end-of-month/leap clamping computed from the target month's first day + anchor day (no re-clamp
  drift). `trialEnd` and `nairobiDate` helpers keep stored **instants** un-shifted while DATE columns
  use the Nairobi calendar date. `BillingIntervalCalculatorTest` — **14 tests** (all five intervals,
  Jan 31→Feb 28/29, leap/non-leap, quarter/year boundaries, anchor preservation across a full year,
  Nairobi/no-DST).
- **State machine:** `MerchantSubscriptionStatus::allowedTransitions()`/`canTransitionTo()`/
  `projectedBillingStatus()` (Gate B2 map) + `MerchantSubscriptionStateMachine` (422
  `invalid_state_transition` via `BillingStateException`). `MerchantBillingStatusReason` enum for the
  structured `billing_status_reason` codes. `MerchantSubscriptionStateMachineTest` — **valid/invalid
  transitions, terminals, B2 projection map, non-terminal set.**
- **Projection:** `ProjectMerchantBillingStatus` — the SOLE transactional projection: locks merchant +
  subscription, guards + applies the transition, projects `merchants.billing_status`, persists the
  reason, emits the subscription event(s) + `merchant.billing_status_changed`, atomic rollback. Reads
  never consult `merchant_subscriptions.status`.
- **Lifecycle actions** (`app/Domain/Billing/Actions/`): `CreateTrialSubscription` (Gate B1 anchor =
  founding Merchant-Admin membership `created_at`; snapshot `default_trial_days`; idempotent —
  existing current subscription short-circuits), `ActivateSubscription` (+`subscription.recovered`
  from suspended), `EnterReadOnlyGrace`, `MarkSubscriptionOverdue`, `SuspendSubscriptionForBilling`,
  `CancelSubscription` (immediate; future-effective returns without early projection, B2),
  `ExpireSubscription`, `SchedulePlanChange`, `CancelScheduledPlanChange`, `ApplyScheduledPlanChange`
  (row-locked, exactly-once, no proration, period advance via the calculator).
  `ResolveEffectivePlatformBillingSettings` query for the trial-days snapshot.
- **Billing gate:** `App\Http\Middleware\EnsureBillingMutable` reads only `merchants.billing_status`
  (via `Merchant::billingBlocksMutations()`), blocks mutations in `read_only_grace`/`suspended_billing`
  (403 `billing_read_only` — new `TenantAccessException::billingReadOnly()`), allows `trialing`/
  `active`/`overdue` (Plan §25.2). Replaces the temporary Phase 17/10F seam intent; wired to routes in
  Increment 5, and drives `FileGenerationPolicy`'s billing-read-only bool.
- **Merchant model:** `billing_status` cast to `MerchantBillingStatus` + `billingBlocksMutations()`
  helper + `@property` for the new columns.
- **Audit:** 13 Phase-20B `AuditEvent` cases (subscription lifecycle + `merchant.billing_status_changed`
  + plan-change) with severities (Notice/Warning/High); `AuditSeverityCoverage`/`AuditEventCoverage`/
  `AuditMutationCoverage` green (632 assertions).

### Tests (green)

`MerchantSubscriptionStateMachineTest`, `MerchantSubscriptionLifecycleTest` (14 — B1 anchor, snapshot
stability, idempotent setup, projection mapping, B2 terminal reasons, no-early-projection, operational-
status independence, atomic rollback, recovery event), `ScheduledPlanChangeTest` (7 — schedule/cancel/
apply, one-per-cycle, no proration, exactly-once, history), `MerchantBillingMutationGateTest` (6),
`BillingIntervalCalculatorTest` (14). **Full Phase 20B suite: 114 passed / 227 assertions.** Pint clean;
Larastan L8 clean; `git diff --check` clean.

### Increment-3 failures → root cause → fix (recorded)

1. Pest helper name collision (`bindMerchant`) → renamed all lifecycle-test helpers with a `p20bl`
   prefix (Pest test functions are global).
2. Audit assertions used a non-existent `event` column → the table column is `action`.
3. Trial-anchor instant mismatch → `setTimezone(Nairobi)` on the `trial_started_at` **timestamptz**
   shifted the stored instant (Laravel stores wall-clock). Fixed: store the raw instant; apply Nairobi
   only to the DATE-column math (`nairobiDate`), and assert against the membership's stored instant.
4. Larastan: `CarbonImmutable` vs `Illuminate\Support\Carbon` on model date assignments → switched the
   two written models to `immutable_date`/`immutable_datetime` casts + `CarbonImmutable` `@property`;
   added `@property` for new Merchant/MerchantUser timestamp columns; dropped an always-true
   `instanceof`; replaced inline `?->…??` with the codebase's assign-then-null-check pattern.

### Onboarding trial wiring (§9 — COMPLETE + green)

- **Contract:** `CompleteFirstTimeSetupRequest` accepts `subscription_plan_ulid` +
  `subscription_plan_price_ulid` (public ULIDs; `size:26` + `exists:` on the ulid columns — never
  bigint). `FirstTimeSetupData` carries both. `service_fee_tier` (percentage-tier, §51) is kept
  distinct and intact.
- **`ResolveSetupPlanPrice`** validates (422 field errors): plan exists + `active` (retired rejected);
  price exists + belongs to plan; price is the currently-**effective** row for its (plan, interval,
  currency) on the setup date (reuses `ResolveEffectivePlanPrice`; historical/future rejected).
- **`CompleteFirstTimeSetup`** now resolves+validates the plan/price at the top of its transaction,
  then after flipping to `active` invokes `CreateTrialSubscription` (idempotent; founding-admin anchor;
  `default_trial_days` snapshot; projects `billing_status=trialing`) — **atomic**: an invalid selection
  or subscription failure rolls the whole completion back (no merchant marked set-up without a
  subscription). Full-setup replay stays blocked by `EnsureFirstTimeSetupAccess` (409).
- **Existing completed merchants (§7.4):** the only seeder is `PermissionSeeder`; no seeder/fixture/
  production record creates a completed merchant without a subscription (pre-production) — **no
  product-owner question needed**; a guard test asserts completed setup always yields exactly one
  current subscription.
- **Tests:** `FirstTimeSetupTest` (7, updated to select a plan/price), `CompleteFirstTimeSetupSubscriptionTest`
  (7 — anchor, snapshot stability, retired-plan/cross-plan/non-effective rejection, atomic rollback,
  one-current-subscription guarantee, `service_fee_tier` intact).

### Scheduler (§13 — COMPLETE + green)

- **`ProcessSubscriptionLifecycle`** command (`billing:process-subscription-lifecycle`) registered in
  `routes/console.php` **daily, `Africa/Nairobi`, `withoutOverlapping` + `onOneServer`** (the
  established billing/integrity cadence). Orchestrates **existing actions only** (no duplicated
  transition logic): trial expiry (`trial_ends_at`) → `EnterReadOnlyGrace` (grace configured) or
  `ExpireSubscription`; trial-grace expiry (`trial_ends_at` + effective `grace_days`) →
  `SuspendSubscriptionForBilling`; due `scheduled_plan_changes` (`effective_at`) →
  `ApplyScheduledPlanChange`. Cross-tenant scan via scope-free `DB::table` bounded to 500/category;
  per-item merchant `bindForJob` + scoped load + the action's row lock (bounded per-item transactions);
  idempotent (state machine + re-selection); one bounded **redacted** failure signal per item + non-zero
  exit. Invoice-`due_at`-driven overdue/suspend for active subscriptions lands with Increment 4.
- **Tests:** `Phase20BSchedulerRegistrationTest` (2 — daily/Nairobi/withoutOverlapping/onOneServer),
  `ProcessSubscriptionLifecycleTest` (7 — trial→grace/expire, grace→suspend, not-yet-due untouched,
  not-before-grace-window, scheduled-apply-exactly-once, idempotent replay).

### Increment 3 status: COMPLETE + green

Full Increment-3 sweep (`onboarding` + `billing` groups): **245 passed / 2223 assertions**; Pint clean
(1093); Larastan L8 clean; `git diff --check` clean. No regressions.

### Increment-3 wiring failures → fix (recorded)

1. Existing `FirstTimeSetupTest` broke on the new required plan/price fields → added a
   `setupPlanPriceUlids()` fixture (guards duplicate `PlatformBillingSettings` on repeated calls via an
   existence check, since its `effective_from` is unique).

Then Increment 4 (invoice issuance/numbering/items/PDF/escalation events).

---

## Increment 4 — Invoice numbering, issuance, void, overdue, escalation, PDF (COMPLETE + green)

### Delivered (all green on PG16)

- **Invoice state machine:** `SubscriptionInvoiceStatus::allowedTransitions()`/`canTransitionTo()` (full
  §25.4 inventory; 20B invokes only `draft→issued`, `issued/partially_paid→overdue`, `draft/issued→void`)
  + `SubscriptionInvoiceStateMachine` (422 `invalid_state_transition`). `void` terminal (never `cancelled`).
- **`AllocateSubscriptionInvoiceNumber`** (Gate B3): row-locked `invoice_number_sequences` for the
  **independent** `subscription_invoice` scope (added `InvoiceNumberSequence::SCOPE_SUBSCRIPTION_INVOICE`);
  gap-free per-merchant `SUB-000001…`; rollback consumes no number; separate from the merchant-client counter.
- **`IssueSubscriptionInvoice`**: transaction + subscription lock; **Gate B5 fail-closed** for any
  non-`fixed_amount` mode (`BillingModeNotSupportedException` 422; NO invoice/item/sequence/audit — proven);
  captures plan/price; allocates number; one immutable `plan_fee` item = captured price; `discount=0`;
  Wallet columns at 20B defaults (null/`unregistered`); `issued_at` + `due_at` (period_end); idempotent per
  subscription period; emits `subscription_invoice.issued`.
- **`VoidSubscriptionInvoice`** (draft/issued→void), **`MarkSubscriptionInvoiceOverdue`**
  (issued/partially_paid→overdue, idempotent) — row-locked, state-machine-guarded, typed audit.
- **`RecordBillingEscalationEvent`**: append-only `insertOrIgnore` idempotent per
  `(merchant_subscription_id, event_type, period_boundary)` (Gate B4 — never `created_at`); emits the
  matching typed `billing_escalation.*` audit only on a new insert.
- **Immutability (defence-in-depth):** `SubscriptionInvoice` model blocks updates to financial fields once
  it leaves `draft` and blocks delete; `SubscriptionInvoiceItem` blocks all update/delete. (`status`,
  `balance_minor`, Wallet columns remain mutable for 20D-W.)
- **Audit:** 9 new `AuditEvent` cases (`subscription_invoice.issued/overdue/voided/pdf_generated`,
  `billing_escalation.reminder/grace_entered/overdue/suspended/recovered`) + severities;
  `AuditEventCoverage`/`AuditSeverityCoverage` green.

### Tests (green)

`SubscriptionInvoiceTest` (11 — fixed-mode total = price + immutable plan_fee item; Wallet defaults + no
Wallet tables; idempotent; **fail-closed percentage (no rows/audit)**; financial-field + item immutability;
independent per-merchant numbering; overdue idempotent; void terminology; invalid-transition 422; no
percentage-ledger table), `BillingEscalationTest` (4 — append-only, idempotent per period boundary,
distinct boundary, no `updated_at`). **Full `--group=billing` 241 passed / 508 assertions**; Pint clean
(1102); Larastan L8 clean; `git diff --check` clean.

### Increment-4 failure → fix (recorded)

- Larastan `CarbonImmutable` vs `Illuminate\Support\Carbon` on `SubscriptionInvoice` date assignments →
  switched to `immutable_date`/`immutable_datetime` casts + `CarbonImmutable` `@property` + import (same
  pattern as the other 20B models).

### Invoice PDF (§12.3 — COMPLETE + green)

- **`GenerateSubscriptionInvoicePdf`** via the Phase 10F private-file domain — no new file table,
  storage service, public path, object-store URL, or Wallet seam. Reuses `GeneratedFileWriter`
  (purpose `billing_invoice_pdf`, `requiresMerchant=true`, `billingReadOnlyGeneration=true`) exactly
  like the Phase 18B receipt PDF; renders via the dependency-free `MinimalPdf` (no library added to the
  pinned stack) through `SubscriptionInvoiceDocumentRenderer`.
- **Migration `2026_07_11_000009`:** additive `subscription_invoices.file_id` (FK `uploaded_files`
  RESTRICT) + `pdf_version` (int default 0) — technical projection columns, not part of the immutable
  snapshot. Manifest entry added; disposable PG16 `migrate:fresh` (82 migrations) green + dropped.
- **Billing-status generation gate (§22):** `FileGenerationPolicy::canGenerate(BillingInvoicePdf,
  Merchant::billingBlocksMutations())` — reads `merchants.billing_status` **only**, never the
  subscription record. Allowed in `trialing`/`active`/`overdue`; blocked in `read_only_grace`/
  `suspended_billing` → **403 `billing_read_only`** (`TenantAccessException::billingReadOnly()`).
- **Pending reference:** `SubscriptionInvoiceDocumentRenderer::PENDING_REFERENCE_TEXT` = exactly
  **"Payment reference pending — see your billing dashboard"** (the canonical string for API/UI). The
  PDF renders it (MinimalPdf is ASCII-only, so the em-dash normalises to a space — a known writer
  limitation matching the receipt precedent, not a semantic deviation). No fabricated account reference,
  Wallet payment ID, internal ID, or storage path is rendered. Registered-path rendering is 20D-W.
- **Versioning + download:** each regeneration writes a new `uploaded_files` version, revokes the prior
  (`markLifecycle(Revoked)`), updates `file_id` + increments `pdf_version`. Download reuses the existing
  10F `FileAccessService` (auth re-checked at link issue AND download; tenant ownership; downloadable +
  object present; short-lived signed URL) — billing-read-only is **not** a download gate, so existing
  PDFs stay downloadable in `read_only_grace`/`suspended_billing`. (The merchant-facing invoice-PDF
  route + `merchant.subscription.invoice.download` permission are wired in Increment 5.)
- **Audit:** exactly one `subscription_invoice.pdf_generated` per generated version (invoice ULID, file
  ULID, merchant ULID, version — no path/URL/token/HTML/internal-id).
- **Tests:** `SubscriptionInvoicePdfTest` (8 — purpose/private/association, exact pending-reference +
  no-fake-ref, generation allowed active/trialing/overdue, blocked read_only_grace/suspended (403),
  regeneration new version + prior revoked, issued snapshot unchanged, one audit event, no Wallet
  runtime), `SubscriptionInvoicePdfDownloadTest` (4 — authorized download + signed URL, existing
  downloadable in read-only/suspended, cross-tenant 404, revoked-version 404).
- **Increment-4 PDF failure → fix:** a `value('lifecycle_status')` assertion compared to the enum's
  string but Eloquent's `value()` applies the cast → compared to the `FileLifecycleStatus::Revoked`
  enum instead.

### Increment 4 status: COMPLETE + green

Full `--group=billing`: **253 passed / 539 assertions**; Pint clean (1107); Larastan L8 clean;
`MigrationManifest`/`AuditEventCoverage`/`AuditSeverityCoverage`/`TenantColumnCoverage` green; disposable
PG16 fresh-build proof green; `git diff --check` clean.

### Wallet source-of-truth reaffirmed (§3 of the resume brief; Plan §49 + ADR-014 controlling)

Phase 20B ships **only** nullable Wallet projection columns. **No** `RegisterInvoicePayment` action,
outbox intent, outbox table, consumer, or Wallet API call exists (the §80 summary sentence mentioning a
no-op outbox intent is superseded by the detailed §49/ADR-014 contract and was **not** implemented). All
issued invoices keep `account_reference=null`, `wallet_payment_id=null`,
`wallet_registration_status=unregistered`, `wallet_registered_at=null` — proven by
`SubscriptionInvoiceTest`/`SubscriptionInvoicePdfTest` (no Wallet tables exist).

## Increment 5 — permission activation + merchant & platform-governance APIs (atomic)

The atomic §19 flip: nine canonical §19.2 keys activated, two dead legacy keys retired, live route
consumers + policies + audit mappings + contracts + tests landed as one coherent unit.

### Permission counts (computed, verified — not hard-coded)

| dimension | before | after | delta |
|---|---|---|---|
| active | 93 | 100 | −2 legacy actives, +9 canonical actives |
| planned | 77 | 68 | −9 (activated) |
| legacy-active (active ∧ non-canonical) | 14 | 12 | −2 retired |

(Prompt's "94→101" was an estimate; the verified live counts are 93→100. `PermissionMatrixParityTest`
proves YAML-active == PHP registry == DB projection; `PermissionLegacyKeyReconciliationTest` asserts 12;
`PermissionPlannedKeyIsolationTest` asserts 68; `PermissionTypeScriptParityTest` proves the TS set.)

### Nine activated canonical keys

- **Merchant (merchant_admin, merchant scope):** `merchant.subscription.view`,
  `merchant.subscription.plan_change`, `merchant.subscription.invoice.view`,
  `merchant.subscription.invoice.download`.
- **Platform (super_admin, platform scope):** `platform.registration_monitor.view`,
  `platform.merchant.view`, `platform.merchant.suspend`, `platform.merchant.reactivate`,
  `platform.merchant.deactivate`.
- MFA `Y` on all nine (canonical matrix). Fresh step-up `Y` **only** on suspend/reactivate/deactivate
  (a single new `StepUpAction::MerchantGovernance`, not three); reads + merchant plan-change are `SU N`.
- Derived `audit_event` (route + `AuditMutationCoverage`): reads = `none`; `plan_change` =
  `subscription.plan_change_cancelled; subscription.plan_change_scheduled`; `invoice.download` =
  `subscription_invoice.pdf_generated`; suspend/reactivate/deactivate = `merchant.{suspended,
  reactivated,deactivated}`.

### Two retired legacy keys (truthful reconciliation)

- `merchant.tier.update` → canonical successor `merchant.subscription.plan_change`. Only consumer was
  the unused `MerchantPolicy::updateTier()` (no route/live caller) — the method is deleted.
- `platform.merchants.govern` → **no 1:1 successor**. Its single blunt authority is truthfully **split**
  into a read surface + three operational-status mutations:
  `platform.merchant.suspend` / `platform.merchant.reactivate` / `platform.merchant.deactivate`
  (plus the `platform.merchant.view` / `platform.registration_monitor.view` reads). No live consumer
  existed. Both YAML rows are deleted entirely (this schema has no `retired` status); the seeder's
  `prunePermissions()` removes the DB rows on re-seed.

### Merchant API (existing tenant group, under `EnsureMerchantActive`; `TenantMutation` on writes)

`GET subscription` · `GET subscription/plans` · `GET subscription/scheduled-plan-change` ·
`POST subscription/scheduled-plan-change` · `POST subscription/scheduled-plan-change/cancel` ·
`GET subscription-invoices` · `GET subscription-invoices/{invoice}` ·
`POST subscription-invoices/{invoice}/pdf` (generation) ·
`GET subscription-invoices/{invoice}/pdf/download-link` (existing download).
Plan-change writes carry `merchant.subscription.plan_change` + `EnsureBillingMutable`; `effective_at`
is server-computed (period end); no proration. PDF generation is a mutation + `EnsureBillingMutable`
(blocked in read-only); the existing-PDF download link is a read allowed in read-only (reuses
`FileAccessService`). Bindings resolve inside `BelongsToMerchant` (foreign tenant → 404). **No** trial/
activation/issue/void/payment/Wallet route.

### Platform API (existing platform group; `ResolvePlatformContext` + `EnsurePrivilegedMfa`; `PlatformMutation`)

`GET registration-monitor` · `GET merchants` · `GET merchants/{merchant}` ·
`POST merchants/{merchant}/{suspend,reactivate,deactivate}` (mandatory reason + fresh step-up).
Named actions `SuspendMerchant` / `ReactivateMerchant` / `DeactivateMerchant` each lock the row,
validate the operational transition (`MerchantStatusException` → 422 `invalid_state_transition`),
mutate `merchants.status` **only** (never `billing_status`, never a subscription/payment row), and emit
exactly one typed, redacted audit event on the platform/governance chain (`merchant_id=null`; context =
merchant ULID + prev/new status + sanitised reason). Reactivation never clears a billing suspension.
**No** merchant-create / first-admin / impersonation / manual-payment / billing-recovery route
(`NoPlatformMerchantCreationTest` tightened to forbid a collection create + first-admin path while
permitting the `{merchant}/status` governance mutations).

### Audit

Three new typed events — `merchant.suspended` (High), `merchant.reactivated` (High),
`merchant.deactivated` (**Critical**) — added to the exhaustive `AuditEvent::severity()` match; six new
mutating routes mapped in `AuditMutationCoverage::AUDITED`.

### Contracts

`docs/api/openapi.json` regenerated (**203 operations**); `resources/spa/src/types/generated/api.ts`
regenerated (`api:contract:check` OK — 171 paths / 203 operations);
`resources/spa/src/types/generated/permissions.ts` regenerated + `--check` clean. Generated files never
hand-edited.

### Tests (Increment 5)

New: `Phase20BPermissionActivationTest` (4), `MerchantSubscriptionApiTest` (13),
`PlatformMerchantGovernanceApiTest` (10). Updated: `PermissionMatrixTest`,
`PermissionLegacyKeyReconciliationTest` (→12), `PermissionPlannedKeyIsolationTest` (→68),
`NoPlatformMerchantCreationTest` (tightened).

### Increment 5 status: COMPLETE + green

Atomic guard battery (62 tests): OpenAPI · generated API types · generated permission types ·
RouteSecurityContract · FinancialRouteIdempotency · AuditMutationCoverage · AuditSeverityCoverage ·
PermissionMatrix{,Parity,Schema,PlanMetadataParity,CatalogueCompleteness} · PermissionLegacyKey
Reconciliation · PermissionPlannedKeyIsolation · PermissionRoleBoundary · PermissionMfaCoverage ·
PermissionStepUpCoverage · PermissionTypeScriptParity · PermissionDatabaseProjection ·
NoDirectProviderIntegration — **all pass**. Full backend suite **1350 passed / 7 skipped / 0 failed**;
Pint clean (1129); Larastan L8 clean.

## Increment 6 — frontend (green)

Four Phase 20B screens on the existing layouts/router/Pinia/design-system, driven by the generated
API + permission types, server `can` maps, and structured errors. UX only — the backend stays
authoritative.

### Stores (3, generated-typed; no hand-written contracts)

- `subscriptionStore` — `MerchantSubscriptionResource` + `SubscriptionPlanOptionResource` +
  `ScheduledPlanChangeResource`; fetch subscription/plans/scheduled-change + schedule/cancel.
- `subscriptionInvoiceStore` — `SubscriptionInvoiceResource`; fetch list/detail + `generatePdf`
  (POST mutation) + `downloadLink` (GET read); exports the exact `PAYMENT_REFERENCE_PENDING_TEXT`.
- `platformMerchantStore` — `PlatformMerchantResource` + `MerchantRegistrationMonitorResource`;
  registration-monitor/merchants/detail + suspend/reactivate/deactivate (mandatory reason).
  All preserve ULIDs, apply an allowlisted `status` filter, and persist no tokens/reasons/secrets.

### Screens (4)

- `merchant/SubscriptionDashboard.vue` (`merchant.subscription`) — subscription + INDEPENDENT billing
  status, plan/price, interval, trial + current-period dates, scheduled-change + latest-invoice
  summaries, and a billing read-only explanation. `SvStateBoundary` for loading/empty/error/success;
  a no-permission note.
- `merchant/PlanManagement.vue` (`merchant.plan`) — available plans + effective prices, current-plan
  badge, schedule/cancel a no-proration next-cycle change with a SERVER-computed effective date (no
  client date / no mid-cycle). Mutation controls removed in billing read-only; structured 409
  (`scheduled_plan_change_exists`) + 422 + `billing_read_only` surfaced.
- `merchant/SubscriptionInvoices.vue` (`merchant.invoices`) — list + detail (number/period/amounts/
  currency/balance/status/dates), the exact payment-reference-pending copy, and STRICTLY separated
  Generate PDF (mutation; disabled in billing read-only) vs Download existing PDF (read; allowed in
  read-only, via `window.open` of a signed link). No Wallet/STK/PayBill-Till/provider/payment UI.
- `platform/RegistrationMonitoring.vue` (`platform.registration-monitoring`) — consolidated Super-Admin
  surface with accessible tabs: registration monitoring + merchant directory/detail (operational vs
  billing status separate) + suspend/reactivate/deactivate via an `SvModal` requiring a mandatory
  reason (confirm disabled < 3 chars) + confirmation; step-up / `invalid_state_transition` errors
  surfaced; focus restored to the trigger on close. Governance controls gated by the server `can` map.
  The "Merchant directory" nav label routes here too. NO merchant-create/first-admin/impersonation/
  payment/Wallet control.

### Navigation / inventory / specs

- `roleNavigation.ts` + `role-navigation.yaml`: five items flipped `planned`→`live` with route names
  (`merchant.plan` phase corrected 20A→20B); navigation parity test green.
- `inventory.json` + `inventory.yaml`: four screens flipped `planned`→`implemented` with route + spec;
  four §27.1 specs regenerated via `node scripts/generate-screen-specs.mjs`.

### Increment 6 gates

`npm run lint` (0 errors; new files warning-free), `npm run typecheck` (clean),
`npm run test` (**308 vitest tests pass**, incl. 7 new store/page specs), `npm run build` (✓).

## Increment 7 — E2E + full local gates (green)

### Playwright — `tests/e2e/phase-20b.spec.ts` (23 tests, all pass)

Drives the REAL frontend against stubbed `/me` + `/api/v1` (the SPA preview has no backend; genuine
authz/billing/step-up/non-enumeration are proven by the Feature suite). Covers: dashboard across
`trialing`/`active`/`read_only_grace`/`overdue`/`suspended_billing` + terminal `cancelled`/`expired`;
mandatory-MFA challenge redirect; no-proration schedule + cancel; billing-read-only control removal;
structured 409; invoice detail + exact payment-reference-pending copy; **new PDF blocked in read-only
vs existing PDF downloadable**; registration monitoring; merchant directory/detail with operational vs
billing status **separated**; suspend with mandatory reason + fresh-step-up 403 guidance; reactivate
that does **not** clear the billing suspension; forbidden-UI absence (no merchant-create/first-admin/
impersonation/payment/Wallet/STK/PayBill); merchant role denied the platform route. Accessibility:
axe **serious/critical = 0** (light + dark) on the dashboard and the governance dialog; no page-level
horizontal overflow at 360/768/1280; the confirmation dialog manages focus and **restores focus to the
trigger** on Escape. Two initial failures fixed: the `/me` membership role must be the enum value
`merchant_admin` (not the `merchant_administrator` identity → "unsupported role"); and the `text-primary`
(Savannah-Orange) inline links failed AA contrast → switched to the theme-aware `text-heading` link
style (ADR-009). Full E2E suite: **292 passed**.

### Final gate battery (all green)

- **Contracts:** `composer api:openapi` (203 operations) · `npm run api:types` · `servana:permission-types
  --check` (up to date) · `npm run api:contract:check` (171 paths / 203 operations) — all clean; generated
  files never hand-edited.
- **Backend:** `composer validate --strict` valid · Pint PASS (1129) · Larastan L8 no errors ·
  `php artisan test` **1348 passed / 7 skipped / 0 failed** · `--parallel` **1348 passed / 0 failed**.
- **Frontend:** ESLint 0 errors · vue-tsc clean · Vitest **308 passed** · production build ✓ · Playwright
  full suite **292 passed**.
- **Security:** `composer audit --locked` no advisories · `npm audit --audit-level=high` clean (2
  **moderate** advisories in the `@redocly/openapi-core` dev dependency, truthfully below the high gate) ·
  `gitleaks detect --no-git --redact` **no leaks**.
- **Docker:** `docker build -f docker/php.Dockerfile --target dev .` ✓ · `docker build -f
  docker/nginx.Dockerfile --target prod .` ✓ (built sequentially, not during Playwright).

### Lifecycle

All local gates pass → **Phase 20B = local_complete pending PR CI / review / merge** (NOT
`verified_complete`). Single completion commit `phase-20b: implement subscription lifecycle and
invoices` on branch `phase-20b-subscription-lifecycle-invoices`; no PR opened, no merge, Phase 20C not
started.
