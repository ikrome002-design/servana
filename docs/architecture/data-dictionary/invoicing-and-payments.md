# Invoicing and Merchant-Client Payments — Data Dictionary (Plan §13.8, §13.15, §40, §41–§46, §25.3; Phases 17, 18A, 18B)

> Canonical per-table data dictionary for the Merchant-Client invoicing **and
> payment-recording** substrate. This is the Plan §13.8 canonical path
> `invoicing-and-payments.md`; it consolidates the Phase 17 `invoicing.md`
> (renamed here at Phase 18A start so there is exactly **one** authority — no
> two competing files). **Phase 17** owns `invoice_number_sequences`, `invoices`,
> `invoice_items` (below). **Phase 18A** owns `payment_recording_groups`,
> `payment_records`, `payment_allocations`, `payment_reference_checks` (see the
> "Merchant-Client Payments (Phase 18A)" section appended at the end). Settles columns,
> nullability, composite foreign keys, the invoice↔session relationship,
> duplicate-invoicing prevention, draft mutability vs. finalization immutability,
> the invoice-number format, snapshot semantics, the preferred-fee fallback, the
> percentage-config seam, tax/discount behaviour, void/adjustment representation,
> the period-lock enforcement seam, branch-close/archive behaviour, and the
> retention/deletion prohibition. `DataDictionaryCoverageTest` reads this file.
>
> Money is **integer minor units** via the `Money` value object — never float.
> Timestamps are UTC; business-day logic is `Africa/Nairobi`. ULIDs are the only
> public identifiers; the internal bigint `id` is never exposed.

---

## Controlling sources

- Plan §40 (Invoices, Phase 17), §13.8 (canonical schema summary), §13.15
  (`invoice_number_sequences`, Correction 3), §25.3 (Merchant-Client Invoice
  machine), §19.3 (permission matrix), §80 (roadmap).
- Scope §4.5 (invoice/payment money flow), §4.5.2 (Invoice Adjustment and Void
  Approval Workflow), the **Branch Invoice and Receipt Numbering Rules**
  (merchant-wide uniqueness with an optional branch prefix, e.g. `KIL-INV-000124`;
  voided invoices keep their number), §6 role boundaries (Front Office creates;
  Finance voids/adjusts).
- Phase 16C handoff: the merged `service_sessions` migration created
  `UNIQUE (id, merchant_id)` **expressly** as the composite-FK target for
  `invoice_items.service_session_id`; completion yields a NON-PAYABLE commission
  preview only (no fee, no ledger).

---

## Specification-gate resolutions (controlling decisions)

### Gate A — completed service-session source → RESOLVED (composite FK; multi-session invoice)

- **Source invariant:** *only a `completed` `service_sessions` row may become a
  service invoice item.* `pending`, `in_progress`, and `cancelled` sessions are
  rejected.
- **Relationship:** `invoice_items.service_session_id` is **NOT NULL** with a
  composite FK `(service_session_id, merchant_id) → service_sessions(id,
  merchant_id)` — the target the Phase 16C migration prepared. Provenance
  (`client_id`, `service_id`, `staff_profile_id`, `branch_id`, `merchant_id`,
  `preferred_personnel_honored`) is **derived from the locked completed session**
  at draft/finalization, never accepted from the browser.
- **Cardinality:** one invoice **may contain multiple** completed service sessions
  (one `invoice_items` row each), provided every source shares the same merchant,
  branch, client, and currency. Decisive evidence: `invoice_items` is a multi-row
  child of `invoices`; the Phase 17 frontend spec selects *one or more* eligible
  completed sessions; a client receiving several services in one visit gets one
  invoice. A single-session invoice is the degenerate one-item case.
- **Duplicate-invoicing prevention:** `UNIQUE (service_session_id)` on
  `invoice_items` — a completed session is committed to **at most one** invoice.
  Voiding does **not** delete the item, so a voided session is **not** re-invoiceable
  in Phase 17; re-invoicing a voided/superseded session is a *documented correction
  workflow* deferred (no destructive rewrite is ever performed). The action also
  performs a `FOR UPDATE` existence check before insert to fail friendly
  (`409 service_session_already_invoiced`) rather than leak a constraint violation.

### Gate B — void and adjustment representation → RESOLVED (additive columns; no new table)

Phase 17 owns only `invoices`/`invoice_items`/`invoice_number_sequences`. Voids and
adjustments are represented with **Phase-17-owned columns on `invoices`**, never a
new financial table and never a destructive edit:

- `previous_status` — the payable state captured when entering `void_pending`, so a
  rejection restores `issued` or `partially_paid` exactly.
- `voided_at`, `voided_by`, `void_reason` — set only at `voided`.
- `adjusted_at`, `adjusted_by`, `adjustment_reason` — set only at `adjusted`.
- `adjustment_of_invoice_id` (nullable self-FK, composite to `(id, merchant_id)`) —
  the additive, traceable link from a correcting invoice to its original; the seam
  Phase 18 payment/refund allocations reference.

Guarantees: original finalized monetary snapshots (`subtotal/discount/tax/total/
preferred_personnel_fee_snapshot/percentage_fee_config_snapshot/invoice_number`)
are **never mutated** by void or adjust; no financial row is deleted; the original
number is retained (never reused); every void/adjust records actor, reason, time,
and before/after amounts in its audit event.

**Reachability note.** In Phase 17 an invoice cannot become `paid`/`partially_paid`
(there is no payment path until Phase 18A/18B; `validated_paid_minor` starts at 0
and is never writable through a Phase 17 route). Therefore the reachable Phase 17
mutations are `draft → issued`, `issued → void_pending → voided` (or rejection back
to `issued`), and `issued → adjusted`. The `paid → refund_pending` and
`paid → adjustment_required` transitions are **defined and unit-tested** in the
state machine but are **Phase-18B-driven** (post-validated-payment); Phase 18B owns
the additive reversal/refund *financial entries*. This is the Scope's "adjust
invoice after payment" workflow, which is post-payment by definition.

### Gate C — period-lock enforcement seam → RESOLVED (guard contract; persistence is Phase 18B)

`financial_period_locks` and the lock-management workflow are Phase 18B. Phase 17
implements the **invoice-side enforcement contract** through a
`FinancialPeriodGuard` backed by a `PeriodLockRepository` contract. The Phase 17
default binding (`UnlockedPeriodLockRepository`) reports *no* locks (the table does
not exist yet), but the guard is wired into **every** Phase 17 financial mutation
(finalize/void/adjust). Tests substitute a repository that reports a locked period
and assert the canonical `423 financial_period_locked` denial. Phase 18B swaps in
the DB-backed repository with **no change** to the invoice actions. No lock
table, endpoint, or UI is created in Phase 17.

### Gate D — preferred-personnel fee → RESOLVED (resolver; legacy fallback)

No `preferred_personnel_fee_rules` table in Phase 17 (Phase 20A). A single
`PreferredPersonnelFeeResolver` contract is used at finalization, per source
session: (1) if `service_sessions.preferred_personnel_honored` is `null`/`false`,
**no** fee; (2) if `true`, resolve the fixed amount from the **legacy**
`services.preferred_personnel_fee_minor` (the rules table is absent); (3) snapshot
the resolved per-item amount into `invoice_items.preferred_personnel_fee_minor` and
the sum into `invoices.preferred_personnel_fee_snapshot_minor`; (4) the snapshot is
immutable — later changes to `services.preferred_personnel_fee_minor` never
recalculate an issued invoice. The resolver is replaceable by Phase 20A without
changing finalization semantics. Future percentage rules use integer arithmetic and
round-half-up (ADR-005); none is implemented here. The fee is **never** derived from
the non-payable commission preview.

### Gate E — percentage-platform-fee snapshot → RESOLVED (null until Phase 20E)

`percentage_fee_config_snapshot` (jsonb, nullable) is the only percentage-fee seam
Phase 17 owns. Until `platform_fee_configurations` exists (Phase 20E) the snapshot
is **`null`** (the typed "not configured" representation). Phase 17 fabricates no
billing mode, rate, tier, or fee; the absence of configuration is **not** treated as
zero percent; no platform-fee ledger row is created.

### Gate F — taxes and discounts → RESOLVED (schema-present, default zero, deferred)

`discount_minor` and `tax_minor` exist in the canonical schema. No authoritative
Phase 17 tax-rate or discount-eligibility rule exists in the Plan or Scope, so the
fields are retained, integer minor units, **default 0**, with non-negative CHECKs
and **no unauthorized editable control**. Automatic tax calculation and
promotion-driven discounts are deferred (promotions are Phase 20C); manual
Finance-authorized adjustment is the `adjusted` path. Documented deferral.

---

## Table: `invoice_number_sequences` (Phase 17, Plan §13.15 Correction 3)

