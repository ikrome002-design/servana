# Invoicing and Merchant-Client Payments — Data Dictionary (Plan §13.8, §13.15, §40, §41, §25.3; Phases 17, 18A)

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
