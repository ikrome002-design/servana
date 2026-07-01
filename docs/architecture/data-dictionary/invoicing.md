# Invoicing — Data Dictionary (Plan §13.8, §40, §25.3; Phase 17)

> Canonical per-table data dictionary for the Phase 17 invoicing substrate
> (`invoice_number_sequences`, `invoices`, `invoice_items`). Settles columns,
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