Gap-free, per-merchant, row-locked counter consumed **only** inside a successful
finalization transaction. Tenant-owned (TENANT_OWNED — `merchant_id`, no `branch_id`;
numbering is merchant-wide).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK | no | — | internal; never exposed |
| `merchant_id` | bigint FK→merchants RESTRICT | no | — | owner |
| `scope` | varchar(40) | no | — | CHECK `IN ('merchant_client_invoice')` |
| `next_value` | bigint | no | 1 | CHECK `> 0`; the next number to allocate |
| `prefix` | varchar(20) | yes | null | optional merchant-level override; `null` ⇒ literal `INV` |
| `created_at`/`updated_at` | timestamptz | no | — | |

Constraints/indexes: `UNIQUE (merchant_id, scope)`; `scope` CHECK; `next_value > 0`.

Rules: allocation is `SELECT … FOR UPDATE` on the sequence row inside the
finalization transaction, returns `next_value`, then increments — **never**
`MAX(invoice_number)+1`. A rolled-back finalization consumes **no** number (the
increment rolls back with the transaction). Numbers are never reused, including
after a void.

## Table: `invoices` (Phase 17, Plan §13.8 / §40)

Branch-owned (BRANCH_OWNED). ULID public id + route key.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK | no | — | internal |
| `ulid` | char(26) | no | — | UNIQUE; public id + route key |
| `merchant_id` | bigint FK→merchants CASCADE | no | — | tenant |
| `branch_id` | bigint FK→merchant_branches CASCADE | no | — | branch |
| `client_id` | bigint FK→clients RESTRICT | no | — | composite, same merchant |
| `invoice_number` | varchar(40) | yes | null | allocated at finalization; merchant-unique |
| `status` | varchar(24) | no | `draft` | CHECK in the 9 canonical states |
| `previous_status` | varchar(24) | yes | null | payable state preserved for `void_pending` rejection |
| `subtotal_minor` | bigint | no | 0 | Σ item line totals; CHECK `>= 0` |
| `discount_minor` | bigint | no | 0 | CHECK `>= 0` (Gate F, default 0) |
| `tax_minor` | bigint | no | 0 | CHECK `>= 0` (Gate F, default 0) |
| `preferred_personnel_fee_snapshot_minor` | bigint | yes | null | Σ item preferred fees (Gate D); CHECK null or `>= 0` |
| `total_minor` | bigint | no | 0 | CHECK `>= 0` and arithmetic coherence (below) |
| `validated_paid_minor` | bigint | no | 0 | Phase-18B-written; CHECK `>= 0` and `<= total_minor` |
| `currency` | char(3) | no | `KES` | CHECK uppercase ISO, length 3 |
| `percentage_fee_config_snapshot` | jsonb | yes | null | Gate E; null = not configured |
| `finalized_at` | timestamptz | yes | null | set at `draft → issued` |
| `voided_at` | timestamptz | yes | null | set at `voided` |
| `voided_by` | bigint FK→users SET NULL | yes | null | actor at `voided` |
| `void_reason` | text | yes | null | mandatory at `voided`/`void_pending` |
| `adjusted_at` | timestamptz | yes | null | set at `adjusted` |
| `adjusted_by` | bigint FK→users SET NULL | yes | null | actor at `adjusted` |
| `adjustment_reason` | text | yes | null | mandatory at `adjusted` |
| `adjustment_of_invoice_id` | bigint | yes | null | composite self-FK; correcting-invoice link |
| `created_by` | bigint FK→users SET NULL | yes | null | drafting Front Office user |
| `created_at`/`updated_at` | timestamptz | no | — | |

**CHECK constraints**

- `status` ∈ `draft, issued, partially_paid, paid, void_pending, voided, adjusted,
  refund_pending, adjustment_required`.
- `currency = upper(currency) AND char_length(currency) = 3`.
- non-negative: `subtotal_minor, discount_minor, tax_minor, total_minor,
  validated_paid_minor >= 0`; `preferred_personnel_fee_snapshot_minor IS NULL OR
  >= 0`.
- `validated_paid_minor <= total_minor`.
- **draft coherence:** `status <> 'draft' OR (invoice_number IS NULL AND finalized_at
  IS NULL)`.
- **finalized coherence:** `status = 'draft' OR (invoice_number IS NOT NULL AND
  finalized_at IS NOT NULL)`.
- **total arithmetic:** `total_minor = subtotal_minor +
  COALESCE(preferred_personnel_fee_snapshot_minor, 0) + tax_minor - discount_minor`.
- **void coherence:** `status <> 'voided' OR (voided_at IS NOT NULL AND voided_by IS
  NOT NULL AND void_reason IS NOT NULL)`.
- **void_pending coherence:** `previous_status IS NULL OR status = 'void_pending'`;
  `status <> 'void_pending' OR (previous_status IN ('issued','partially_paid') AND
  void_reason IS NOT NULL)`.
- **adjust coherence:** `status <> 'adjusted' OR (adjusted_at IS NOT NULL AND
  adjusted_by IS NOT NULL AND adjustment_reason IS NOT NULL)`.

**FKs / uniqueness / indexes**

- composite `(branch_id, merchant_id) → merchant_branches(id, merchant_id)` CASCADE.
- composite `(client_id, merchant_id) → clients(id, merchant_id)` RESTRICT.
- composite `(adjustment_of_invoice_id, merchant_id) → invoices(id, merchant_id)`
  RESTRICT (same-merchant correcting link).
- `UNIQUE (ulid)`; partial `UNIQUE (merchant_id, invoice_number) WHERE invoice_number
  IS NOT NULL` (merchant-wide number uniqueness; many NULL-number drafts coexist);
  `UNIQUE (id, merchant_id)` (composite-FK target for `invoice_items`).
- indexes `(merchant_id, branch_id)`, `(branch_id, status)`,
  `(merchant_id, invoice_number)`, `(client_id)`, `(adjustment_of_invoice_id)`.

No hard-delete API. Route binding resolves `ulid` inside tenant scope → foreign IDs
404. Retention: invoices, items, and numbers are preserved permanently (§14); void
is additive, never destructive.

## Table: `invoice_items` (Phase 17, Plan §13.8)

Branch-owned. Immutable once its invoice is finalized.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK | no | — | internal |
| `ulid` | char(26) | no | — | UNIQUE; public id |
| `merchant_id` | bigint FK→merchants CASCADE | no | — | tenant |
| `branch_id` | bigint FK→merchant_branches CASCADE | no | — | branch |
| `invoice_id` | bigint FK→invoices RESTRICT | no | — | composite parent |
| `service_session_id` | bigint FK→service_sessions RESTRICT | no | — | composite; Gate A source (completed) |
| `service_id` | bigint FK→services RESTRICT | no | — | composite; snapshotted from the session |
| `staff_profile_id` | bigint FK→staff_profiles RESTRICT | yes | null | composite; performing personnel |
| `description` | varchar(255) | no | — | service-name snapshot |
| `quantity` | int | no | 1 | CHECK `> 0` (1 per session delivery) |
| `unit_price_minor` | bigint | no | — | CHECK `>= 0`; service price snapshot |
| `line_total_minor` | bigint | no | — | CHECK `>= 0` and `= unit_price_minor * quantity` |
| `preferred_personnel_fee_minor` | bigint | yes | null | Gate D snapshot; CHECK null or `>= 0` |
| `eligible_for_commission` | boolean | no | false | commission-evidence snapshot (no ledger in 17) |
| `currency` | char(3) | no | `KES` | CHECK uppercase ISO, length 3 |
| `created_at`/`updated_at` | timestamptz | no | — | |

**Constraints / indexes**

- `quantity > 0`; `unit_price_minor >= 0`; `line_total_minor >= 0 AND line_total_minor
  = unit_price_minor * quantity`; `currency` uppercase/len-3; preferred fee null or
  `>= 0`.
- composite FKs: `(invoice_id, merchant_id) → invoices` RESTRICT;
  `(service_session_id, merchant_id) → service_sessions` RESTRICT;
  `(service_id, merchant_id) → services` RESTRICT;
  `(staff_profile_id, merchant_id) → staff_profiles` RESTRICT;
  `(branch_id, merchant_id) → merchant_branches` CASCADE.
- `UNIQUE (ulid)`; `UNIQUE (service_session_id)` (duplicate-invoicing prevention,
  Gate A).
- indexes `(merchant_id, branch_id)`, `(invoice_id)`, `(service_id)`,
  `(staff_profile_id)`.

Branch/client/session consistency is enforced both by the composite FKs and by the
finalization action (every item's session belongs to the same branch and client as
the invoice header, derived under lock).

---

## Invoice number format

Merchant-wide unique with an **optional branch prefix** for readability (Scope —
Branch Invoice and Receipt Numbering Rules). Canonical Phase 17 format:

```text
{branch.code}-INV-{next_value zero-padded to 6}      e.g.  KIL-INV-000124
```

The per-merchant `invoice_number_sequences` counter guarantees merchant-wide
uniqueness regardless of branch prefix (the numeric component is unique per
merchant). The branch prefix is derived from `merchant_branches.code` at format time;
`invoice_number_sequences.prefix` (default `null` ⇒ `INV`) is an optional
merchant-level override of the `INV` segment. Allocation happens **only** inside the
finalization transaction under the sequence row lock. A voided number is retained and
never reused. Sequential database IDs remain private; the formatted number is the
only public reference besides the ULID.

