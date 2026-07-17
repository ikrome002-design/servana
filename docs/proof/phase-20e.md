# Phase 20E — Percentage Platform-Fee Engine — Proof

> Lifecycle: **verified_complete** — reconciled from `local_complete pending PR CI/review/merge` during
> **Phase 20F Increment 1** on the merge evidence below.
>
> | Reconciliation fact | Value |
> |---|---|
> | PR | **#38** — "Phase 20E: Implement percentage platform fee engine" (<https://github.com/ikrome002-design/servana/pull/38>) |
> | State | **MERGED** |
> | Implementation commit | `f6e208a90513bf5ca1c219c456b263ea0d111c5c` (recorded exactly once on PR #38) |
> | Governance / final PR head | `24d1cad60539fe40596125240391c48a1b821246` (recorded exactly once on PR #38) |
> | Merge commit | `c0881993ae0c59536013c9b84e182e5000fa1e11` |
> | Merged at | `2026-07-14T06:19:43Z` |
> | Final CI run | `29310753740` |
> | Required jobs | Backend **SUCCESS** · Frontend **SUCCESS** · Docker **SUCCESS** · Security **SUCCESS** · E2E — Playwright **SUCCESS** |
> | `reviewDecision` | **blank** — documented solo-maintainer governance exception; **NOT** independent reviewer approval |
> | Branch cleanup | local `phase-20e-percentage-platform-fees` deleted · remote deleted |
> | Post-merge state | merge commit = `origin/main` = local `main` at Phase 20F branch creation; `origin/main...HEAD` = `0 0`; working tree clean; `git fsck --full` clean |
>
> Reconciliation is **documentation-only** — no Phase 20E implementation logic was altered, and the phase
> is **not** rewritten as independently reviewed. Phase 20F branched from the merge commit `c088199…`.
>
> Original in-progress header (retained for history): branch `phase-20e-percentage-platform-fees`, based on
> `origin/main` = `735f419bf72fdd9be3f95c4507e8925c1ed0859e` = the Phase 20C PR #37 squash merge.
> Specification-first:
> **no migration is created until every material gate (E1–E9) is resolved and the data dictionary,
> state machines, and migration manifest are written.** Controlling sources: Plan §§13.10, 51, 52, 80
> (Phase 20E), §§17/18 (invoicing/validation), §§20A/20B (billing settings / subscription invoices);
> Scope §§6.3–6.4 (percentage & fixed-plus-percentage modes, tier behaviour, fee basis); ADR-004
> (expand/contract), ADR-005 (integer money, round-half-up, largest-remainder), ADR-011 (versioned price),
> ADR-012 (Wallet boundary). Corrections 2, 4, 8. Exclusions per §11: Wallet/provider runtime → 20D-W;
> compensation → 20F–20H.

## Baseline verification (independent, at start)

- **Repo:** `C:\Users\nderu\Documents\Development\Product\Servana`. `git fsck --full` clean.
- **Git:** branch created off HEAD == origin/main == merge-base == `735f419bf72fdd9be3f95c4507e8925c1ed0859e`;
  working tree clean at creation; old local + remote `phase-20c-promotions-free-periods` branches absent;
  no unrelated commits on the branch.
- **Runtime:** Docker Engine reachable (Desktop 29.6.1); Compose services `app`/`postgres`/`redis`/`nginx`/
  `meilisearch`/`minio`/`mailpit` **healthy** (+ workers/scheduler up). **PHP 8.3.32 / Laravel 12.62.0 /
  PostgreSQL 16.14**; Laravel↔PostgreSQL connected; `migrate:status` readable (latest ran migration
  `2026_07_12_000006_add_free_period_snapshot_to_merchant_subscriptions`).

## Phase 20C PR #37 verification (recorded)

| Field | Value |
|---|---|
| PR / title / state | #37 / "Phase 20C: Implement promotions and free periods" / MERGED (base `main`) |
| Implementation commit | `782c97313ea988d2263e35d44c325d2c7ccb25ec` |
| Governance / final PR head | `efe0f74afe23fa8f3d3acfdd363c1328520cade8` |
| Squash merge / mergedAt | `735f419bf72fdd9be3f95c4507e8925c1ed0859e` / `2026-07-12T11:50:45Z` |
| Initial CI run | `29191160816` (head `782c973…`) — conclusion **success**; 5/5 required jobs success |
| Final CI run | `29191381748` (head `efe0f74…`) — conclusion **success**; 5/5 required jobs success |
| Required jobs | Backend, Frontend, Docker, Security, E2E — Playwright (all SUCCESS in both applicable runs) |
| reviewDecision | **blank** — documented PR-specific solo-maintainer governance exception; **NOT** independent reviewer approval |
| Branch cleanup | local + remote `phase-20c-promotions-free-periods` deleted (absent) |

Reconciliation applied: `docs/proof/phase-20c.md`, `docs/PROGRESS.md`, `docs/CHANGELOG.md`,
`docs/traceability/servana-requirements.csv` (SRV-PROMOTION-001 + SRV-FREE-PERIOD-001) →
`verified_complete`. No open remediation item is Phase 20C-owned (REM-BILL-MODE-001 is already
`verified_complete`; the percentage-fee **ledger** it references is the separate Phase 20E obligation).

## Gate W (External Wallet gate) — **CLOSED**

`docs/integrations/wallet/gate-w-evidence.md` and the `docs/integrations/wallet/` directory are **absent**.
No service-account credential evidence, no pinned Wallet OpenAPI hash, no passing contract suite, no
sandbox STK/C2B transcript. Per Plan §80.2 and the assignment §6 rule 1, **Gate W is not open**. The v4
dependency graph is `20A → 20B → 20C`; `20B → Gate W → 20D-W`; `20A + 17/18 → 20E`. Phase 20E does **not**
depend on Gate W, so it is the next executable phase. **No pivot to Phase 20D-W.** No Wallet client,
webhook, payment attempt/row, merchant billing credit, or provider code is introduced in Phase 20E.

## Specification gates E1–E9 (resolved before migration)

Source-of-truth rule: where the Plan and Scope conflict, the **active v4 Plan wins** (repo §2 hierarchy).
Every decision below preserves: invoice-finalization provenance · Finance validation as the billability
authority · append-only reversal/adjustment · subscription-invoice aggregation · Wallet settlement
outside Phase 20E.

### E1 — Canonical ledger lifecycle & creation instant

**Conflict.** Plan §13.10: `platform_fee_ledger_entries.entry_type ∈ {earned, reversal, adjustment}`,
`status ∈ {pending, aggregated, invoiced, reversed, adjusted}`. Scope §6.3.1: `provisional → billable →
aggregated → settled`.

**Decision (Plan canonical).**

| Instant | Effect |
|---|---|
| Merchant-client invoice **finalization** (P17) | Write **nullable** config/rate/basis/tier/currency snapshot onto the invoice (+ items where item provenance needed); for shifting tiers, add the client-shifted line to the invoice presentation/total. **No `platform_fee_ledger_entries` row is created.** |
| Finance **payment validation** (P18B) | Create the **original** ledger row `entry_type='earned'`, `status='pending'`, computed from the validated basis; stamp `billable_at`. **This is the single original liability fact and the billability authority.** Idempotent per validation allocation. |
| **Aggregation** (P20B `IssueSubscriptionInvoice`) | `pending → aggregated` and link `subscription_invoice_item_id`; on subscription-invoice issuance `aggregated → invoiced`. |
| Void / refund / correction | **Additive** `entry_type='reversal'` / `'adjustment'` rows + `platform_fee_adjustments` rows; original row's **monetary fields are immutable**; original `status` may move to `reversed`/`adjusted` as a non-monetary lifecycle marker only. |

- Scope's `provisional` maps to "invoice snapshot exists, no ledger row yet"; `billable` maps to the
  Plan's `pending` (created at validation, already billable — no separate flip; `billable_at` = validation
  instant). Scope's `settled` = Wallet clearing of the subscription invoice = **Phase 20D-W, excluded**;
  never a manual Phase 20E transition.
- **Immutability rule:** the append-only guard blocks `UPDATE` of monetary/snapshot columns and any
  `DELETE`; only the documented state-machine `status`/aggregation-link transitions are permitted (via
  actions). This is consistent with Plan §953 ("resolution that changes money creates a
  `platform_fee_adjustment`, never edits a ledger row").
- **Invariants:** one original liability per source boundary; idempotent replay creates no duplicate; no
  billable liability before validation; no destructive monetary edits; `settled` not fabricated.
- **Reconcilable without a product-owner question** — the Plan enum is authoritative and its roadmap text
  (§2628/§51) fixes the creation instant at validation.

### E2 — Canonical fee-basis vocabulary

**Decision (Scope §6.3.2, matched to real invoice columns).** `fee_basis_type ∈`

```
merchant_client_invoice_service_subtotal   -- Σ service-line net (excludes preferred-personnel fee line, promo, tax add-ons)
merchant_client_invoice_total              -- final invoice total (post-discount, incl. tier client-shifted line + charges)
net_after_discount                         -- subtotal after P20C promotion snapshot, before charges
invoice_item_subtotal                      -- per-item subtotal (item-level basis; drives largest-remainder provenance)
validated_paid_amount                      -- validated allocation amount (per-validation basis)
```

- DB CHECK-enforced; **no aliases**, no arbitrary strings. Snapshotted per original entry
  (`fee_basis_type` + `fee_basis_amount_minor`). Basis is invoice-level except `invoice_item_subtotal`
  (item-level) and `validated_paid_amount` (allocation-level).
- Discount treatment: `net_after_discount` uses the P20C promotion-snapshotted net (never re-resolved).
- Preferred-personnel fee lines are **excluded** from `merchant_client_invoice_service_subtotal` (they are
  a separate merchant-client charge, not platform-fee basis).
- **Partial-payment treatment (see E7):** `validated_paid_amount` → one `earned` entry per validated
  allocation (proportional, idempotent per allocation). Fixed-at-finalization bases → single `earned`
  entry created when the invoice reaches `paid`.
- Currency: entry currency == config currency == source-invoice currency; mismatch fails closed.

### E3 — Effective configuration model

**Decision.** `platform_fee_configurations` — platform-scoped, effective-dated using the P20A
effective-dated platform-configuration conventions (no duplicate billing-mode source of truth; the active
mode remains `platform_billing_settings.billing_mode`).

| Field | Rule |
|---|---|
| `billing_mode` | CHECK ∈ modes; percentage config only applicable when a percentage component is active |
| `percentage_basis_points` | int, CHECK 0–10000, nullable (required when a percentage component is active) |
| `fixed_component_minor` | bigint nullable (present for `fixed_amount_plus_percentage_on_merchant_client_invoice`) |
| `tier_behavior` | CHECK ∈ `{customer_centric, shared, business_centric}` (nullable; required in percentage modes) |
| `shared_split_basis_points` | int, CHECK 0–10000, nullable; **required iff `tier_behavior='shared'`** (see E-Extra below) |
| `fee_basis_type` | CHECK ∈ E2 vocabulary; required in percentage modes |
| `currency` | char(3) upper CHECK |
| `effective_from` / `effective_to` | effective window; **exclusion constraint** prevents overlapping active/scheduled windows for the same applicability boundary |
| `status` | CHECK ∈ `{draft, scheduled, active, superseded, cancelled}` |
| `created_by` / `approved_by` / `approved_at` / `change_reason` | approval metadata; `change_reason` NOT NULL |

- Approved monetary terms (bps, fixed component, split, basis, currency) are **immutable**; changes
  **supersede** (new version), never destructively edit. No arbitrary JSON for core financial rules.
- No active percentage behaviour without a valid effective `active` configuration → fail closed.

### E3-Extra — Shared-tier split field (assignment §13.3 gap)

**Decision.** Field `shared_split_basis_points` (integer basis points, range 0–10000, no default).
`50%` is **not** a hard rule and **not** silently defaulted — it must be explicitly configured when
`tier_behavior='shared'` (a DB CHECK enforces non-null in that case). Effective-dated with the parent
configuration; snapshotted onto each ledger entry as `shared_split_snapshot`. Rounding: the client-shifted
share uses ADR-005 round-half-up; the merchant-absorbed remainder = `gross − client_shifted` (residual
lands on the merchant, keeping `absorbed + shifted == gross`).

### E4 — Tier source & mutability

**Naming reconciliation (material).** Shipped `merchants.service_fee_tier` DB CHECK and the
`App\Domain\Merchants\Enums\ServiceFeeTier` enum use **`split_tier`**; the canonical Plan §13.10 / Scope
§6.3 vocabulary uses **`shared`** (Scope §1050: `shared` ≡ the previously-named "Split").

**Decision.**
- Canonical Phase 20E tier vocabulary = `customer_centric / shared / business_centric` (Plan §13.10).
- The shipped `merchants.service_fee_tier` migration is **not edited** (guardrail 12). `ResolveMerchantServiceFeeTier`
  maps the stored `split_tier → shared` deterministically at snapshot time.
- **Effective-tier precedence:** merchant's own `service_fee_tier` (mapped) if set, else the effective
  configuration's `tier_behavior` default.
- Changes apply **prospectively only**; the resolved tier is snapshotted onto each original entry
  (`service_fee_tier_snapshot`).
- **Fixed-only mode:** tier is not resolved and no entry is created.
- **Percentage mode with missing tier/configuration:** fail closed with a typed domain error — never
  silently default a merchant into a liability-changing tier.

### E5 — Item allocation & largest remainder

**Decision (ADR-005).**
1. Invoice-level gross fee `G = round_half_up(basis_minor * bps / 10000)` (integer only).
2. Item exact share numerator `n_i = G * item_basis_i`; floor `f_i = floor(n_i / Σ item_basis)`.
3. Residual `R = G − Σ f_i`.
4. Rank items by descending remainder `(n_i mod Σ item_basis)`; stable tie-break on the **immutable
   invoice-item ULID** (ascending).
5. Distribute one minor unit to the top `R` items.

**Invariants:** `Σ item gross == G`; `Σ client_shifted_i == invoice client_shifted`; `Σ absorbed_i +
Σ shifted_i == G`. Documented behaviour: zero-basis items receive 0; negative corrections handled via
additive adjustment rows (never negative original fee); mixed applicable/non-applicable items excluded
from basis; unsupported currency → typed error.

### E6 — Merchant-client invoice integration (P17)

**Decision.** Extend the existing P17 finalize action atomically: lock draft + source rows → resolve
effective `platform_billing_settings` → resolve effective `platform_fee_configurations` → resolve +
validate tier → resolve fee basis from **immutable server-side** invoice data → compute invoice-level
gross → largest-remainder items → per tier, add the client-shifted amount to the invoice presentation/
total → snapshot config/rate/basis/tier/split/currency/source IDs onto the invoice (nullable columns) →
consume the invoice number **only inside the successful transaction** → one coherent audit event → commit.
Browser-supplied amounts/rates/tiers/totals ignored. **Finalized invoices are never recalculated.**
Failure inside finalization ⇒ no finalized invoice, no invoice number consumed, no original entry, no
partial line, no success audit. **Fixed-only ⇒ no config resolved, no percentage line, no entry, no
change to existing invoice behaviour.** (Note: the original *ledger* entry is created at validation per
E1, not here; finalization writes only the invoice-side snapshot + client line.)

### E7 — Billability & payment-validation integration (P18B)

**Decision.** Hook **inside** the authoritative Finance-validation transaction: on successful validation,
`RecordOriginalPlatformFeeLiability` creates the `earned`/`pending` entry, stamps `billable_at`, and
commits/rolls back with the validation tx. Idempotency keyed to the validation allocation.
- `validated_paid_amount` basis → one entry per validated allocation (proportional to that allocation).
- Fixed-at-finalization bases (`*_subtotal`, `*_total`, `net_after_discount`, `invoice_item_subtotal`) →
  the full invoice fee becomes billable as a **single entry** at the validation event that transitions the
  invoice to `paid` (validated total == invoice total); partial validations create nothing. *(Derived
  decision — preserves "one original liability fact per source boundary" and "no billable before
  validation"; recorded as residual risk, exercised by Increment 4–5 tests.)*
- Recording-only, rejected, or replayed validation never makes a liability billable / duplicated.
  Tenant/branch scoped. Merchant-client validation requires **no** Wallet event (distinct from
  merchant-to-Servana Wallet confirmation, which is 20D-W).

### E8 — Aggregation into subscription invoices (P20B)

**Decision.** Reuse the P20B `IssueSubscriptionInvoice` immutable flow + the existing
`subscription_invoice_items.type='platform_fee_rollup'` line type (§13.10). `AggregatePlatformFeesIntoSubscriptionInvoice`:
select eligible `pending` entries for **one merchant + one currency + the target billing period**
(`Africa/Nairobi` boundaries) `FOR UPDATE SKIP LOCKED`, exclude already-linked entries, integer-sum, emit
**one** `platform_fee_rollup` item, link each source entry (`pending→aggregated→invoiced`), idempotency key
per `(merchant, billing_period, currency)`. **Invariants:** an entry cannot be aggregated twice; different
merchants/currencies never share a line; fixed-only contributes zero rollup; issued subscription invoices
remain immutable; failed issuance consumes **no** invoice number and marks no entry. Rerun returns the
same result or a typed already-processed response. **No Wallet call/outbox/table; `account_reference`
remains 20D-W-governed.**

### E9 — Permissions & legacy-key reconciliation

**Legacy live keys** (`docs/auth/permission-matrix.yaml`, `owning_phase: Phase 20B`,
`canonical_successor: null`, "legacy runtime key pending §19 reconciliation"): `platform_fees.view`
(default_roles merchant_admin/branch_manager/audit; `billing_read_only_behavior: allow_read`) and
`platform_fees.dispute` (finance; `block`). Existing platform keys: `platform.billing_reconciliation.view`
/ `.resolve`.

**Decision (one coherent model, updated atomically across YAML / `PermissionRegistry` PHP / DB projection /
TypeScript).**

| Actor | Capability | Key (canonical) | Scope | MFA / step-up | Billing read-only | Period-lock | Audit sev |
|---|---|---|---|---|---|---|---|
| Super-Admin | configure %-fee config (create/approve/supersede/cancel) | `platform.platform_fee.configure` | platform | MFA + fresh step-up | n/a | n/a | critical |
| Super-Admin / Merchant-Admin / Finance / Branch-Mgr / Audit | view fee entries (scoped, masked) | reconcile `platform_fees.view` → `platform.platform_fee.view` (scoped variants) | merchant/branch | no | allow_read | n/a | info |
| Merchant-Admin / Finance | raise dispute | reconcile `platform_fees.dispute` → `platform.platform_fee.dispute` | merchant | no | block | enforce | warn |
| Finance | review/resolve/reject dispute; create adjustment | `platform.billing_reconciliation.resolve` (+ maker/checker) | merchant | fresh step-up on resolve | block | enforce | warn/critical |
| Front-Office | client-shifted invoice-line visibility only | invoice-line read (no new key) | branch | no | allow_read | n/a | — |
| Audit | masked read-only review | existing audit read | branch | no | allow_read | n/a | info |

- Legacy keys `platform_fees.view` / `platform_fees.dispute` get a `canonical_successor` and are retired
  only after the four parity layers are updated atomically and their positive/negative tests pass. If a
  legacy key must remain live for one release, it is kept with a recorded matrix decision (not casually
  deleted). **Final key names are fixed during Increment 6** against `PermissionRegistry` + generated
  types; the table above is the Increment-1 reconciliation **intent** only.
  > **Superseded — see Increment 6 "6A" below.** The final canonical keys differ from this early intent:
  > the merchant read is `platform_fee.view` (not `platform.platform_fee.view`), dispute review/resolve/reject
  > is the dedicated merchant-side `platform_fee.dispute.review` (NOT `platform.billing_reconciliation.resolve`,
  > which the product owner ruled is Phase 20D-W Wallet-owned). Product-owner decision Option A (2026-07-13)
  > authorized all four keys into the Plan §19.2 catalogue + §19.3 populated matrix; legacy ratchet 12→10.
- `REM-WALLET-001`, Phase 20E-external, and future-phase obligations are **not** closed here.

## Data-dictionary plan

`docs/architecture/data-dictionary/billing-and-wallet.md` gains the four Phase 20E tables + the additive
nullable snapshot columns on `invoices` / `invoice_items` (P17) and the `platform_fee_ledger_entries →
subscription_invoice_items` link (P20B), each with full column/type/CHECK/FK/ON DELETE/unique/partial-
unique/exclusion/index/immutability/idempotency/retention/audit/factory/test/backfill spec. No duplicate
dictionary.

## Migration plan (registered in `docs/architecture/migrations/manifest.yaml`)

Forward-only expand/contract; no shipped migration edited; timestamps after
`2026_07_12_000006`. Categories: create the four tables; additive nullable %-fee snapshot columns on
`invoices`/`invoice_items`; ledger→`subscription_invoice_items` link; immutability triggers (monetary
columns + DELETE) on `platform_fee_ledger_entries` and `platform_fee_adjustments`; source/idempotency/
aggregation indexes; config-window exclusion constraint. **Backfill: none** — no historical fee liabilities
fabricated; existing fixed-only records unchanged; existing nullable seams stay null.

## State-machine plan

`docs/architecture/state-machines/platform-fee-configuration.md`,
`platform-fee-ledger-entry.md`, `platform-fee-dispute.md` (states, transitions, actor/permission,
tx boundary, lock, idempotency, audit, DB enforcement, positive/negative tests). Subscription-invoice and
merchant-client invoice state machines updated only where 20E adds a documented integration transition.

## Increment status

- **Increment 1 (this) — COMPLETE:** baseline ✅ · branch ✅ · Phase 20C reconciliation ✅ (proof /
  PROGRESS / CHANGELOG / traceability) · Gate W CLOSED recorded ✅ · E1–E9 resolved ✅ · data dictionary
  (4 tables + invoice/item expands) ✅ · 3 state machines ✅ · traceability row `SRV-PLATFORM-FEE-001` ✅ ·
  PROGRESS Phase 20E section ✅. `MigrationManifestTest` re-run **9 passed** after the doc edits.
- **Migration manifest note:** the 7 planned migrations (§Migration plan) are **registered in
  `docs/architecture/migrations/manifest.yaml` in Increment 2, together with the migration files** — the
  repo `MigrationManifestTest` forbids manifest entries that have no file on disk, so early registration
  would break the lint. The full plan is recorded above; registration is deferred by one increment to
  keep the manifest lint green.
- **Next action:** Increment 2 — create the four migrations + the two invoice expands + the immutability
  triggers, register each in the manifest, add enums / models / factories / PostgreSQL constraints, write
  schema tests, prove a fresh migrate+seed on PG16. No partial commit; dirty tree preserved on the branch.

## Increment 2 — migrations, enums, models, factories, database guards (COMPLETE + green)

**Migrations (6, forward-only; no shipped migration edited):** `2026_07_13_000001..000004` create the four
tables; `000005/000006` additive nullable expands on `invoices`/`invoice_items`. Immutability triggers are
inline in the create migrations (ledger: block DELETE + block monetary/snapshot UPDATE, permit only
`status` + `subscription_invoice_item_id`; adjustments: block UPDATE + DELETE; disputes: block DELETE).
Config overlap via `EXCLUDE USING gist` over `(billing_mode, currency, daterange)` for active+scheduled.
All 6 registered in the manifest (94 entries total).

**Enums (7):** `PlatformFeeConfigurationStatus`, `PlatformFeeLedgerStatus`, `PlatformFeeEntryType`,
`PlatformFeeAdjustmentType`, `PlatformFeeDisputeStatus`, `PlatformFeeBasisType`, `CanonicalPlatformFeeTier`
(the single `split_tier → shared` mapping via `fromMerchantTier()`).

**Models (4) + factories (4):** `PlatformFeeConfiguration` (EXEMPT/platform), `PlatformFeeLedgerEntry` /
`PlatformFeeAdjustment` (append-only, `UPDATED_AT` disabled, `BelongsToMerchant`), `PlatformFeeDispute`
(`BelongsToMerchant`). `TenantOwnership`: config → EXEMPT; ledger/adjustment/dispute → TENANT_OWNED +
MODELS `tenant`.

**Tests / gates (PostgreSQL 16):**
- `Phase20ESchemaTest` (group `phase20e-schema`) — **24 passed / 45 assertions**: table existence,
  ownership registration, factory validity, currency-upper, bps/split ranges, mode-shape + shared-split
  coherence, config overlap reject + adjacent allow, split-sum + liability invariants, provenance,
  idempotency uniqueness, raw-UPDATE/DELETE immutability (ledger + adjustment), status-only transition
  allowed, escalated rejected at DB CHECK, dispute target/no-delete, invoice/item expand columns, zero
  historical backfill.
- `Phase20EEnumParityTest` — **12 passed**: every PHP enum == its PostgreSQL CHECK; no `escalated`; no
  `provisional`/`billable`/`settled`.
- `TenantColumnCoverageTest` + `ModelTenancyTraitCoverageTest` — **21 passed / 317 assertions**.
- `MigrationManifestTest` — **9 passed** (no dangling/missing/dupe; deps resolve).
- Pint **clean (270 files)**; Larastan **level 8 — no errors (927 files)**.
- **Disposable PG16 proof:** DB `servana_p20e_proof` (never the dev DB) — `migrate:fresh --seed` applied
  **94 migrations** + seeders clean; all 4 tables + **5 immutability triggers** present; fee tables
  **0/0/0/0 rows** (no historical backfill); disposable DB **dropped** (cleanup verified).

**Defect fixed (test-defect, not implementation):** DEF-20E-001 — `it rejects an escalated dispute status`
first failed with `ValueError` (enum cast rejected `escalated` in PHP before the DB CHECK ran). Root cause:
the factory/model path casts `status` to the enum. Fix: exercise the DB CHECK directly via a raw `UPDATE`
to `status='escalated'` (disputes permit UPDATE; the CHECK is the guard). Re-run: 24 passed.

**Next action:** Increment 3 — `PlatformFeeConfigurationStateMachine`,
`ResolveEffectivePlatformFeeConfiguration`, `ResolveMerchantServiceFeeTier`, `ResolvePlatformFeeBasis`,
`CalculatePlatformFee` (integer round-half-up), `AllocatePlatformFeeByLargestRemainder` + unit/property/
boundary tests. No partial commit; dirty tree preserved.

## Increment 4B — Finance-validation liability creation (COMPLETE + green)

**Validation-source insertion point:** `ValidatePaymentRecordingGroup` step **4b** — after the invoice
`validated_paid_minor` projection update (step 4) and the `PaymentValidationEvent` creation (step 1),
inside the same transaction, before receipt issuance (step 5). Existing lock order (group → components →
invoice), maker/checker, period lock, invoice-state, receipt, and audit work are all preserved and
untouched. Any failure in 4b rolls back the whole validation.

**Liability source granularity:** invoice-level (one `earned` entry per `PaymentValidationEvent`,
`source_invoice_item_id` NULL) — the group validation recognises an invoice-level validated amount. The
DB replay invariant `(source_validation_event_id, source_invoice_item_id) NULLS NOT DISTINCT WHERE
earned+event` guarantees one earned row per event; item-level provenance stays on the invoice items.

**Implementation:**
- `RecordOriginalPlatformFeeLiability` (Billing service) — reads the **immutable finalization snapshot on
  the invoice** (never re-resolves config). `validated_paid_amount` (customer_centric) → per-event
  `round_half_up(event.validated_amount × rate / 10000)`; snapshot bases → proportional
  `round_half_up(snapshot_gross × validated_paid_minor / total_minor) − Σ prior earned`; zero new-earned →
  no row; zero-total invoice with a non-zero fee → fail closed (no divide-by-zero). Creates `earned`/
  `pending`, links `source_validation_event_id`, stamps `billable_at`, idempotency `earned:{invoice}:{event}`.
- `CalculatePlatformFee::splitByTier()` — splits an already-computed earned portion by tier (no rate).
- `AuditEvent::PlatformFeeOriginalRecorded` (`platform_fee.original_recorded`, Info, Finance domain) —
  emitted only when an entry is created, safe context (ULIDs + amounts, no PII).
- `ValidatePaymentRecordingGroup` extended (constructor + step 4b + audit).

**Tests / gates (PostgreSQL 16):**
- `PlatformFeeBillabilityTest` (group `phase20e-billability`) — **6 passed / 44 assertions**: fixed-only
  validation creates nothing; recording-alone creates nothing; full validation → one earned/pending entry
  (gross 12500, liability=gross, billable_at, source_validation_event_id, provenance); **proportional
  partial release + residual capture** (250k+250k → 6250+6250 = snapshot 12500); `validated_paid_amount`
  per-event (200k→5000, 300k→7500, shifted 0); snapshot immutability + idempotency-key stamping.
- Regression: full `phase20e` group **80 passed / 210 assertions**; Phase 18B
  validation+atomicity+rejection **17 passed / 112 assertions**. Pint clean; Larastan level 8 clean.

**Next action:** Increment 5 — `AggregatePlatformFeesIntoSubscriptionInvoice` (reuse P20B issuance;
`pending→aggregated→invoiced`; `platform_fee_rollup` line; concurrency-safe; no Wallet) + reversal/
adjustment hooks (void/refund/correction) + dispute actions.

## Increment 4A — merchant-client invoice finalization integration (COMPLETE + green)

**Gate 4.2 (product-owner decision):** `validated_paid_amount` is valid **only with `customer_centric`**.
Enforced at two levels: (1) DB CHECK on `platform_fee_configurations`
(`fee_basis_type <> 'validated_paid_amount' OR tier_behavior = 'customer_centric'`); (2) resolved-tier
domain guard in the finalization service (fails closed when a merchant tier override differs from the
config default). No separate finalization-basis concept was invented.

**Gate 4.1 (non-circular total):** `merchant_client_invoice_total` basis = the Phase-17
`InvoiceTotalsCalculator` result (subtotal + tax − discount + preferred fee) **before** the Phase 20E
client-shifted amount is added. Documented in the data dictionary. The fee is never computed on a total
containing that fee.

**Gate 3.7 (structural replay invariant):** added partial-unique
`(source_validation_event_id, source_invoice_item_id) NULLS NOT DISTINCT WHERE earned+event` — one earned
entry per validation event/item at the DB level (invoice-level rows collide on NULL item). `idempotency_key`
is an additional application control, not a substitute.

**Implementation:**
- `RecordPlatformFeeAtFinalization` (Billing service) — inert in fixed-only mode
  (`PlatformFeeFinalizationResult::inactive()`); for percentage modes resolves settings/config/tier
  (`split_tier→shared`)/basis, computes the integer fee + tier split, allocates per-item provenance by
  largest remainder, writes item provenance, returns the header snapshot + client-shifted delta. Does
  **not** create a ledger row (earning is at validation).
- `ResolvePlatformFeeBasis` (query) + `PlatformFeeFinalizationResult` VO +
  `PlatformFeeException::validatedPaidRequiresCustomerCentric`.
- `FinalizeInvoice` extended inside its existing transaction: fee resolved **before** number allocation
  (fail-closed consumes no number), client-shifted added to `total_minor`, structured snapshot persisted,
  fee audit context added. Legacy `percentage_fee_config_snapshot` seam stays null.
- **Constraint-expand migration** `2026_07_13_000007` (forward-only; shipped P17 migration untouched):
  widened `invoices_total_arithmetic_check` by `+ COALESCE(platform_fee_client_shifted_minor, 0)` so the
  shifted amount is a valid part of `total_minor`.

**Tests / gates (PostgreSQL 16):**
- `PlatformFeeFinalizationTest` (group `phase20e-finalization`) — **9 passed / 33 assertions**: fixed-only
  no-op (no snapshot/no ledger/no provenance); customer_centric (total unchanged, gross 12500, 0 shifted);
  business_centric (total += 12500); shared (total += 6250); item split reconciles to gross; fail-closed
  missing config (draft preserved, no number); `validated_paid_amount` + customer_centric accepted;
  Gate 4.2 fail-closed on business_centric merchant override (no number); no recalculation after config
  change.
- Regression: `FinalizeInvoiceTest` 9 + `InvoiceCorrectionTest` + `Phase20ESchemaTest` 25 +
  `MigrationManifestTest` 9 = **47 passed** combined. Pint clean; Larastan level 8 clean.

**Root-cause note (DEF-20E-003, implementation):** the shipped `invoices_total_arithmetic_check` rejected
the client-shifted total. Root cause: the P17 invariant predates the Phase 20E shifted line. Fix: a
forward-only expand migration widened the CHECK (not editing the shipped migration); backward-compatible
via `COALESCE(...,0)`. Re-run: finalization 9 pass, Phase 17 regression 38 pass.

**Next action:** Increment 4B — `RecordOriginalPlatformFeeLiability` hooked into
`ValidatePaymentRecordingGroup` (proportional per-event billability, idempotent per `PaymentValidationEvent`,
`billable_at`) + `PlatformFeeBillabilityTest` + Phase 18B regression.

## Increment 4 pre-work — partial-payment billability reconciliation + migration correction

**Validation-source identity (confirmed against `ValidatePaymentRecordingGroup`):** Phase 18B validates a
whole recording group atomically, writing one immutable `PaymentValidationEvent` with a discrete
`validated_amount_minor` and advancing `invoices.validated_paid_minor` (→ `Paid` at `total_minor`, else
`PartiallyPaid`). There is **no** partial-group validation. Therefore the canonical validation-source
identity for the platform-fee ledger is **`payment_validation_event_id`** — one earned entry (invoice-level)
or one per item (item-level) per validation event.

**Partial-payment billability (settled; matches the assignment's controlling interpretation — no
product-owner question needed):**
- Finalization-snapshot bases (`merchant_client_invoice_service_subtotal`, `merchant_client_invoice_total`,
  `net_after_discount`, `invoice_item_subtotal`): proportional release per validation event —
  `cumulative_target = round_half_up(snapshot_total_fee × invoices.validated_paid_minor / invoices.total_minor)`;
  `new_billable = cumulative_target − already_earned(invoice)`. The final validation (validated == total)
  captures the residual, so cumulative earned == the snapshot total exactly.
- `validated_paid_amount`: per event — `round_half_up(event.validated_amount_minor × rate / 10000)`.
- Invariant satisfied: a partial validated payment makes only its corresponding liability billable;
  unvalidated balance never becomes billable. Recording/rejection/replay create nothing.

**Migration correction (uncommitted `2026_07_13_000002` edited before any hook):** added
`source_validation_event_id` (nullable FK → `payment_validation_events`, RESTRICT) + index, included it in
the append-only immutable tuple. `idempotency_key` remains the replay guard
(`earned:{invoice}:{event}[:{item}]`). Data dictionary, model (fillable + relation + PHPDoc), and manifest
(dependency + note) updated. Re-run: `Phase20ESchemaTest` **25 passed**, `Phase20EEnumParityTest`/
`MigrationManifestTest`/`TenantColumnCoverageTest` green (50 passed combined), Pint clean. This proves:
one entry per intended validation source; replay no duplicate; different validated groups → separate
proportional entries; cross-tenant impossible (FK RESTRICT + tenant merchant_id); corrections trace the
original (`source_validation_event_id` + `reversed_entry_id`).

**Extension points identified:** finalization = `app/Domain/Invoicing/Actions/FinalizeInvoice.php`
(currently sets `percentage_fee_config_snapshot = null` at the Gate E seam — Phase 20E populates the
structured snapshot + client-shifted line for percentage modes; fixed-only stays inert). Validation =
`app/Domain/Payments/Actions/ValidatePaymentRecordingGroup.php` (hook after the invoice
`validated_paid_minor` update, inside the same transaction).

## Increment 3 — configuration state machine, resolvers, arithmetic engine (COMPLETE + green)

**Services/Queries/ValueObjects added:**
- `CalculatePlatformFee` (Services) — integer `round_half_up(basis * bps / 10000)` + tier split; returns
  `CalculatedPlatformFee` (VO) which re-asserts `shifted + absorbed = gross` and `liability = gross`.
- `AllocatePlatformFeeByLargestRemainder` (Services) — generic largest-remainder `allocate()` (floor +
  descending-remainder residual, ascending-ULID tie-break, `ksort` for deterministic output) and
  `allocateFee()` (applies twice — gross then client-shifted — so both item sums reconcile); returns
  `AllocatedPlatformFeeItem` VOs.
- `ResolveMerchantServiceFeeTier` (Queries) — the single consumer of the `merchants.service_fee_tier`
  seam; maps `split_tier → shared` via `CanonicalPlatformFeeTier::fromMerchantTier`, precedence
  merchant→config-default, fail-closed (`PlatformFeeException::missingTier`).
- `ResolveEffectivePlatformFeeConfiguration` (Queries) — `find()` (null for fixed-only / inert) +
  `require()` (fail-closed `PlatformFeeException::missingConfiguration`); effective active config by
  half-open window, mode, uppercase currency.
- `PlatformFeeConfigurationStateMachine` + `PlatformFeeDisputeStateMachine` (Services) — delegate to the
  enum `allowedTransitions()` inventories; forbidden pair → `BillingStateException`
  (`422 invalid_state_transition`). Ledger status transitions also added to the enum.
- `PlatformFeeException` — fail-closed 422 envelope (missing config / missing tier / shared-split missing /
  currency mismatch).

**Tests / gates (PostgreSQL 16):**
- `PlatformFeeCalculationTest` (group `phase20e-calc`) — **21 passed / 52 assertions**: zero basis;
  half below/at/above; 0/1/10000 bps; large-amount no-float; customer/business/shared tier; shared
  fail-closed; currency upcasing; largest-remainder sum/determinism/residual 0-1-multi/zero-weight;
  `allocateFee` gross+shifted sums; `split_tier→shared` mapping + precedence + fail-closed; config +
  dispute state-machine allow/deny.
- `PlatformFeeConfigurationResolutionTest` (group `phase20e-resolution`) — **6 passed**: effective-by-date;
  fixed-only inert (null); fail-closed missing; superseded/other-currency/not-yet-started not resolved.
- Pint **clean**; Larastan **level 8 — no errors (936 files)**.

**Defect fixed (test/impl-ordering, not math):** DEF-20E-002 — the determinism assertion compared arrays
order-sensitively; the allocator returned correct per-key values but in input-insertion order. Fix:
`ksort` the allocator output so ordering is deterministic regardless of input order (strengthened
guarantee). Re-run: 21 passed.

**Next action:** Increment 4 — extend the Phase 17 finalize action (config/rate/basis/tier snapshot +
client-shifted line, atomic, fixed-only inert) and add the Phase 18B validation billability hook
(`RecordOriginalPlatformFeeLiability`, idempotent per allocation, `billable_at`), with regression tests.

## Increment 5 — aggregation, reversals/adjustments, disputes (COMPLETE + green)

Scope: 5A aggregate earned liabilities into the subscription invoice; 5B additive reversals/adjustments
through the void/refund/correction workflows; 5C the canonical dispute workflow. **Not started:** Increment 6.

### 5A — aggregation into subscription invoices

**Exact Phase 20B architecture inspected (recorded per assignment §4.1):** issuance action
`App\Domain\Billing\Actions\IssueSubscriptionInvoice` (single `DB::transaction`; `lockForUpdate` on
`MerchantSubscription`; one-invoice-per-`(merchant, period_start, period_end, status≠void)` idempotency);
number allocator `App\Domain\Billing\Services\AllocateSubscriptionInvoiceNumber` (independent
`subscription_invoice` sequence scope, gap-free, allocated only inside the tx); billing-cycle resolver
`App\Domain\Billing\Services\BillingIntervalCalculator` (`TIMEZONE = Africa/Nairobi`); line-type
`SubscriptionInvoiceItemType::PlatformFeeRollup` (pre-existing); audit `subscription_invoice.issued`.

**Design (forced by the schema):** `subscription_invoices.plan_id/price_id` are NOT NULL, the header
CHECK is `total = subtotal − discount`, and an issued invoice's financial snapshot is immutable
(`SubscriptionInvoice::IMMUTABLE_AFTER_ISSUE`). Therefore the rollup is **folded into the period invoice
at issuance**, not added to an issued invoice, and there is **no second subscription-invoice aggregate**.
`AggregatePlatformFeesIntoSubscriptionInvoice` is a collaborator of `IssueSubscriptionInvoice` with two
phases inside the one issuance transaction:
- `collectEligible(merchant, currency, periodStart, periodEnd)` — before the invoice row is created,
  `FOR UPDATE`-locks the eligible entries and returns the integer total so it is part of the immutable
  subtotal (`subtotal = plan_price + rollup_total`; the promotion still applies to the plan fee only).
- `writeRollup(invoice, selection, actor)` — after the invoice exists, creates ONE `platform_fee_rollup`
  item, links each entry, and transitions `pending → aggregated → invoiced`.

**Eligibility (all required):** `entry_type='earned'`, `status='pending'`, `billable_at` inside the
period, matching merchant + currency, `subscription_invoice_item_id IS NULL`. Excluded: fixed-only (no
earned rows), reversal/adjustment rows (`entry_type≠earned`), other tenants (explicit `merchant_id` filter
+ the `BelongsToMerchant` scope), other currencies, already-aggregated/invoiced entries.

**Billing-period boundary:** the subscription's `current_period_start..current_period_end`, compared as
`(billable_at AT TIME ZONE 'Africa/Nairobi')::date >= period_start AND < period_end` — inclusive start,
exclusive end; proven by the boundary test (an entry at `2026-06-30 23:59:59` and one at the exclusive end
`2026-08-01 00:00:00` are excluded; `2026-07-01 00:00:00` is included). UTC timestamps resolve to the
correct Nairobi calendar date.

**Source ordering:** deterministic `billable_at ASC, ulid ASC` under `lockForUpdate`. One deterministic
rollup per merchant/currency/cycle (no batching).

**Cycle-level uniqueness/idempotency guard:** the existing `MerchantSubscription` row lock + the
one-invoice-per-period application idempotency, **hardened at the database** by new forward-only migration
`2026_07_13_000008` — a partial `UNIQUE INDEX subscription_invoice_items (subscription_invoice_id) WHERE
type='platform_fee_rollup'` (≤ one rollup line per cycle invoice). Not an application-only pre-check
(assignment §4.4). Registered in the manifest (now **95 entries**).

**Rollback-safe numbering + atomicity:** the number is allocated only inside the successful transaction;
a forced failure (throwing audit recorder) after allocation leaves **no invoice, no consumed number, no
rollup item, no entry link, no status change, no success audit** (proven).

**Audit:** `platform_fee.aggregated` + `platform_fee.invoiced` (Info, Finance) — safe ULIDs, integer
totals, entry count, period; no internal ids/references.

### 5B — reversals & adjustments (void / refund / correction)

Extended actions (no duplicate workflows): `ExecuteInvoiceVoid` (Invoicing) and `FinalizeRefund` (Refunds)
— each already enforces `FinancialPeriodGuard` + maker/checker; the new services hook **inside** their
existing transactions. Services: `RecordPlatformFeeReversal` (full) delegating to the core writer
`RecordPlatformFeeAdjustment` (all four `adjustment_type`s).

**Append-only evidence:** each correction writes (1) a new `platform_fee_ledger_entries` row
(`entry_type='reversal'` for a full reversal, else `'adjustment'`; `reversed_entry_id`=original; magnitude
split by the original tier snapshot; `status='pending'` so it is eligible for the next authorized cycle)
and (2) a `platform_fee_adjustments` row carrying the SIGNED amount, reason, source reference, actor,
business date. The original earned monetary fact is **never edited**; only its non-monetary `status`
marker moves to `reversed`/`adjusted` (and only while it is on a billing status — the remaining balance,
not the marker, gates further corrections, so multiple partials work).

**Sign & balance rules:** `reversal`/`partial_refund` amounts are negative (DB CHECK + service guard); a
negative amount may not exceed `remaining = original.gross + Σ signed prior adjustments` → else `409`
`platform_fee_over_reversal`. Currency always equals the original's (structurally — copied), so
cross-currency correction is impossible; cross-tenant is impossible (the original carries `merchant_id`
under the tenant scope). **Source-event idempotency:** a partial-unique `idempotency_key` on BOTH tables
+ an application pre-check → replay returns the existing adjustment, writes nothing new.

**Void hook:** fully reverses every earned entry of the voided invoice (key
`reversal:invoice_void:{invoice}:{entry}`). **Refund hook:** full refund (invoice becomes fully unpaid)
→ full reversal of each earned entry; partial refund → a `partial_refund` adjustment proportional to the
refunded share of the previously-validated amount (`round_half_up(remaining × refund / validatedBefore)`),
distributed across entries' remaining balances by largest remainder. **Aggregated/invoiced liabilities:**
the issued subscription invoice is never rewritten; the correction is additive and eligible for the next
cycle. Period-lock + maker/checker are inherited from the host actions (proven: a locked period blocks the
void and writes no correction). Audit: `platform_fee.reversed` / `platform_fee.adjusted` (Warning).

### 5C — dispute workflow

State machine `PlatformFeeDisputeStateMachine` (`open → under_review → resolved | rejected`; no
`escalated`). Actions: `CreatePlatformFeeDispute`, `StartPlatformFeeDisputeReview`,
`ResolvePlatformFeeDispute`, `RejectPlatformFeeDispute`. Create requires a sanitised reason + ≥1 target,
each within the actor's tenant (a cross-tenant target → 404). Resolve/Reject require a resolution note and
block creator self-resolution (maker/checker); an invalid transition → `422 invalid_state_transition`
(`BillingStateException`); resolve enforces the period lock for a money change. A money-changing
resolution creates an additive `platform_fee_adjustments` row (`adjustment_type='dispute_resolution'`) via
the 5B writer — it never edits the ledger amount and never rewrites the issued subscription invoice
(proven). Money change with no ledger-entry target → 422. Audit:
`platform_fee.dispute_created`/`_review_started`/`_resolved`/`_rejected` (Info for review-started; Warning
otherwise; a money-changing resolution records the linked adjustment ULID in context — the original ledger
fact is never rewritten, so the base severity stays warning; the state-machine doc note is reconciled to
this single severity).

**Note (Increment 5/6 boundary):** the dispute actions enforce tenant scope + transitions + money rules at
the domain layer. HTTP routes/policies and the final canonical **permission keys** (E9) are reconciled in
Increment 6; no generic status endpoint is added.

### New audit events (8)

`platform_fee.aggregated`, `platform_fee.invoiced`, `platform_fee.reversed`, `platform_fee.adjusted`,
`platform_fee.dispute_created`, `platform_fee.dispute_review_started`, `platform_fee.dispute_resolved`,
`platform_fee.dispute_rejected` — all Finance domain; safe public ULIDs + integer amounts only.

### New/changed code

New: `AggregatePlatformFeesIntoSubscriptionInvoice`, `PlatformFeeLedgerEntryStateMachine`,
`RecordPlatformFeeReversal`, `RecordPlatformFeeAdjustment`, `PlatformFeeRollupSelection` (VO),
`Create/Start/Resolve/Reject PlatformFeeDispute` (4 actions), `PlatformFeeDisputeException`, migration
`2026_07_13_000008`. Changed: `IssueSubscriptionInvoice` (fold rollup at issuance), `ExecuteInvoiceVoid`
(reversal hook), `FinalizeRefund` (proportional correction hook), `AuditEvent` (8 cases + domain +
severity), `PlatformFeeException` (409 over-reversal + status), and the stale Phase 20B test
`SubscriptionInvoiceTest` (the `platform_fee_ledger_entries` table now exists — the assertion is
reconciled to "fixed-mode invoice earns no ledger row and no rollup line", intent preserved).

### Tests + gates (PostgreSQL 16)

- `PlatformFeeAggregationTest` (10) — pending-earned only; period boundary exact; no cross-merchant/
  currency mix; stable order + idempotent re-issue; one-link-once + one number; **cycle-guard DB block**;
  fixed-only no rollup; rollback-safe numbering; ledger state-machine invalid transition; no Wallet table.
- `PlatformFeeReversalAdjustmentTest` (10) — full reversal additive + original unchanged; proportional
  partial + remaining decremented; multiple partials then **409 over-reversal**; wrong-sign rejected;
  source idempotency (no duplicate); rollback writes nothing; **void hook** full reversal; **locked period
  blocks the void**; **FinalizeRefund** full + partial hooks.
- `PlatformFeeDisputeTest` (9) — create/reason/target; cross-tenant 404; open→under_review→resolved +
  invalid transitions; reject from open/under_review; maker/checker self-resolution block; money-changing
  resolution creates adjustment, ledger + issued subscription invoice unchanged; money change requires a
  ledger target; rollback writes no success audit.
- **Totals — targeted:** 29 Increment-5 tests green. **Full `tests/Feature/Billing`: 433 passed / 1349
  assertions** (Phase 20B/20C/20E incl. the reconciled `SubscriptionInvoiceTest`).
- **Regression:** `tests/Feature/Invoicing` (void/adjust/finalize) + `tests/Feature/Refunds`
  (request/approve/finalize/step-up/allocation) + `PaymentGroupValidationTest` = **62 passed / 267
  assertions**.
- **Gates:** `MigrationManifestTest` (95 entries, no dangling/dupe), `TenantColumnCoverageTest` +
  `ModelTenancyTraitCoverageTest`, `NoDirectProviderIntegrationTest`, `AuditEventCoverageTest`,
  `AuditMutationCoverageTest` = **31 passed / 351 assertions**. Billing state-machine tests within the
  Billing suite pass. **Pint clean (1244 files); Larastan level 8 — no errors (952 files).**

**Defects fixed during Increment 5:**
- DEF-20E-004 (test-defect): the Nairobi period-boundary test initially failed (rollup 3000 vs 2000)
  because Eloquent stores a Carbon wall-clock as UTC — a `parse($s, 'Africa/Nairobi')` value was persisted
  with the Nairobi digits treated as UTC. Fix: store the true UTC instant (`->utc()`) so it matches how
  production stamps `billable_at = now()` (UTC) and the `AT TIME ZONE` query round-trips correctly.
- DEF-20E-005 (pre-existing Phase 20B regression surfaced by Increment 2): `SubscriptionInvoiceTest`
  asserted `platform_fee_ledger_entries` did not exist; the Increment-2 migration created it. Fix: the
  assertion now proves the fixed-mode invoice creates no ledger row and no rollup line (intent preserved).
- Test-setup issues (nested `Invoice` factory under a bound tenant; the config-overlap exclusion; the
  refund state machine requiring `refund_pending`) were resolved in the tests, not the implementation.

**Remaining risk:** the future-cycle aggregation of `pending` reversal/adjustment ledger rows into an
`adjustment` subscription-invoice line was closed after Increment 6 — see **Backend closure — future-cycle
correction aggregation** below. Dispute HTTP surface + final permission keys land in Increment 6.

## Increment 6 — canonical permissions, HTTP API, audit/route-security, OpenAPI/TS (COMPLETE + green)

Scope: 6A permission reconciliation (Gate E9); 6B HTTP API + policies + requests + controllers + Resources;
6C typed audit + route-security coverage; 6D deterministic OpenAPI + generated TypeScript + permission
metadata. **Not started:** Increment 7 (frontend/E2E).

### 6A — canonical permission reconciliation (Gate E9)

**Reconciliation table (final):**

| Capability | Legacy | Canonical | Default roles | Scope | MFA | Step-up | Period lock | Maker/checker | Audit sev |
|---|---|---|---|---|---|---|---|---|---|
| Configure %-fee config | — (new) | `platform.platform_fee.configure` | super_admin | platform | Y | Y (BillingConfiguration) | n/a | approver≠creator (advisory) | high |
| View %-fee entries (masked) | `platform_fees.view` | `platform_fee.view` | merchant_admin, branch_manager, finance, audit | merchant | N | N | n/a | n/a | info |
| Raise a dispute | `platform_fees.dispute` | `platform_fee.dispute` | merchant_admin, finance | merchant | N | N | n/a | n/a | warn |
| Review/resolve/reject a dispute | — (new) | `platform_fee.dispute.review` | finance | merchant | Y (resolve/reject) | Y | enforced (money) | creator≠resolver | warn |

- **Legacy decisions:** `platform_fees.view` / `platform_fees.dispute` **retired** and replaced by the
  singular canonical `platform_fee.view` / `platform_fee.dispute` (matching the Phase 20E `platform_fee_*`
  domain naming); merchant-scoped keys keep **no `platform.` prefix** (that prefix is platform scope in
  this repo — only the config key is `platform.platform_fee.configure`). Two genuinely-new keys added.
  Merchant Admin gains `platform_fee.dispute`; Finance gains `platform_fee.view` (settled/reconciliation
  read, moved from grantable-only to default) + `platform_fee.dispute` + `platform_fee.dispute.review`.
- **Role boundaries proven:** Super-Admin config only (no payment validation / ledger fabrication /
  settlement); Merchant Admin merchant-wide read + dispute create (no configure/resolve/adjust); Branch
  Manager branch-attributable read only; Finance reconciliation read + dispute review/resolve/reject
  (period-lock + maker/checker + step-up preserved); Front Office no merchant-wide API (client-shifted
  invoice line only — no new key); HR/Personnel none; Audit masked branch read only.
- **Parity updated atomically across all layers:** `docs/auth/permission-matrix.yaml` (4 entries),
  `PermissionRegistry` PHP (definitions + role grants + grantable), the DB projection (derived from the
  registry by `PermissionSeeder`; old keys pruned), the generated `permissions.ts` (regenerated by
  `servana:permission-types`), the policies/middleware/routes, `PermissionMatrixTest` (independent expected
  grants), and `docs/proof/phase8-matrix.txt` (regenerated by `PermissionMatrixTest`). Gates:
  `PermissionMatrixSchemaTest`, `PermissionMatrixTest`, `PermissionMatrixPlanMetadataParityTest`
  (including the route-derived `audit_event` parity) all green. **`REM-PERM-001` remains OPEN**
  (Phase 19-owned; its closure criteria are not touched here).
- **Canonical-catalogue correction (product-owner decision, 2026-07-13; Option A).** The pre-reboot draft
  renamed the two plural legacy keys but did **not** add the four Phase 20E keys to the Plan §19.2 canonical
  catalogue, so the `PermissionLegacyKeyReconciliationTest` legacy-active ratchet still counted them as
  *legacy* (12 → 14 — the sole failing gate at recovery). The product owner ruled the §19.2 omission a
  **catalogue gap, not** authority to classify the new keys as legacy and **not** authority to move the
  merchant-side dispute workflow into the Phase 20D-W `platform.billing_reconciliation.*` Wallet domain. The
  four keys — `platform_fee.view`, `platform_fee.dispute`, `platform_fee.dispute.review`,
  `platform.platform_fee.configure` — are therefore **authorized as canonical** and added to the active v4
  Plan: §19.2 catalogue (`platform.platform_fee.configure` under Platform Governance; the three merchant
  keys under Finance) **and** the §19.3 populated matrix with their role/scope/control metadata. In the YAML
  their `owning_phase` is cleared to `null` (canonical form; `canonical_successor` already `null`).
  **Ratchet arithmetic:** previous legacy-active 12 − 2 retired plural keys (`platform_fees.view`,
  `platform_fees.dispute`) = **10**; the four successors are canonical, not counted. §19.2 canonical
  catalogue **156 → 160**; §19.3 populated matrix **156 → 160**. `PermissionLegacyKeyReconciliationTest`
  asserts **10** (not weakened/bypassed); `PermissionMatrixCatalogueCompletenessTest` and
  `PermissionMatrixPlanMetadataParityTest` assert **160**. All auth/matrix gates green (19 passed).

### 6B — HTTP API, policies, requests, controllers, Resources

**Config-management actions built (Increments 1–5 shipped only the state machine):**
`CreatePlatformFeeConfiguration`, `UpdatePlatformFeeConfigurationDraft` (draft-only; an approved config is
immutable → 422 `platform_fee_configuration_not_editable`), `ApprovePlatformFeeConfiguration`
(draft/scheduled → active/scheduled server-derived from `effective_from` in `Africa/Nairobi`; advisory
lock + `23P01` → 409 `platform_fee_configuration_overlap`), `SupersedePlatformFeeConfiguration`
(active → superseded + new version), `CancelPlatformFeeConfiguration` (draft/scheduled → cancelled). The
dispute + reversal/adjustment actions from Increment 5 are REUSED unchanged.

**Route inventory (16 routes):**
- Platform config (7; `ResolvePlatformContext`, `platform.platform_fee.configure`, MFA group +
  `platform_mutation` class + fresh BillingConfiguration step-up on mutations + idempotency on
  store/approve/supersede/cancel): `GET/POST /platform/billing/platform-fee-configurations`,
  `GET/PATCH …/{configuration}`, `POST …/{configuration}/approve|supersede|cancel`.
- Merchant reads (3; `platform_fee.view`, server-side scope): `GET /platform-fees`,
  `GET /platform-fees/summary`, `GET /platform-fees/{entry}`.
- Disputes (6): `POST /platform-fee-disputes` (`platform_fee.dispute`, tenant_mutation + idempotency),
  `GET /platform-fee-disputes[/{dispute}]` (`platform_fee.view`), `POST …/{dispute}/review`
  (`platform_fee.dispute.review`, tenant_mutation, bodiless → VALIDATION_EXEMPT),
  `POST …/{dispute}/resolve` (financial_mutation, step-up + idempotency + period lock),
  `POST …/{dispute}/reject` (tenant_mutation, step-up). New `StepUpAction::PlatformFeeDisputeResolution`.

**Routes intentionally NOT added:** no reversal/adjustment routes (owned by void/refund/correction/dispute
resolution), no manual aggregation route (issuance/scheduler-owned; Plan requires no manual retry), no
generic status endpoint, no DELETE (proven `405`), no Wallet/provider callback.

**Architecture:** every route follows Form Request → policy (`PlatformFeeConfigurationPolicy` /
`PlatformFeeLedgerEntryPolicy` / `PlatformFeeDisputePolicy`) → platform-or-tenant context → MFA/step-up/
period/idempotency → thin controller → transactional action → masked Resource. Controllers never compute
fees, resolve tiers, mutate the ledger, or resolve disputes. Requests reject every server-owned field
(`status`, `merchant_id`, `branch_id`, actors, calculated amounts, snapshots) — proven by the "status
ignored" tests.

**Masking:** Resources expose ULIDs only (26-char ids proven; internal ids, `idempotency_key`,
`source_validation_event_id`, raw references, private evidence content never exposed). Server-side scope:
Merchant Admin merchant-wide; Finance/Branch Manager/Audit branch-attributable (`branch_id` in assigned
branches) — a foreign-tenant ULID → 404 (no leak), a cross-branch entry → 404 for a branch-scoped user
(both proven).

### 6C — typed audit + route-security coverage

5 new config audit events (`platform_fee.configuration_created/updated/approved/superseded/cancelled`,
General domain, High severity) added to `AuditEvent`; all 9 mutating routes registered in
`AuditMutationCoverage::AUDITED`. Gates: `AuditMutationCoverageTest` (every mutation AUDITED/EXEMPT, real
events only), `AuditEventCoverageTest`, `RouteSecurityContractTest` (every non-GET route exactly one valid
class + required/forbidden middleware + body validation or explicit exemption),
`FinancialRouteIdempotencyCoverageTest` (idempotency on every `financial_mutation`),
`NoDirectProviderIntegrationTest` all green.

### 6D — deterministic OpenAPI + generated TypeScript

OpenAPI regenerated (`servana:openapi`) — **235 production routes / 235 operations**, byte-deterministic
(two runs, identical sha256 `F8B9496A…2FE1B8C4`; all 13 platform-fee paths present). TypeScript API types
(`npm run api:types` / `openapi-typescript`, host Node 24 — the app container has no npm) + permission
metadata (`servana:permission-types`) regenerated from source; `api:contract:check` clean (196 paths / 235
operations); `permission:types:check` clean; `permissions.ts` regeneration byte-identical to the recovered
file. Generated artifacts are never hand-edited. **Note:** the recovered working tree carried a regenerated
`permissions.ts` but a **stale** `openapi.json` / `api.ts` (not regenerated pre-reboot); both were
regenerated here (generated-contract drift, not a defect).

### Tests + gates (PostgreSQL 16; final recovery run 2026-07-13)

- Increment-6 API — `PlatformFeeConfigurationApiTest` (9), `PlatformFeeMerchantApiTest` (12),
  `PlatformFeeDisputeTest` (9) = **30 passed / 98 assertions** (list; non-platform 403; create ULID-only
  status-ignored + audit; no-fresh-step-up 403; idempotent replay; bps>100% 422; create→update→approve
  lifecycle + approved-edit 422; supersede; overlap 409; merchant-wide/branch/masked reads; Front
  Office/Audit denials; foreign-tenant 404; cross-branch 404; review→resolve money-change → additive
  adjustment, ledger + issued invoice unchanged; maker/checker creator≠resolver; no DELETE 405).
- **Full `tests/Feature/Billing`** (all Phase 20A/20B/20C/20E) = **455 passed / 1422 assertions**.
- **Auth/matrix parity gates** — `PermissionMatrixSchemaTest`, `PermissionMatrixTest`,
  `PermissionMatrixParityTest`, `PermissionMatrixCatalogueCompletenessTest` (160),
  `PermissionMatrixPlanMetadataParityTest` (160), `PermissionLegacyKeyReconciliationTest` (10),
  `PermissionPlannedKeyIsolationTest` = **19 passed / 818 assertions**.
- **Security/audit/tenancy/boundary gates** — `RouteSecurityContractTest`,
  `FinancialRouteIdempotencyCoverageTest`, `NoDirectProviderIntegrationTest`, `ForbiddenRouteAbsenceTest`,
  `AuditMutationCoverageTest`, `AuditEventCoverageTest`, `AuditSeverityCoverageTest`,
  `TenantColumnCoverageTest`, `ModelTenancyTraitCoverageTest`, `AuthorityBoundariesTest`,
  `AuthorizationFreshnessTest` = **53 passed / 1126 assertions**.
- **Affected-phase regression** — `tests/Feature/Invoicing` + `Refunds` + `Audit` (Phase 17/18B/19) =
  **167 passed / 1336 assertions**.
- **Frontend contract gates** — `vue-tsc --noEmit` clean; ESLint **0 errors** (138 pre-existing style
  warnings in existing `.vue` files, untouched — Increment 6 is backend-only); Vitest **321 passed** (77
  files); `vite build` ✓ (built in 13.6s).
- `composer validate --strict` valid; Pint clean (1264 files); Larastan level 8 — **0 errors** (970 files).

### Recovery incident (2026-07-13 desktop reboot)

A desktop reboot interrupted Increment 6. On restart: **no recovered-file loss** — all 104 recovery files
(25 modified tracked + 79 untracked) hash-matched the external backup
(`Servana-Recovery-20260713-131810`) byte-for-byte (0 missing, 0 changed). Git integrity verified
(`git fsck` clean, `git diff --check` clean); branch/HEAD/origin-main/merge-base all `735f419…`,
divergence `0 0`. Docker/WSL recovered; PostgreSQL 16.14 completed automatic crash recovery and accepted
SQL; 11 compose services reached their expected final state (`minio-init` `Exited(0)` expected); all eight
Phase 20E migrations `Ran`; HTTP `/health` → 200. **Recovery ran no tests** — Increment 6 was independently
re-baselined (30 API + 455 Billing green at baseline; the single failing gate was the legacy-key ratchet,
resolved by the product-owner canonical-catalogue correction above, not by any test workaround). The
transient PowerShell `else`/`if` messages and the brief PostgreSQL "unhealthy" during crash recovery were
environment artifacts, not Servana defects — no product code, migration, health check, or DB setting was
changed on their account.

**Defects fixed during Increment 6:**
- DEF-20E-006 (implementation): approve/supersede computed the target state by parsing `effective_from`
  in the app (UTC) timezone, so a same-day "today" effective date resolved to `scheduled` instead of
  `active`. Fix: parse `effective_from` in `Africa/Nairobi` before comparing to the Nairobi start-of-day.
- DEF-20E-007 (larastan/type): the config actions received `array<string,mixed>` validated data but
  declared array-shape params. Fix: relaxed the `@param` to `array<string,mixed>` + explicit scalar casts.
- Test-setup: platform-scoped config is shared across merchants, so a second scenario reused the single
  active KES config instead of creating an overlapping one (config exclusion).

**Remaining risk:** the frontend platform-fee screens (Super-Admin config, merchant fee dashboard, Finance
reconciliation/dispute worklist) + E2E/a11y are Increment 7. The future-cycle aggregation of pending
reversal/adjustment ledger rows into an `adjustment` subscription-invoice line is now closed — see below.

## Backend closure — future-cycle correction aggregation (COMPLETE + green)

Closes the one Phase 20E-owned financial gap left after Increment 6: a pending correction (`reversal`/
`adjustment` of an already-billed fee) had no proven path into a later subscription invoice, so the
append-only ledger and the subscription-billing projection could diverge. Phase 20E owns subscription-
invoice aggregation + fee adjustments/reversals, and `subscription_invoice_items.type='adjustment'`
already exists — so this is acceptance-criteria completion, not new-phase work. **No migration; no route
or public-contract change** (issuance is system/action-driven).

**Root cause:** `AggregatePlatformFeesIntoSubscriptionInvoice::collectEligible()` selected only
`entry_type='earned' AND status='pending'`; correction rows (also `status='pending'`) were never selected,
so they never linked to a subscription-invoice item, never transitioned to `invoiced`, and stayed pending.

**Design (gates AR1–AR6):**
- **Eligibility (AR1):** `entry_type IN (reversal,adjustment)` · `status=pending` · `subscription_invoice_item_id IS NULL` ·
  matching merchant + currency · `(billable_at AT TIME ZONE 'Africa/Nairobi')::date < period_end` (sweep-forward,
  no lower bound — un-invoiced corrections from earlier periods carry) · **AND the original earned entry was
  billed** (`original.subscription_invoice_item_id IS NOT NULL`). The last gate is a correctness necessity:
  a correction of a never-invoiced original is skipped (that original was already dropped from the rollup by
  its `reversed`/`adjusted` marker), so no spurious credit is issued.
- **Signed line (AR2):** the canonical signed value per correction entry is its paired
  `platform_fee_adjustments.amount_minor` (never recomputed), located via the intentional idempotency linkage
  `ledger.idempotency_key = 'ledger:' || adjustment.idempotency_key`. One **net signed `adjustment` line per
  cycle** = Σ consumed signed amounts (invariant: Σ consumed = line amount). The `platform_fee_rollup` line
  and the original earned fact are untouched.
- **Arithmetic (AR3):** `subtotal = plan + rollup + appliedNet`; `total = subtotal − discount`; `balance =
  total`. Signed line feeds `subtotal` (no separate adjustments column); no DB constraint weakened.
- **Net-negative cycle (AR4):** authoritative rule (no product question): DB `subscription_invoices.subtotal/total ≥ 0`
  forbids a negative invoice; Phase 20E excludes Wallet credits (20D-W); ADR-005 + commission carry-forward
  (§2305 "negative adjustment in a **future** payout; paid history never rewritten"). Corrections are consumed
  greedily in `billable_at ASC, ulid ASC` order; a negative correction is applied only while the invoice stays
  non-negative (`Σconsumed ≥ −(plan+rollup−discount)`); a correction that would breach the floor is left
  `pending` (whole-entry carry-forward — an immutable row is never split, so no new financial concept). Residual =
  un-consumed pending entries. **Residual risk:** a single correction larger than a cycle's positive headroom
  waits for a future cycle with enough headroom.
- **Uniqueness (AR5):** no new DB constraint (a broad `type='adjustment'` unique would block unrelated
  adjustments). Protection = `subscription_invoice_item_id IS NULL AND status=pending` under `FOR UPDATE` +
  single FK + terminal `invoiced` state + one-invoice-per-(merchant,period) under the `MerchantSubscription`
  row lock — the same model the earned rollup relies on.
- **Transitions (AR6):** consumed corrections `pending → aggregated → invoiced` via `PlatformFeeLedgerEntryStateMachine`;
  `entry_type` preserved; the original `reversed`/`adjusted` markers keep their meaning (untouched).

**Implementation:** `AggregatePlatformFeesIntoSubscriptionInvoice::collectApplicableCorrections()` +
`writeCorrectionLine()`; `IssueSubscriptionInvoice` folds the applied net into the subtotal and writes the line
in the same transaction; new VO `PlatformFeeCorrectionSelection`. Audit reuses `platform_fee.aggregated` +
`platform_fee.invoiced` (correction context: net, residual count/amount) — **not** `platform_fee.adjusted`
(which means "a correction was created"), so no audit-catalogue change.

**Tests + gates:** `PlatformFeeCorrectionAggregationTest` = **8 passed / 46 assertions** — basic future-cycle
sweep + original immutable; no-spurious-credit for a never-invoiced original; mixed rollup + correction (two
lines); correction-only cycle (plan invoice always hosts it); net-negative cap + residual carry + no negative
invoice + no `merchant_billing_credits` table + residual consumed once next cycle; idempotent re-issue (no
duplicate line / no double-link); rollback links nothing; future-dated + cross-tenant exclusion. Full
`tests/Feature/Billing` **463 passed / 1468 assertions**; `Invoicing`+`Refunds`+`Audit`(event/severity/mutation)+
`NoDirectProviderIntegration`+`Tenancy`(column/trait) **83 passed / 1275 assertions**; Pint clean (1266 files);
Larastan L8 **0 errors**; `composer validate --strict` valid. **No Wallet/provider/credit runtime introduced.**

## Increment 7 — frontend platform-fee surfaces (COMPLETE + green)

The Vue 3 + Pinia surfaces for the six roles (Plan §51, §52, §13.10, §27.1). Backend stays authoritative:
every control is UX-gated by `useCan()` only; the API enforces authorization, MFA, fresh step-up,
server-side scope, period-lock, maker/checker and idempotency. Amounts are server-authoritative — the
browser only formats integer minor units (`utils/money.ts`); no fee money is recomputed in JavaScript. The
canonical `shared` tier is always shown by its label, never the persisted `split_tier`. No Wallet /
settlement UI.

**Stores/services (typed on generated `api.ts`, via the shared `apiClient`):** `platformFeeConfigStore`
(config list/show/create/update-draft + named approve/supersede/cancel — no generic status setter),
`platformFeeStore` (ledger list/summary/detail), `platformFeeDisputeStore` (list/show/create/review/
resolve/reject).

**Screens (hosted on existing surfaces; no duplicate top-level nav):**
- **Super Administrator (7B):** a new **Platform fees** tab in `platform/BillingSettings.vue`
  (`PlatformFeeConfigSection.vue`, `platform.platform_fee.configure`). List + create/edit-draft/approve/
  supersede/cancel; active/approved terms render read-only (supersede offered, no edit control); client
  validation mirrors the server (integer basis points ≤ 10000; shared split required for the shared tier;
  `validated_paid_amount` only for customer-centric; effective coherence; required change reason). Step-up
  failures surface the repository-standard "complete a fresh step-up" message; no step-up secret is stored.
- **Merchant/Branch/Finance/Audit (7C):** one shared `pages/billing/PlatformFees.vue` mounted per role
  (`merchant.platform-fees`, `branch.platform-fees`, `finance.platform-fees`, `audit.platform-fees`) —
  the backend server-scopes. Merchant-wide summary cards (server `/summary`), fee-entry list/detail
  (ULID-only, canonical tier label, integer amounts), and disputes. Merchant Admin/Finance raise disputes
  (`platform_fee.dispute`); Finance-only start-review/resolve/reject (`platform_fee.dispute.review`) — a
  resolve carries an OPTIONAL signed money change that the backend turns into an additive adjustment
  (no direct ledger edit, no generic adjustment creator). Branch Manager/Audit are strictly read-only.
- **Front Office (7C):** `invoicing/InvoiceDetail.vue` shows a client-facing **Platform fee** line when the
  server returns a positive `platform_fee_client_shifted` (shared / business-centric); customer-centric and
  fixed-only invoices return null → no line. Merchant liability / rate config are never exposed here.

**Backend contract correction (§23):** the merchant-client `InvoiceResource` did not expose the computed
client-shifted platform fee. Smallest fix: added a masked `platform_fee_client_shifted` money field
(present only when > 0); regenerated OpenAPI + `api.ts`; `Invoicing` Feature tests **42 passed**. No
financial logic or migration changed.

**Shared-component fix:** `SvModal` gained `max-h-[90vh] overflow-y-auto` so tall dialogs scroll internally
(§16 — dialogs stay within the viewport at 200% zoom); short modals are unaffected.

**States, gating, terminology:** every affected screen uses `SvStateBoundary` (loading/empty/error) +
capability-gated controls; forbidden data is hidden AND server-denied; friendly errors never leak
SQLSTATE/constraint/stack detail; period-lock / stale-step-up map to readable messages.

**Screen inventory + navigation:** 4 inventory entries added + the billing-settings entry enriched
(`inventory.json`/`.yaml` regenerated; 8/8 guard green); §27.1 spec files generated; a "Platform fees" nav
item added for Super Admin/Merchant/Branch/Finance/Audit (`roleNavigation.ts` + `role-navigation.yaml`
synced).

**Gates:** Vitest **352 passed / 82 files** (18 new store + component specs: config store/section,
ledger+dispute store/view, Front-Office invoice-line cases). Playwright `phase-20e.spec.ts` **14 passed** —
Super-Admin config tab + read-only/supersede + shared-split validation blocks submit + permission-hidden
tab; merchant summary/entries + dispute create; Finance review controls; Branch/Audit read-only; **responsive
360/768/1280 (no page-level horizontal overflow), 200% zoom, keyboard open + focus restore on Escape, and
axe serious/critical = 0 in light AND dark**. Modal/nav-affected e2e (phase-20c + role navigation/entry)
**32 passed** (no regression from the SvModal/nav changes). `vue-tsc` clean; ESLint **0 errors** (138
pre-existing style warnings, untouched files); `vite build` ✓; `api:contract:check` OK (235 ops);
`permission-types --check` clean; backend platform-fee API **30 passed**. **No Wallet/STK/PayBill/provider/
settlement/credit UI introduced.**

## Increment 8 — full local gate sweep + single completion commit (COMPLETE + green)

The whole-phase local acceptance run. Every gate was re-run against the complete tree (not a targeted
Increment-7 subset), on PostgreSQL 16, PHP 8.3.32, Laravel 12.62.0. Executed 2026-07-13.

**8A — repository & runtime boundary.** Branch `phase-20e-percentage-platform-fees`; `HEAD` =
`origin/main` = merge-base = `735f419bf72fdd9be3f95c4507e8925c1ed0859e`; `origin/main...HEAD` divergence
`0  0`; 0 staged; `git diff --check` clean; `git fsck --full` exit 0. Runtime: Docker engine 29.6.1;
compose services healthy (`minio-init` `Exited (0)` expected); PostgreSQL 16 `accepting connections`;
Redis `PONG`; PHP 8.3.32; Laravel 12.62.0; all eight `2026_07_13_000001…000008` migrations **Ran**;
`GET /health` = 200.

**8B backend quality.** `composer validate --strict` → *composer.json is valid*. `vendor/bin/pint --test`
→ **PASS, 1266 files**. `composer stan` (Larastan **level 8**, 971 files) → **No errors** (level not
lowered; no ignores added).

**Isolated PostgreSQL 16 fresh build.** Disposable database `servana_fresh_proof` (created + owned by
`servana`, never the dev DB): `migrate:fresh --seed --force` → all migrations DONE + `PermissionSeeder`
DONE; **96 total migrations**, **8** Phase 20E (`2026_07_13_000001…000008`); all four tables present
(`platform_fee_configurations`, `platform_fee_ledger_entries`, `platform_fee_adjustments`,
`platform_fee_disputes`); database **dropped** after verification (clean-up green). No historical
liabilities fabricated.

**Architecture / schema / permissions / route-security / idempotency / audit contract gates** (single
filtered run): `PermissionMatrix* / PermissionCatalogueCompleteness / PermissionLegacyKeyReconciliation /
PermissionPlannedKeyIsolation / RouteSecurityContract / FinancialRouteIdempotency /
Audit{Mutation,Event,Severity}Coverage / NoDirectProviderIntegration / MigrationManifest /
TenantColumnCoverage / ModelTenancyTrait` → **52 passed / 1121 assertions**. Canonical catalogue:
`PermissionMatrixCatalogueCompletenessTest` asserts and passes **§19.2 = 160** canonical keys;
`PermissionMatrixPlanMetadataParityTest` proves **§19.3 = 160** populated rows; legacy-active ratchet
**= 10** (`PermissionLegacyKeyReconciliationTest`, 12→10 after retiring the plural
`platform_fees.view` / `platform_fees.dispute`). The four canonical singular keys
(`platform.platform_fee.configure`, `platform_fee.view`, `platform_fee.dispute`,
`platform_fee.dispute.review`) are present; the two plural legacy keys are retired.

**Phase 20E targeted backend** (12 files: `Phase20ESchema`, `Phase20EEnumParity`, `PlatformFeeCalculation`,
`PlatformFeeConfigurationResolution`, `PlatformFeeFinalization`, `PlatformFeeBillability`,
`PlatformFeeAggregation`, `PlatformFeeCorrectionAggregation`, `PlatformFeeReversalAdjustment`,
`PlatformFeeDispute`, `PlatformFeeConfigurationApi`, `PlatformFeeMerchantApi`) → **138 passed / 431
assertions**.

**Full backend serial** (`php artisan test`) → **1181 passed / 7 skipped / 0 failed / 7396 assertions**,
578.78s. **Full backend parallel** (`php artisan test --parallel`, 4 processes) → **1181 passed / 7
skipped / 0 failed / 7396 assertions**, 338.53s. The 7 skips are pre-existing environment-gated cases
(not Phase 20E). Affected earlier-phase regressions (Invoicing / Payments / Refunds / Audit / Billing /
Tenancy) are inside the full green suite and inside the contract-gate run above — no earlier-phase failure.

**OpenAPI + generated-contract determinism.** SHA-256 recorded before generation, after run 1, and after
run 2 — **identical across all three** for `docs/api/openapi.json`
(`A83DB0C5…9ABD39FF`), `resources/spa/src/types/generated/api.ts` (`6E289A42…40F9E275`), and
`resources/spa/src/types/generated/permissions.ts` (`C3E55F0E…9B7A9114`); no second-run Git diff.
`permission:types --check` → *up to date*. `api:contract:check` → **OK — 196 paths, 235 operations**;
OpenAPI generation reports **235 production routes**. Generated files never hand-edited.

**Frontend static + unit.** `npm run lint` (ESLint) → **0 errors**, 138 pre-existing style warnings — every
warning is in an untouched pre-Phase-20E file (Sv-input primitives, dashboard stubs, legal/cash-up pages);
**no Phase 20E file carries a warning**. `npm run typecheck` (`vue-tsc --noEmit`) → clean. `npm run test`
(Vitest) → **352 passed / 82 files**. `npm run build` → **built in 10.33s** (the `[PLUGIN_TIMINGS]` line is
an informational Vite note, not a warning).

**Playwright.** Affected (`phase-20e`, `invoice`, `phase-20c`, `role-navigation-keyboard`,
`role-foundation-accessibility`) → **77 passed**. Full suite (`npm run e2e`) → **324 passed / 0 failed**.
The Phase 20E responsive/zoom/keyboard/dark/axe matrix is inside `phase-20e.spec.ts` (14) — 360/768/1280 no
page-level horizontal overflow, 200% zoom, keyboard open + focus restore, **axe serious/critical = 0** in
light AND dark; role-foundation responsive/accessibility specs re-confirm the shell across all six roles.

**Dependency / security / secrets.** `composer audit --locked` → *No security vulnerability advisories
found*. `npm audit --audit-level=high` → **exit 0** (high gate green); it discloses **2 moderate**,
development-only, transitive advisories (`js-yaml` via `@redocly/openapi-core`, an OpenAPI-tooling dev
dependency) — below the configured high gate, disclosed not concealed, no production dependency affected.
`gitleaks detect --no-git --redact` (config `.gitleaks.toml`, vendor/node_modules allowlisted) → **no leaks
found** (15.34 MB scanned, 5.55s).

**Docker builds** (sequential, not concurrent with Playwright). `docker compose build app` →
`servana-app:dev` **Built**. `docker compose -f docker-compose.prod.yml build app` → `servana-app:prod`
**Built**. `docker compose -f docker-compose.prod.yml build nginx` → `servana-nginx:prod` **Built**.

**Scope purity.** 47 modified tracked + 97 untracked, all classified under Phase 20C reconciliation, Phase
20E specification/schema/backend/contracts/frontend/proof, or their earlier-phase integration seams
(`AuditEvent`, `StepUpAction`, `PermissionRegistry`, `InvoiceResource`, `RouteClassification`,
`FinalizeInvoice`, `ExecuteInvoiceVoid`, `IssueSubscriptionInvoice`, `TenantOwnership`, `routes/api.php`).
No path touches Wallet runtime, provider integration, Phase 20F/20G/20H, R&E, notifications/reports, SMS,
search, deployment, IDE metadata, recovery backups, or test/coverage/report artifacts (`public/spa/assets`,
`test-results`, `playwright-report`, `coverage`, `.claude/` are all gitignored/untracked-excluded).
`git diff --check` clean.

**Failures fixed at root cause during Increment 8:** none — every gate passed on first execution. No test
disabled, no assertion loosened, no axe rule suppressed, no constraint removed, no generated file
hand-edited, no analysis level lowered, no audit severity reduced.

**Completion commit:** `phase-20e: implement percentage platform fee engine` — the single implementation
commit on top of `735f419…`. (Hash recorded in `docs/PROGRESS.md` after commit + push.)

## Explicit exclusions (owner phases)

Wallet sync/registration/STK/PayBill/webhooks/apply/reconciliation → **20D-W**; compensation setup →
**20F**; salary/commission ledgers → **20G**; payout runs/earnings → **20H**; R&E capture/qualification →
**21R-A/B**; notifications/reports → **21N**; personnel SMS → **21S**; search → **22**; release hardening →
**23**; performance → **24**; deployment/alerting/runbooks → **25**. No Wallet/provider runtime introduced.

## Solo-Maintainer Review Exception - PR #38

- PR: #38
- verified implementation head: f6e208a90513bf5ca1c219c456b263ea0d111c5c
- initial successful CI run: 29310417943
- CI/Backend: passed
- CI/Frontend: passed
- CI/Docker: passed
- CI/Security: passed
- CI/E2E - Playwright: passed
- GitHub reviewDecision: intentionally blank
- governance record: docs/governance/solo-maintainer-review-exception-pr-38.md

This exception applies only to Phase 20E and is not independent reviewer approval.

Phase 20D-W and all later Wallet, payment, settlement, compensation, payout,
referral, notification, SMS, search, performance and production-readiness domains
remain deferred to their documented owning phases.