## Totals (deterministic, integer minor units)

```text
line_total            = unit_price_minor * quantity            (per item; quantity = 1 for a session)
subtotal_minor        = Σ line_total_minor                      (service items)
preferred_fee_total   = Σ invoice_items.preferred_personnel_fee_minor   (→ preferred_personnel_fee_snapshot_minor)
total_minor           = subtotal_minor + preferred_fee_total + tax_minor - discount_minor
balance (display)     = total_minor - validated_paid_minor
```

All arithmetic uses the `Money` value object (integer-safe, currency-checked). The
DB total-arithmetic CHECK enforces the same identity. Finalization derives every
monetary value from locked authoritative service/session/fee data — never from
browser-supplied totals.

## Branch-close / archive integration

Inspect `BranchClosureGuard` before changing it. The Scope (§"A branch must not be
closed or archived while live operational records exist") names *unpaid invoices*
among blockers, but "unpaid" is a **payment** concept that only becomes meaningful in
Phase 18A/18B (there is no validated-payment path in Phase 17, so every finalized
invoice is trivially unpaid). Phase 17 therefore documents the mapping and **does not
flip on** an invoice day-close/archival blocker that would block every branch close
the moment invoicing ships; the unpaid-invoice blocker is wired with payment state in
Phase 18B. `draft` invoices are informational; `voided`/`adjusted` are terminal and
never block. (Recorded decision; revisit at Phase 18B when validated-payment state
exists.)

## Models, factories, registration

- Models: `Invoice`, `InvoiceItem`, `InvoiceNumberSequence` in
  `app/Domain/Invoicing/Models`. `Invoice`/`InvoiceItem` use `BelongsToMerchant` +
  `BelongsToBranch`; `InvoiceNumberSequence` uses `BelongsToMerchant`. ULID auto-set
  on create; `getRouteKeyName()` = `ulid`.
- Register in `app/Domain/Tenancy/TenantOwnership.php`: `invoices` + `invoice_items`
  → BRANCH_OWNED + COMPOSITE_CONSISTENCY (`branch_id` → merchant_branches) + MODELS
  (`branch`); `invoice_number_sequences` → TENANT_OWNED + MODELS (`tenant`).
- Factories: `InvoiceFactory`, `InvoiceItemFactory`, `InvoiceNumberSequenceFactory`.
- Migrations registered in `docs/architecture/migrations/manifest.yaml`.

---

# Merchant-Client Payments (Phase 18A) — Correction 18

> Canonical per-table data dictionary for the Phase 18A **payment-recording**
> substrate: `payment_recording_groups`, `payment_records`, `payment_allocations`,
> `payment_reference_checks`. Controlling sources: Plan §13.8 + §13.15 (canonical
> DDL), §41 (Merchant-Client Payments — the controlling workflow spec), §19.3
> (permission matrix), §25 (payment-recording-group machine), §24.4 (idempotency),
> §46 (period locks — reused, not created here), §80 (roadmap). Scope §4.5 (money
> flow), PART B role ownership (Front Office is the default maker; Finance is the
> checker in Phase 18B). `DataDictionaryCoverageTest` and `MigrationManifestTest`
> read this file.
>
> Money is **integer minor units** via `Money` — never float. Timestamps are UTC;
> business-day logic is `Africa/Nairobi`. ULIDs are the only public identifiers;
> the internal bigint `id` is never exposed. Full/normalized payment references and
> raw client contact are **never** returned by a Resource, written to an audit log,
> or written to an application log.

## Phase 18A boundary (what these tables do NOT do)

Recording is **maker-only evidence capture**. A successful recording produces a
durable group + components + allocations + reference checks in the Phase-18A
pending state and notifies Finance. It **must not**: increase
`invoices.validated_paid_minor`; move an invoice to `partially_paid`/`paid`;
create `payment_validation_events`; create a receipt or allocate a receipt number;
earn commission; create a refund/dispute/cash-up; or create/reopen a
`financial_period_locks` row. Those are Phase 18B/19/20+.

## Specification-gate resolutions (controlling decisions)

### Gate A — recordable invoice source: RESOLVED (issued / partially_paid only)

A payment group may be recorded only against an invoice whose status is `issued`
or `partially_paid` (the Phase 17 `InvoiceStatus::payableStatuses()`). `draft`,
`paid`, `void_pending`, `voided`, `adjusted`, `refund_pending`,
`adjustment_required` are rejected (`422 invoice_not_recordable`). The invoice
`merchant_id`/`branch_id`/`client_id`/`currency`/`total_minor`/
`validated_paid_minor` are the authoritative source; the browser supplies none of
them. `validated_balance = invoice.total_minor - invoice.validated_paid_minor`
(the Phase 17 `Invoice::balanceMinor()`). Recording reuses the Phase 17
`FinancialPeriodGuard` (to `423 financial_period_locked`) and the billing-mutation
gate; it does not read from any 18B table.

### Gate B — split_payment representation: RESOLVED (group-level; concrete component methods)

Per §41: a single-method payment is a group of one; a `split_payment`/multi-method
payment is one group with multiple component `payment_records`. The **group**
represents the split; it has **no** `method` column. Each component
`payment_records.method` carries a **concrete** method (`cash`, `mpesa_offline`,
`bank_transfer`, `card_terminal`, `voucher`, `other`). `split_payment` is retained
in the `payment_records.method` CHECK for canonical schema fidelity but is
**never written as a component method in Phase 18A** — writing one would create the
forbidden synthetic component that duplicates amounts. `group.total_amount_minor`
equals the sum of component amounts and single-currency is enforced. The
`PaymentMethodReferenceValidator`/`PaymentRecordingComposer` reject a component
whose method is `split_payment`.

### Gate C — durable duplicate + uniqueness: RESOLVED (partial unique index WHERE result='unique')

`payment_reference_checks` carries a **partial unique index**
`UNIQUE (merchant_id, method, reference_normalized) WHERE result = 'unique' AND
reference_normalized IS NOT NULL`. The first accepted reference for a
`(merchant, method)` occupies the single `unique` slot. A later component with the
same `reference_normalized` cannot insert a second `unique` row (the DB rejects it);
the `PaymentReferenceDuplicateChecker` instead writes a `duplicate_suspected` row
(outside the index predicate, so it persists) whose `matched_payment_record_id`
points at the prior record. A Finance override writes an `override_approved` row
(also outside the predicate). Result: **every** attempt is durable and auditable,
silent unapproved reuse is impossible, and concurrency is deterministic (two
concurrent first-inserts race for the `unique` slot; the loser catches the unique
violation and is recorded `duplicate_suspected`). The original reference is never
edited. `payment_records` keeps only a **non-unique** index `(merchant_id, method,
reference_normalized)` — the record table must allow the duplicate row to persist.

### Gate D — Finance notification seam: RESOLVED (masked mail Notification; no Phase 21N table)

A Laravel `Notification` (mail channel; Mailpit in dev) is sent to eligible Finance
users of the invoice merchant/branch (resolved via the permission registry) when a
group becomes `pending_validation` or when a `duplicate_suspected` review is raised.
Payload carries only the group ULID, invoice number, branch/merchant public
identifiers, component method labels, integer amounts + currency, and a **masked**
reference suffix — never a full/normalized reference or unmasked client contact.
Delivery is idempotent per `(group ULID, event)`. **No Phase 21N `notifications`
table is created**; the durable in-app notifications platform remains Phase 21N.

### Gate E — period-lock / billing sequencing: RESOLVED (reuse Phase 17 guards)

Recording reuses the Phase 17 `FinancialPeriodGuard` + `PeriodLockRepository`
(the always-open `UnlockedPeriodLockRepository` binding) and the billing-mutation
gate (`financial_mutation` route class). A repository-reported lock returns `423`.
No `financial_period_locks` table, route, or UI is created in Phase 18A (Phase 18B).

### Gate F — cash / day-close evidence: RESOLVED (no cash-up table)

`cash` records with an **optional** reference (denomination/drawer note) and **no**
duplicate-reference check. Branch/day cash-up persistence and approval remain Phase
18B; Phase 18A creates no `branch_cash_ups`/`cash_up_lines` rows. Active
(non-terminal, not-yet-validated) recording groups block branch archival and
branch-day close via the existing `BranchClosureGuard` seam so a pending recording
is never stranded (proven cross-branch/cross-tenant isolated).

---

## `payment_recording_groups` (18A, branch-owned) — durable split/multi-payment group

The unit of Finance validation (Phase 18B). One group = one recording workflow;
one or more component `payment_records` reference it.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint identity | no | — | internal PK; never exposed |
| `ulid` | char(26) | no | auto | public id + route key (`getRouteKeyName()=ulid`) |
| `merchant_id` | bigint | no | — | FK RESTRICT to merchants; tenant scope (`BelongsToMerchant`) |
| `branch_id` | bigint | no | — | FK RESTRICT to merchant_branches; branch scope (`BelongsToBranch`) |
| `invoice_id` | bigint | no | — | FK RESTRICT to invoices; the single invoice this group pays |
| `maker_user_id` | bigint | no | — | FK RESTRICT to users; server-derived recording actor (Front Office, or Finance under exception) |
| `total_amount_minor` | bigint | no | — | equals the sum of component amounts; CHECK `> 0` |
| `currency` | char(3) | no | — | uppercase ISO; CHECK; equals invoice.currency and every component currency |
| `idempotency_key_id` | bigint | yes | null | FK RESTRICT to idempotency_keys; links the group to the R4 replay record |
| `status` | varchar | no | `recorded` | CHECK in (`draft`,`recorded`,`pending_validation`,`validated`,`rejected`,`correction_required`,`reversed`) |
| `recorded_at` | timestamptz | yes | null | set when the group + components commit |
| `submitted_for_validation_at` | timestamptz | yes | null | set on `recorded -> pending_validation` |
| `validated_at` | timestamptz | yes | null | **Phase 18B** (validation) — null in 18A |
| `rejected_at` | timestamptz | yes | null | **Phase 18B** — null in 18A |
| `created_at`/`updated_at` | timestamptz | no | — | |

**Statuses (Phase 18A reachability).** `draft` — reserved (not written in 18A).
`recorded` — created here; the group and its components have committed but the group
is **not yet** in Finance validation queue (either transiently, or **held** because
a component reference is `duplicate_suspected`). `pending_validation` — the Phase-18A
success terminal: all references cleared, group submitted for Finance validation.
`validated`/`rejected`/`correction_required`/`reversed` — defined in the CHECK and in
the state machine but **owned by Phase 18B**; Phase 18A has no action or route that
reaches them and rejects such a transition (`422 invalid_state_transition`).

**Transitions owned by 18A** (see `docs/architecture/state-machines/payment-recording-group.md`):
`(create) -> recorded`; `recorded -> pending_validation` (happy path, in the same
transaction when no duplicate is suspected; or via `ApproveDuplicatePaymentReference`
after a Finance override).

**Constraints / indexes.** `UNIQUE (ulid)`; `UNIQUE (id, merchant_id)` (composite-FK
target for children); composite FK `(branch_id, merchant_id) -> merchant_branches`
CASCADE and `(invoice_id, merchant_id) -> invoices(id, merchant_id)` RESTRICT (branch
+ invoice tenant consistency); `total_amount_minor > 0`; currency uppercase-ISO
CHECK; status/timestamp coherence CHECK (`recorded_at` set for any non-draft;
`submitted_for_validation_at` iff status in {pending_validation,validated,rejected,
correction_required,reversed}; `validated_at`/`rejected_at` null in 18A and only set
by 18B). Indexes `(merchant_id, branch_id)`, `(branch_id, status)`,
`(invoice_id, status)`. No hard-delete API.

## `payment_records` (18A, branch-owned) — component payment

A concrete-method component of a group. A single-method payment is a group with one
component.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint identity | no | — | internal PK |
| `ulid` | char(26) | no | auto | public id + route key |
| `merchant_id` | bigint | no | — | FK RESTRICT; equals group.merchant_id |
| `branch_id` | bigint | no | — | FK RESTRICT; equals group.branch_id |
| `invoice_id` | bigint | no | — | FK RESTRICT; equals group.invoice_id |
| `payment_recording_group_id` | bigint | no | — | FK RESTRICT to payment_recording_groups; every component belongs to exactly one group |
| `recorded_by` | bigint | no | — | FK RESTRICT to users; equals group.maker_user_id (server-derived) |
| `payer_client_id` | bigint | yes | null | FK RESTRICT to clients; when supplied must equal invoice.client_id |
| `method` | varchar | no | — | CHECK in (`cash`,`mpesa_offline`,`bank_transfer`,`card_terminal`,`voucher`,`split_payment`,`other`); Gate B: `split_payment` never written as a component method in 18A |
| `amount_minor` | bigint | no | — | CHECK `> 0` |
| `currency` | char(3) | no | — | uppercase ISO; equals group.currency and invoice.currency |
| `reference_normalized` | varchar | yes | null | normalized comparison key (uppercased/trimmed per method); `$hidden` — never in a Resource/audit/log; required-or-null per method rules |
| `reference_display_encrypted` | text | yes | null | Laravel `encrypted` cast of the original entered reference; decrypted only to compute a masked suffix; never returned raw |
| `paid_at` | timestamptz | no | — | when the merchant received the payment (maker-entered; at or before now) |
| `status` | varchar | no | `pending_validation` | CHECK in (`pending_validation`,`validated`,`rejected`,`correction_required`,`reversed`,`adjusted`) |
| `maker_user_id` | bigint | no | — | FK RESTRICT to users; equals recorded_by (kept for maker/checker parity with the matrix) |
| `validated_amount_minor` | bigint | yes | null | **Phase 18B** — null in 18A; CHECK null-or-`>= 0` |
| `created_at`/`updated_at` | timestamptz | no | — | |

**Statuses.** Phase 18A always creates components at `pending_validation` (the
duplicate hold is expressed at the **group** level, not by a component status).
`validated`/`rejected`/`correction_required`/`reversed`/`adjusted` are Phase 18B.

**Method-reference coherence (DB CHECK + `PaymentMethodReferenceValidator`).**
`cash` — reference optional, **no** duplicate check. `mpesa_offline` — reference
**required**, normalized uppercase, format-validated, duplicate-detected.
`bank_transfer` — bank/deposit reference **required**, duplicate-detected.
`card_terminal` — terminal/auth reference **required**, duplicate-detected.
`voucher` — voucher code/evidence **required** (no voucher module invented),
duplicate-detected. `other` — merchant-defined label + evidence **required**,
duplicate-detected. `split_payment` — rejected as a component method. A DB CHECK
enforces that methods requiring a reference have a non-null `reference_normalized`.

**Constraints / indexes.** `UNIQUE (ulid)`; `UNIQUE (id, merchant_id)`; composite FKs
`(branch_id, merchant_id) -> merchant_branches` CASCADE, `(invoice_id, merchant_id)
-> invoices` RESTRICT, `(payment_recording_group_id, merchant_id) ->
payment_recording_groups(id, merchant_id)` RESTRICT, `(payer_client_id, merchant_id)
-> clients` RESTRICT; `amount_minor > 0`; currency uppercase-ISO; method/reference
coherence CHECK; `validated_amount_minor` null-or-`>= 0`. Indexes `(invoice_id,
status)`, **non-unique** `(merchant_id, method, reference_normalized)`,
`(payment_recording_group_id)`. No hard-delete API.

## `payment_allocations` (18A, branch-owned) — component to invoice allocation

Phase 18A allocates each component to the group invoice at the **invoice level**
(no item-level UI); the nullable `invoice_item_id` preserves the Phase-18B
item-allocation seam.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint identity | no | — | internal PK (no ulid — child evidence row, per §13.8) |
| `merchant_id` | bigint | no | — | FK RESTRICT; equals component.merchant_id |
| `branch_id` | bigint | no | — | FK RESTRICT; equals component.branch_id |
| `payment_record_id` | bigint | no | — | FK RESTRICT to payment_records |
| `invoice_id` | bigint | no | — | FK RESTRICT to invoices; equals component.invoice_id (group invoice only) |
| `invoice_item_id` | bigint | yes | null | FK RESTRICT to invoice_items; Phase-18B item-level seam (null in 18A) |
| `amount_minor` | bigint | no | — | CHECK `> 0` |
| `created_at`/`updated_at` | timestamptz | no | — | (repository convention) |

**Invariants.** `amount_minor > 0`; same `merchant_id`/`branch_id`/`invoice_id` as
the parent component (composite FKs); the sum of a component allocations equals
`component.amount_minor` (enforced in the transactional composer under lock; in 18A
exactly one invoice-level allocation per component); the sum of group allocations
equals `group.total_amount_minor`; group allocations target **only** the group
invoice (no browser-controlled cross-invoice allocation); never mutates
`invoices.validated_paid_minor`. Indexes `(payment_record_id)`, `(invoice_id)`,
`(merchant_id, branch_id)`. Rollback leaves no orphan allocation.

## `payment_reference_checks` (18A, branch-owned) — durable duplicate-reference record

Makes duplicate detection (§41) durable and auditable.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint identity | no | — | internal PK |
| `ulid` | char(26) | no | auto | public id + route key (override route binds `{paymentReferenceCheck}`) |
| `merchant_id` | bigint | no | — | FK RESTRICT; equals record.merchant_id |
| `branch_id` | bigint | no | — | FK RESTRICT; equals record.branch_id |
| `payment_record_id` | bigint | no | — | FK RESTRICT to payment_records |
| `method` | varchar | no | — | equals record.method; duplicate-checked methods only produce a check row |
| `reference_normalized` | varchar | no | — | `$hidden`; the normalized key compared for duplication |
| `result` | varchar | no | — | CHECK in (`unique`,`duplicate_suspected`,`override_approved`) |
| `matched_payment_record_id` | bigint | yes | null | FK RESTRICT to payment_records; **required** for `duplicate_suspected`/`override_approved`; null for `unique` |
| `checked_at` | timestamptz | no | — | when the check ran |
| `override_by` | bigint | yes | null | FK RESTRICT to users; **required** for `override_approved` (Finance actor) |
| `override_reason` | varchar | yes | null | **required** (non-empty, sanitized, length-capped) for `override_approved`; never carries a reference |
| `created_at`/`updated_at` | timestamptz | no | — | (repository convention) |

**Coherence (DB CHECK + `PaymentReferenceDuplicateChecker`).** `cash` produces **no**
check row (no duplicate check). A reference-requiring method cannot bypass a check.
`matched_payment_record_id` required iff `result` in {duplicate_suspected,
override_approved}. `override_by` + `override_reason` required iff `result` =
override_approved. **Partial unique index** `UNIQUE (merchant_id, method,
reference_normalized) WHERE result = 'unique' AND reference_normalized IS NOT NULL`
(Gate C). `reference_normalized` is `$hidden`; no full/normalized reference ever
reaches an API/audit/log. Indexes `(payment_record_id)`, `(matched_payment_record_id)`.

## Duplicate-reference workflow (durable, concurrency-safe)

1. For each reference-requiring component the composer calls
   `PaymentReferenceDuplicateChecker` under the invoice row lock. It first looks for
   an existing `payment_reference_checks` row (or `payment_records`) with the same
   `(merchant_id, method, reference_normalized)`.
2. **No prior match** — attempt to insert a `result='unique'` check row. If the
   partial unique index rejects it (a concurrent first-insert won the slot), fall
   through to the duplicate branch.
3. **Prior match** — insert a `result='duplicate_suspected'` row with
   `matched_payment_record_id` = the prior record. The group is left in `recorded`
   (held); the API returns `409 payment_reference_duplicate_suspected` carrying the
   group ULID, the method, and the **masked** matched-reference suffix. No invoice
   balance changes; the components + allocations + checks are already durable.
4. **Finance override** (`ApproveDuplicatePaymentReference`, permission
   `customer_payment.duplicate_override`, MFA + fresh step-up, non-empty sanitized
   reason): inserts a `result='override_approved'` row (with `override_by` +
   `override_reason`), preserves the original reference, transitions the group
   `recorded -> pending_validation`, sets `submitted_for_validation_at`, emits a
   high-severity audit event, and notifies. Idempotent; the maker may not later act
   as the group checker.

The general **reference-correction** workflow (`customer_payment.reference_correct`)
is **Phase 18B** and is not implemented here.

## Idempotency, period lock, billing (reused seams)

Group recording and duplicate override are `financial_mutation` routes and MUST
carry the Phase R4 `EnsureIdempotentRequest` middleware; the group persists
`idempotency_key_id`. Replay of the same key + payload returns the stored response
(same group ULID; no second component/allocation/check/audit); a changed payload
under the same key returns `409`. The raw key is never stored or logged (R4
discipline). Period openness is enforced by `FinancialPeriodGuard` (`423`);
billing-mutation status by the established gate. No new idempotency/period/billing
infrastructure is created.

## Audit events (typed, safe context only)

`customer_payment.recorded` (info), `customer_payment.duplicate_suspected` (warn/high),
`customer_payment.duplicate_override_approved` (high), `customer_payment.recorded_exception`
(high). Context may include: group ULID; invoice ULID + number; branch/merchant
public identifiers; client ULID; component methods; integer amounts + currency;
masked reference suffix; balance-before; pending-total-before; available-after;
actor; sanitized override reason; state change. **Never**: full/normalized reference;
encrypted display value; full client contact; raw request body; raw idempotency key;
tokens/headers; sequential IDs. A rolled-back action emits **no** success event.

## Retention and deletion

Append-mostly financial evidence. No hard-delete endpoint exists for any of the four
tables. Corrections are additive (Phase 18B validation/rejection/reversal/adjustment
states); the original reference and record are never destroyed or silently edited.

## Models, factories, registration (payments)

- Models in `app/Domain/Payments/Models`: `PaymentRecordingGroup`, `PaymentRecord`,
  `PaymentAllocation`, `PaymentReferenceCheck`. All four use `BelongsToMerchant` +
  `BelongsToBranch`. ULID auto-set on create; `getRouteKeyName()=ulid` where route-bound.
- Register in `app/Domain/Tenancy/TenantOwnership.php`: all four to `BRANCH_OWNED` +
  `COMPOSITE_CONSISTENCY` (`branch_id -> merchant_branches`) + `MODELS` (`branch`).
- Factories: `PaymentRecordingGroupFactory`, `PaymentRecordFactory`,
  `PaymentAllocationFactory`, `PaymentReferenceCheckFactory` (tenant-aware; never
  bypass scoping).
- Migrations registered in `docs/architecture/migrations/manifest.yaml` with
  `data_dictionary: docs/architecture/data-dictionary/invoicing-and-payments.md`.

## Eloquent relationships (payments)

`Invoice hasMany PaymentRecordingGroup`; `Invoice hasMany PaymentRecord`.
`PaymentRecordingGroup belongsTo Invoice`, `hasMany PaymentRecord` (components),
`hasMany PaymentAllocation` (through components), `belongsTo User` (maker).
`PaymentRecord belongsTo PaymentRecordingGroup`, `belongsTo Invoice`, `hasMany
PaymentAllocation`, `hasMany PaymentReferenceCheck`, `belongsTo Client` (payer,
nullable). `PaymentAllocation belongsTo PaymentRecord`, `belongsTo Invoice`,
`belongsTo InvoiceItem` (nullable). `PaymentReferenceCheck belongsTo PaymentRecord`,
`belongsTo PaymentRecord as matched` (nullable), `belongsTo User` (override_by).

## Phase 18B handoff (payments)

Phase 18B consumes a `pending_validation` group as the validation unit: verifies
each component, creates one immutable `payment_validation_events` row for the group,
sets components `validated`, increases `invoices.validated_paid_minor`, transitions
the invoice (`partially_paid`/`paid`), auto-issues one receipt (`receipt_number_
sequences`), and earns commission by component — all atomic, maker not equal to
checker. The `payment_records.validated_amount_minor` and the group
`validated_at`/`rejected_at` timestamps, and the component
`validated`/`rejected`/`correction_required`/`reversed` states, are written only
then. Nothing in Phase 18A pre-creates those rows or states.

---

# Financial Validation Controls (Phase 18B) — Correction 18 (validation onward)

Completes the auditable merchant-client money lifecycle after Phase 18A recording:
group validation/rejection/correction, immutable validation events, invoice
validated balance + payment state, one automatic gap-free receipt (+ reissue),
external refunds, finance disputes, branch cash-up reconciliation, database-backed
period locks (+ exceptional reopen), and scoped async finance exports. Controlling
sources: Plan §13.8, §13.15, §13.16, §41–§46, §25, §65, §67, §70, §80; ADR-0007
(maker/checker + period locks). Money is integer minor units. Full/normalized
payment references, external refund references, raw client contact, private file
paths and signed URLs are never returned by a Resource, audited, or logged.

## Specification-gate resolutions (controlling decisions — Gates A–J)

### Gate A — group-level validation-event schema: RESOLVED (`payment_recording_group_id` parent)
The Plan §13.8 shorthand `payment_record_id` is superseded by the corrected §13.15,
§42, §80 and the Phase 18A handoff, which are all group-level: **one** validation
decision, **one** immutable event, **one** receipt per validated group, all
component records updated atomically. Therefore `payment_validation_events` is
parented by `payment_recording_group_id` (not per component). Component rows remain
individually traceable through `payment_records.payment_recording_group_id`
(component → group → validation event); each component also carries
`validated_amount_minor` set atomically at validation. No per-component validation
event is created.

### Gate B — whole-group decision; no partial group validation: RESOLVED
A group is validated as a unit. The only final decisions are `validated`,
`rejected`, `correction_required`. It is impossible for some components to become
`validated` while others remain `pending_validation`: the transactional action sets
every component to the group's decision or rolls back. If any component fails a
revalidation check the whole group is `rejected` or `correction_required` per the
documented reason.

### Gate C — commission handoff without inventing Phase 20G: RESOLVED (durable outbox seam)
No durable domain-event outbox exists in the repository (only the R4
`idempotency_keys` store and typed audit log). Phase 18B therefore adds the
**smallest** Plan-compatible durable seam, `commission_handoff_events`, written in
the same validation transaction — one immutable, idempotent **per-component**
`validated_allocation` payload identifying invoice item, service, personnel,
payment record, validated amount, currency, validation event and effective
timestamp. It carries **no** commission rate, earned row, or payable liability;
those belong to Phases 20F/20G. A `reversal` seam row is written on refund
finalization (Gate E). The validation transaction cannot commit an invoice as
`paid`/`partially_paid` while a required handoff row is missing. This is explicitly
**not** a commission ledger; `commission_rules`/`commission_ledger`/earnings/payout
tables are not created in Phase 18B.

### Gate D — refund component allocation: RESOLVED (`refunds.payment_record_id` boundary)
Servana records external refunds; it does not move funds. A refund is allocated to
concrete validated payment **components** via `refunds.payment_record_id` (the
existing Plan relationship). A refund spanning multiple components is persisted as
**one coherent atomic workflow of multiple `refunds` rows** — one per allocated
component, sharing a `refund_group_ulid` correlation — so each component allocation
is individually traceable. No unallocated group-level refund amount is stored. A
separate allocation table is not essential because the component grain is the
`payment_record` and the correlation column links a multi-component refund.

### Gate E — non-destructive refund accounting: RESOLVED
Refund finalization preserves original payment records, receipt rows, and
validation events; it **adds** the finalized refund row(s), reduces
`invoices.validated_paid_minor` by the finalized allocated amount, and derives the
resulting invoice payment state deterministically (0 → `issued`; 0<x<total →
`partially_paid`; =total → `paid`; outside 0..total → fail + roll back). It writes
a durable **per-component proportional** `commission_handoff_events` reversal seam
(largest-remainder split by validated weight, ADR-0007 §Decision 4) and never
recomputes or overwrites any future stored commission amount. Historical payment,
receipt and audit rows are never deleted or rewritten.

### Gate F — period reopen governance: RESOLVED (see ADR-0007 §Decision 3)
Finance owns routine locking + routine reopen execution; a Merchant Administrator
approves exceptional reopen only where the lock's `exception_required` flag is set;
`period_lock.reopen ⟂ merchant.period_reopen.approve_exception`; the same user may
not request and approve an exceptional reopen; reopen requires reason, fresh
step-up and audit. The `exception_required` flag is sourced at lock creation from
existing merchant configuration — no new policy engine is invented. Minimal schema:
request/approval columns on `financial_period_locks` (no separate request table).

### Gate G — existing `branch_cash_ups` seam: RESOLVED (forward-only evolution)
`branch_cash_ups` already exists (Phase 7 seam migration
`2026_06_15_000108_create_branch_cash_ups_table`, plus R5
`2026_06_23_000002_add_merchant_id_to_branch_owned_tables` which added
`merchant_id`). It is **not** recreated and its shipped migrations are **not**
edited. A forward-only expand/backfill/constrain migration adds the canonical
columns (`business_date`, `approved_by`, `approved_at`, `notes`), maps the existing
money columns to the canonical model, and widens the status CHECK from
(`draft`,`submitted`,`approved`,`rejected`) to add (`correction_requested`,
`locked`). Existing rows (all `default`-seam, none in production) are backfilled
(`business_date` from the linked `branch_day_records.business_date` where present).
See the table entry below for the existing→canonical column mapping.

### Gate H — cash-up expected-total formula: RESOLVED (server-derived)
For a `(merchant, branch, business_date)` cash-up, each method line's
`expected_minor` is the sum of **validated** payment-component `validated_amount_minor`
for that method whose invoice's business date (Africa/Nairobi) equals the cash-up
date, **minus** finalized refund allocations of that method on that date;
pending/rejected/correction-required components are excluded. Header
`expected_minor` = Σ line `expected_minor`. The server computes expected and
variance (`variance = counted − expected`); the Branch Manager supplies only the
counted amounts. No client-supplied expected amount is accepted as authoritative.

### Gate I — finance-export launch types: RESOLVED
The `finance_exports.export_type` CHECK enumerates all nine future types
(`invoices`,`payments`,`receipts`,`cash_up`,`refunds`,`disputes`,`compensation`,
`payouts`,`billing`) for forward compatibility, but the Phase 18B request policy
**rejects** `compensation`,`payouts`,`billing` (owned by Phases 20E–20H / 20A–20B)
with `422 unsupported_export_type`. Only the six implemented domains are requestable.

### Gate J — receipt generation and PDF ownership: RESOLVED
Receipt issuance is automatic after successful group validation — there is **no**
manual issue route or button. The initial `receipts` row is durable in the same
financial transaction; the PDF is generated through the Phase 10F file domain with
purpose `receipt_pdf` via a durable outbox-guaranteed job (`GenerateReceiptPdf`,
`TenantAwareJob`). A receipt is never surfaced as successfully issued while its
required durable generation record (queued job + `receipts.file_generation_status`)
is absent. Reissue creates a **new** `receipts` row referencing the original
(`reissue_of_receipt_id`); the original is immutable and keeps its number; the
reissue receives a new gap-free number.

## Phase 18B boundary (what these tables do NOT do)

No commission rate/earned/payable rows (20F/20G); no actual fund movement (external
refunds only); no M-Pesa/Daraja provider workflow (20D); no notifications/inbox
platform or report catalogue/materialized views (21N); no day-close/cash-up PDF or
email (21N); no complete flagged-audit workflow or full permission-matrix closure
(19); no platform-fee/subscription/payout tables (20A/20B/20E/20F–20H).

## Table: `payment_validation_events` (18B, branch-owned) — immutable group decision

One immutable event per group validation decision (Gate A/B). Append-only; no
UPDATE/DELETE route.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint identity | no | — | internal PK; never exposed |
| `ulid` | char(26) | no | auto | public id + route key |
| `merchant_id` | bigint | no | — | FK RESTRICT merchants; `BelongsToMerchant` |
| `branch_id` | bigint | no | — | FK RESTRICT merchant_branches; `BelongsToBranch` |
| `payment_recording_group_id` | bigint | no | — | FK RESTRICT payment_recording_groups; the validated group (Gate A) |
| `invoice_id` | bigint | no | — | FK RESTRICT invoices; equals group.invoice_id |
| `checker_user_id` | bigint | no | — | FK RESTRICT users; Finance actor; ≠ group.maker_user_id |
| `decision` | varchar | no | — | CHECK in (`validated`,`rejected`,`correction_required`) |
| `validated_amount_minor` | bigint | yes | null | equals group.total_amount_minor for `validated`; null for non-validated (documented contract) |
| `reason` | varchar | yes | null | sanitized, length-capped; **required** for `rejected`/`correction_required`; never carries a reference |
| `created_at` | timestamptz | no | — | append-only; no `updated_at` (immutable) |

**Constraints / indexes.** `UNIQUE (ulid)`; `UNIQUE (id, merchant_id)`; composite
FKs `(branch_id, merchant_id) → merchant_branches`, `(invoice_id, merchant_id) →
invoices`, `(payment_recording_group_id, merchant_id) → payment_recording_groups`
(group + invoice + branch tenant consistency). CHECK: `validated_amount_minor` is
non-null and `>= 0` iff `decision='validated'`, else null; `reason` non-null iff
decision in {rejected, correction_required}. **Partial unique index** `UNIQUE
(payment_recording_group_id) WHERE decision='validated'` — at most one final
validated event per group. Indexes `(payment_recording_group_id)`,
`(invoice_id)`, `(branch_id, created_at)`, `(checker_user_id, created_at)`.
No hard-delete API.

## Table: `receipt_number_sequences` (18B, merchant-owned) — gap-free receipt numbering (§13.15)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint identity | no | — | internal PK |
| `merchant_id` | bigint | no | — | FK RESTRICT merchants |
| `scope` | varchar | no | `receipt` | CHECK in (`receipt`) |
| `next_value` | bigint | no | 1 | next number to allocate |
| `prefix` | varchar | yes | null | optional per-merchant prefix |
| `created_at`/`updated_at` | timestamptz | no | — | |

**Constraints.** `UNIQUE (merchant_id, scope)`. Allocation is under `SELECT … FOR
UPDATE` inside the receipt-issuance transaction; **never** `MAX(receipt_number)+1`.
Numbers are per merchant, gap-free on committed issuance, never reused; a rolled-back
issuance consumes no number (the `FOR UPDATE` increment rolls back with the txn).

## Table: `receipts` (18B, branch-owned) — one original per validated group (+ reissue)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint identity | no | — | internal PK |
| `ulid` | char(26) | no | auto | public id + route key |
| `merchant_id` | bigint | no | — | FK RESTRICT merchants |
| `branch_id` | bigint | no | — | FK RESTRICT merchant_branches |
| `invoice_id` | bigint | no | — | FK RESTRICT invoices |
| `payment_validation_event_id` | bigint | yes | — | FK RESTRICT payment_validation_events; the validated event (nullable only for schema symmetry; always set for an original) |
| `receipt_number` | bigint | no | — | unique per merchant; from `receipt_number_sequences` |
| `amount_minor` | bigint | no | — | validated group total; CHECK `> 0` |
| `currency` | char(3) | no | — | uppercase ISO; equals invoice.currency |
| `components` | jsonb | no | — | safe snapshots only: per-component `{method, amount_minor}` — **no** full/normalized reference, no internal id, no path |
| `reissue_of_receipt_id` | bigint | yes | null | FK RESTRICT receipts; set on a reissue; null on an original |
| `reason` | varchar | yes | null | sanitized reissue reason; null on an original |
| `file_id` | bigint | yes | null | FK RESTRICT uploaded_files; the `receipt_pdf` file (set when generation completes) |
| `file_generation_status` | varchar | no | `pending` | CHECK in (`pending`,`ready`,`failed`); a receipt is not "issued for download" until `ready` |
| `issued_by` | bigint | yes | null | FK RESTRICT users; Finance actor for a reissue; null for an automatic original |
| `created_at`/`updated_at` | timestamptz | no | — | original row content is immutable after issue |

**Constraints / indexes.** `UNIQUE (ulid)`; `UNIQUE (id, merchant_id)`; `UNIQUE
(merchant_id, receipt_number)`; composite FKs `(branch_id, merchant_id)`,
`(invoice_id, merchant_id)`, `(payment_validation_event_id, merchant_id)`,
`(reissue_of_receipt_id, merchant_id)`. **Partial unique index** `UNIQUE
(payment_validation_event_id) WHERE reissue_of_receipt_id IS NULL` — exactly one
original receipt per validated event. CHECK: `amount_minor > 0`; currency
uppercase-ISO. App/service invariants (not expressible as CHECK): a receipt row may
not exist without a `validated` `payment_validation_events` row; an original's
`components` sum equals `amount_minor`; a reissue references an immutable original
and receives a new number. Indexes `(invoice_id)`, `(branch_id, created_at)`,
`(merchant_id, receipt_number)`. No hard-delete API; the original is never mutated
(reissue is additive).

## Table: `refunds` (18B, branch-owned) — external refund, component-allocated (Gate D/E)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint identity | no | — | internal PK |
| `ulid` | char(26) | no | auto | public id + route key |
| `merchant_id` | bigint | no | — | FK RESTRICT merchants |
| `branch_id` | bigint | no | — | FK RESTRICT merchant_branches |
| `invoice_id` | bigint | no | — | FK RESTRICT invoices |
| `payment_record_id` | bigint | no | — | FK RESTRICT payment_records; the validated component being refunded (Gate D) — non-null (mandatory allocation) |
| `refund_group_ulid` | char(26) | no | — | correlation for a multi-component refund workflow; single-component refund is its own group of one |
| `amount_minor` | bigint | no | — | positive integer minor units; ≤ component remaining refundable validated amount |
| `currency` | char(3) | no | — | uppercase ISO; equals invoice/payment currency |
| `method` | varchar | no | — | CHECK = payment method set; the external refund method |
| `external_reference_encrypted` | text | yes | null | Laravel `encrypted`; required per method rules; never returned raw (masked suffix only) |
| `reason` | varchar | no | — | sanitized, length-capped |
| `status` | varchar | no | `requested` | CHECK in (`requested`,`approved`,`finalized`,`rejected`) |
| `requested_by` | bigint | no | — | FK RESTRICT users |
| `approved_by` | bigint | yes | null | FK RESTRICT users; ≠ requested_by (maker/checker); required for approved/finalized |
| `finalized_by` | bigint | yes | null | FK RESTRICT users; required for finalized |
| `rejected_by` | bigint | yes | null | FK RESTRICT users; required for rejected |
| `approved_at`/`finalized_at`/`rejected_at` | timestamptz | yes | null | set on the matching transition |
| `created_at`/`updated_at` | timestamptz | no | — | |

**Constraints / indexes.** `UNIQUE (ulid)`; `UNIQUE (id, merchant_id)`; composite
FKs `(branch_id, merchant_id)`, `(invoice_id, merchant_id)`, `(payment_record_id,
merchant_id) → payment_records`. CHECK: `amount_minor > 0`; currency uppercase-ISO;
`approved_by` non-null iff status in {approved, finalized}; `finalized_by` non-null
iff status=finalized; `rejected_by` non-null iff status=rejected; step/timestamp
coherence. App invariants: `payment_record.status = validated`; Σ finalized+in-flight
refund `amount_minor` for a component ≤ `component.validated_amount_minor`
(remaining-refundable, enforced under the payment_record row lock);
`approved_by ≠ requested_by`. Indexes `(invoice_id)`, `(payment_record_id)`,
`(branch_id, status)`, `(refund_group_ulid)`. No hard-delete API.

## Table: `finance_disputes` (18B, branch-owned) — investigation record

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint identity | no | — | internal PK |
| `ulid` | char(26) | no | auto | public id + route key |
| `merchant_id` | bigint | no | — | FK RESTRICT merchants |
| `branch_id` | bigint | no | — | FK RESTRICT merchant_branches |
| `invoice_id` | bigint | yes | null | FK RESTRICT invoices |
| `payment_record_id` | bigint | yes | null | FK RESTRICT payment_records |
| `status` | varchar | no | `open` | CHECK in (`open`,`under_review`,`resolved`,`rejected`) |
| `reason` | varchar | no | — | sanitized, length-capped |
| `resolution_note` | varchar | yes | null | required for resolved/rejected |
| `evidence_file_id` | bigint | yes | null | FK RESTRICT uploaded_files; purpose `dispute_evidence`; path never exposed |
| `created_by` | bigint | no | — | FK RESTRICT users |
| `resolved_by` | bigint | yes | null | FK RESTRICT users; required for resolved/rejected |
| `created_at`/`updated_at` | timestamptz | no | — | |

**Constraints / indexes.** `UNIQUE (ulid)`; `UNIQUE (id, merchant_id)`; composite
FKs `(branch_id, merchant_id)`, `(invoice_id, merchant_id)`, `(payment_record_id,
merchant_id)`. CHECK: at least one of `invoice_id`/`payment_record_id` is non-null;
`resolution_note` + `resolved_by` non-null iff status in {resolved, rejected}. The
disputed source record is never mutated by the dispute workflow. Indexes
`(branch_id, status)`, `(invoice_id)`, `(payment_record_id)`. No hard-delete API.
(Phase 18B uses the authoritative 4-state Plan set; the broader Scope-only status
list is not added unless the Plan is amended.)

## Table: `branch_cash_ups` (evolved 18B, branch-owned) — one cash-up per branch-day

Evolved forward-only from the Phase 7 seam (Gate G). **Existing→canonical mapping:**
`expected_total`→ header `expected_minor`; `cash_counted` retained as the cash line;
`discrepancy_amount`→ header `variance_minor`; `recorded_totals` (json) retained for
back-compat but superseded by `cash_up_lines`; `reviewed_by`/`reviewed_at`/
`review_note` retained; new `approved_by`/`approved_at`/`notes` added (approval
distinct from generic review); `business_date` added (backfilled). Canonical columns:

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id`, `ulid`, `merchant_id`, `branch_id`, `branch_day_record_id` | | | | existing (merchant_id from R5; branch_day_record_id FK) |
| `business_date` | date | yes→backfilled | null | Africa/Nairobi business date; one cash-up per (branch, business_date) |
| `status` | varchar | no | `draft` | CHECK widened to (`draft`,`submitted`,`approved`,`rejected`,`correction_requested`,`locked`) |
| `expected_minor` (was `expected_total`) | bigint | no | 0 | server-derived (Gate H) |
| `counted_minor` | bigint | no | 0 | Σ line counted; Branch Manager input |
| `variance_minor` (was `discrepancy_amount`) | bigint | no | 0 | `counted − expected` |
| `submitted_by`/`submitted_at` | | | | existing |
| `approved_by`/`approved_at` | bigint/timestamptz | yes | null | Finance checker; `approved_by ≠ submitted_by` |
| `notes` | varchar | yes | null | sanitized |

**Constraints.** Existing `UNIQUE(ulid)`; add **partial unique** `UNIQUE
(branch_id, business_date) WHERE business_date IS NOT NULL` (one cash-up per
branch-day); status CHECK widened (drop+recreate the named CHECK — forward-only, no
edit to the shipped migration). App invariants: header totals equal Σ line totals;
`variance = counted − expected`; expected is server-derived; maker (Branch Manager)
≠ checker (Finance); submitted/approved values are not destructively overwritten
(correction creates a new draft cycle, not a silent rewrite).

## Table: `cash_up_lines` (18B, branch-owned via cash-up) — per-method line

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint identity | no | — | internal PK (child evidence row; no ulid) |
| `merchant_id` | bigint | no | — | FK RESTRICT; equals cash_up.merchant_id |
| `branch_id` | bigint | no | — | FK RESTRICT; equals cash_up.branch_id |
| `cash_up_id` | bigint | no | — | FK RESTRICT branch_cash_ups |
| `method` | varchar | no | — | CHECK = concrete payment methods only (never `split_payment`) |
| `expected_minor` | bigint | no | 0 | server-derived for this method |
| `counted_minor` | bigint | no | 0 | Branch Manager input |
| `variance_minor` | bigint | no | 0 | `counted − expected` |
| `created_at`/`updated_at` | timestamptz | no | — | |

**Constraints / indexes.** composite FK `(cash_up_id, merchant_id) → branch_cash_ups`;
`UNIQUE (cash_up_id, method)` (one line per method per cash-up); method CHECK
excludes `split_payment`. Index `(cash_up_id)`.

## Table: `financial_period_locks` (18B, merchant-owned; optional branch scope)

Replaces the always-open repository (ADR-0007 §Decision 2/3).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint identity | no | — | internal PK |
| `ulid` | char(26) | no | auto | public id + route key |
| `merchant_id` | bigint | no | — | FK RESTRICT merchants |
| `branch_id` | bigint | yes | null | FK RESTRICT merchant_branches; null = merchant-wide |
| `period_start` | date | no | — | CHECK `period_start <= period_end` |
| `period_end` | date | no | — | |
| `status` | varchar | no | `locked` | CHECK in (`open`,`locked`,`reopened`) |
| `exception_required` | boolean | no | false | Gate F: reopen needs Merchant Admin approval |
| `locked_by` | bigint | no | — | FK RESTRICT users; Finance |
| `locked_at` | timestamptz | no | — | |
| `reopen_requested_by` | bigint | yes | null | FK RESTRICT users; Finance requester |
| `reopen_requested_at` | timestamptz | yes | null | |
| `reopen_reason` | varchar | yes | null | required to reopen |
| `reopen_approved_by` | bigint | yes | null | FK RESTRICT users; Merchant Admin ≠ requester (exception only) |
| `reopen_approved_at` | timestamptz | yes | null | |
| `reopened_by` | bigint | yes | null | FK RESTRICT users; Finance executor |
| `reopened_at` | timestamptz | yes | null | |
| `created_at`/`updated_at` | timestamptz | no | — | |

**Constraints / indexes.** `UNIQUE (ulid)`; `UNIQUE (id, merchant_id)`; composite FK
`(branch_id, merchant_id)` when branch-scoped; CHECK `period_start <= period_end`;
status CHECK. **No overlapping active lock** for the same scope: enforced by a
PostgreSQL exclusion constraint over `merchant_id`, a normalized branch key
(`COALESCE(branch_id,0)`) and a `daterange(period_start, period_end, '[]')` WHERE
`status='locked'` (`btree_gist`; matches the appointment-exclusion precedent).
Reopen coherence CHECK: `reopen_approved_by ≠ reopen_requested_by`; `reopened_*`
set only for status `reopened`. Indexes `(merchant_id, status)`,
`(merchant_id, branch_id, period_start, period_end)`.

## Table: `finance_exports` (18B, merchant-owned; optional branch scope) — §65/§67 async export

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint identity | no | — | internal PK |
| `ulid` | char(26) | no | auto | public id + route key |
| `merchant_id` | bigint | no | — | FK RESTRICT merchants |
| `branch_id` | bigint | yes | null | FK RESTRICT merchant_branches; null = merchant-wide |
| `requested_by` | bigint | no | — | FK RESTRICT users |
| `export_type` | varchar | no | — | CHECK in (`invoices`,`payments`,`receipts`,`cash_up`,`refunds`,`disputes`,`compensation`,`payouts`,`billing`) — last three rejected by request policy (Gate I) |
| `scope_json` | jsonb | no | — | validated filters (date range, branch, status…) |
| `reason` | varchar | no | — | sanitized, length-capped |
| `status` | varchar | no | `queued` | CHECK in (`queued`,`processing`,`ready`,`failed`,`expired`,`revoked`) |
| `file_id` | bigint | yes | null | FK RESTRICT uploaded_files; purpose `finance_export`; set when ready |
| `row_count` | integer | yes | null | rows written |
| `expires_at` | timestamptz | yes | null | auto-expiry |
| `first_downloaded_at` | timestamptz | yes | null | set once |
| `last_downloaded_at` | timestamptz | yes | null | updated each successful download |
| `download_count` | integer | no | 0 | incremented atomically |
| `failure_code` | varchar | yes | null | redacted failure code |
| `failure_message_redacted` | varchar | yes | null | redacted; never SQLSTATE/stack/PII |
| `created_at`/`updated_at` | timestamptz | no | — | |

**Constraints / indexes.** `UNIQUE (ulid)`; `UNIQUE (id, merchant_id)`; composite FK
`(branch_id, merchant_id)` when branch-scoped; status + export_type CHECKs;
`download_count >= 0`. Indexes `(merchant_id, status)`, `(requested_by, created_at)`,
`(expires_at)`. Masked rows only; CSV at minimum (no PDF renderer added in 18B).
Private storage via the Phase 10F file boundary; authorized signed download;
`download_count` incremented atomically; `first_downloaded_at` set once;
`last_downloaded_at` per download.

## Table: `commission_handoff_events` (18B, branch-owned) — durable 20G seam (Gate C/E)

Immutable, idempotent per-component seam consumed by Phase 20G. **Not** a commission
ledger — carries no rate, earned row, or payable.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint identity | no | — | internal PK |
| `ulid` | char(26) | no | auto | public id |
| `merchant_id` | bigint | no | — | FK RESTRICT merchants |
| `branch_id` | bigint | no | — | FK RESTRICT merchant_branches |
| `kind` | varchar | no | — | CHECK in (`validated_allocation`,`reversal`) |
| `payment_validation_event_id` | bigint | yes | null | FK RESTRICT; set for `validated_allocation` |
| `refund_id` | bigint | yes | null | FK RESTRICT refunds; set for `reversal` |
| `payment_record_id` | bigint | no | — | FK RESTRICT payment_records; the component |
| `invoice_id` | bigint | no | — | FK RESTRICT invoices |
| `invoice_item_id` | bigint | yes | null | FK RESTRICT invoice_items; when item-level known |
| `service_id` | bigint | yes | null | FK RESTRICT services |
| `personnel_id` | bigint | yes | null | FK RESTRICT merchant_users/personnel |
| `amount_minor` | bigint | no | — | validated (or reversed, negative-signed via `kind`) component amount |
| `currency` | char(3) | no | — | uppercase ISO |
| `effective_at` | timestamptz | no | — | validation/finalization effective time |
| `consumed_at` | timestamptz | yes | null | set by Phase 20G on consumption (never in 18B) |
| `created_at` | timestamptz | no | — | append-only |

**Constraints / indexes.** `UNIQUE (ulid)`; composite FKs for tenant consistency;
CHECK: `payment_validation_event_id` non-null iff kind=`validated_allocation`;
`refund_id` non-null iff kind=`reversal`. **Partial unique** `UNIQUE
(payment_validation_event_id, payment_record_id) WHERE
kind='validated_allocation'` and `UNIQUE (refund_id, payment_record_id) WHERE
kind='reversal'` — idempotent per (event/refund, component). Indexes
`(merchant_id, consumed_at)`, `(payment_record_id)`. No hard-delete API.

## Audit events (18B, typed, safe context only)

`customer_payment.validated`, `.rejected`, `.correction_requested`,
`.reference_corrected`, `.resubmitted`; `receipt.issued`, `.reissued`,
`.downloaded`; `refund.requested`, `.approved`, `.rejected`, `.finalized`;
`finance_dispute.opened`, `.review_started`, `.resolved`, `.rejected`;
`cash_up.draft_updated`, `.submitted`, `.approved`, `.rejected`,
`.correction_requested`, `.resubmitted`, `.locked`; `financial_period.locked`,
`.reopen_requested`, `.reopen_approved`, `.reopened`; `finance_export.requested`,
`.generated`, `.failed`, `.downloaded`, `.expired`, `.revoked`. Context: ULIDs,
integer minor amounts, currency, safe statuses, masked reference suffix, sanitized
reasons only. **Never**: full/normalized reference, external refund reference
plaintext, full client contact, private file path, signed URL/signature, export
content, raw CSV/PDF, SQLSTATE, stack trace, internal bigint id, MFA code,
authorization header. A rolled-back action emits no success event.

## Models, factories, registration (18B)

- Models in `app/Domain/Payments/Models`, `app/Domain/Receipts/Models`,
  `app/Domain/Refunds/Models`, `app/Domain/FinanceOps/Models`,
  `app/Domain/Compensation/Models`. Branch-owned tables use `BelongsToMerchant` +
  `BelongsToBranch`; `receipt_number_sequences`, `financial_period_locks`,
  `finance_exports` use `BelongsToMerchant` (merchant-owned; branch nullable).
- Register in `app/Domain/Tenancy/TenantOwnership.php`: branch-owned tables to
  `BRANCH_OWNED` + `COMPOSITE_CONSISTENCY` + `MODELS`; merchant-owned tables to
  `MERCHANT_OWNED` + `MODELS`; `cash_up_lines` classified via its cash-up parent.
- Factories for every new table (tenant-aware).
- Migrations registered in `docs/architecture/migrations/manifest.yaml` with
  `data_dictionary: docs/architecture/data-dictionary/invoicing-and-payments.md`.

## Phase 20G handoff (commission)

Phase 20G consumes `commission_handoff_events` (`validated_allocation` + `reversal`)
to compute earned commission and reversals once `commission_rules` /
`commission_ledger` exist. Phase 18B guarantees one immutable, idempotent
per-component row per validation and per finalized refund component, with no
invented rate.
