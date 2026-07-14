# Platform-Fee Ledger Entry — State Machine (Plan §13.10, §51; Phase 20E)

> Named mandatory state-machine specification (Plan §25.1). `platform_fee_ledger_entries` is an
> **append-only financial fact**: monetary/snapshot columns are immutable (DB trigger blocks their
> `UPDATE` and blocks all `DELETE`); only the documented `status` / aggregation-link transitions run
> through named actions via `PlatformFeeLedgerEntryStateMachine`. No generic `PATCH` route. Money is
> integer minor units; timestamps UTC; billing-period boundaries `Africa/Nairobi`; rounding ADR-005.

Aggregate: `platform_fee_ledger_entries` (tenant-owned `merchant_id`, nullable `branch_id`). Reversal and
adjustment amounts are **additive** rows (`entry_type ∈ {reversal, adjustment}`), never edits of the
original `earned` row. Consistent with Plan §953: a money-changing correction creates a
`platform_fee_adjustments` row, never edits a ledger row.

## Entry types (mirror the DB CHECK)

```text
earned      original percentage-fee liability, created at Finance payment validation (billability authority)
reversal    additive negative fact from invoice void / full refund
adjustment  additive fact from partial refund / correction / dispute-resolution money change
```

## States (mirror the DB CHECK) — apply to the `earned` original row

```text
pending      earned + billable (billable_at stamped); not yet aggregated
aggregated   linked to a subscription_invoice_item (platform_fee_rollup); rollup being built
invoiced     the subscription invoice has been issued (immutable); terminal-for-billing in Phase 20E
reversed     a reversal row has fully offset this entry (non-monetary lifecycle marker)
adjusted     an adjustment row has partially modified this entry (non-monetary lifecycle marker)
```

`reversal`/`adjustment` rows are created `pending` and then follow the SAME billing lifecycle as an earned
row — `pending → aggregated → invoiced` — when they are swept into a later subscription invoice's signed
`adjustment` line (future-cycle correction aggregation). A correction is only swept when its ORIGINAL earned
entry was already invoiced (`original.subscription_invoice_item_id IS NOT NULL`); a correction of a
never-invoiced original stays `pending` with no billing effect (that original was already dropped from the
rollup). The `reversed`/`adjusted` markers above are non-monetary markers on the ORIGINAL row only — a
correction row never takes them. **`settled` does not exist in Phase 20E** — settlement of the subscription
invoice is Wallet/Phase 20D-W and never writes back onto this ledger.

## Transition inventory (authoritative arrow set — original `earned` row)

```text
(none)      → pending          RecordOriginalPlatformFeeLiability (at Finance validation)
pending     → aggregated       AggregatePlatformFeesIntoSubscriptionInvoice (link rollup item)
aggregated  → invoiced         subscription invoice issued (parent immutable)
pending     → reversed         RecordPlatformFeeReversal (full reversal before aggregation)
aggregated  → reversed         RecordPlatformFeeReversal (post-aggregation → additive; issued invoice not rewritten)
invoiced    → reversed         RecordPlatformFeeReversal (additive reversal; issued invoice not rewritten)
pending     → adjusted         RecordPlatformFeeAdjustment
aggregated  → adjusted         RecordPlatformFeeAdjustment (additive)
invoiced    → adjusted         RecordPlatformFeeAdjustment (additive; issued invoice not rewritten)
```

### Transition inventory — `reversal`/`adjustment` correction row (future-cycle sweep)

```text
(none)      → pending          RecordPlatformFeeAdjustment (correction created; original was billed)
pending     → aggregated       AggregatePlatformFeesIntoSubscriptionInvoice (link signed adjustment item)
aggregated  → invoiced         subscription invoice issued (parent immutable)
```

The correction's signed contribution to the invoice is its paired `platform_fee_adjustments.amount_minor`;
a negative correction is capped so the invoice total can never go negative, and any un-applied correction
stays `pending` for a later cycle. No `reversed`/`adjusted` transition applies to a correction row.

No unlisted transition. No `UPDATE` of `amount_minor`/`basis_minor`/`rate_basis_points`/snapshots/currency;
no `DELETE`. Any attempt → DB trigger error / `422 invalid_state_transition`.

---

### record original — (none) → pending  (`RecordOriginalPlatformFeeLiability`)
```text
driver: successful Finance payment validation (P18B) — hook INSIDE the validation transaction
input: source invoice (+ item where item basis), validated basis, effective config snapshot
computation: gross = round_half_up(fee_basis_amount_minor * rate_bps / 10000); tier split per E4/E5; largest-remainder item allocation
idempotency: key per validation allocation (invoice + payment allocation) — replay creates no duplicate
writes: entry(entry_type=earned, status=pending, billable_at=now(), merchant_id, branch_id, source ids, all snapshots, gross/client_shifted/merchant_absorbed/merchant_liability)
audit_event: platform_fee.original_recorded (info; safe ULIDs, rate/basis/tier snapshot)  +  platform_fee.became_billable (info) at the same instant
failure_codes: 422 (missing config/tier fail-closed), 409 (idempotent replay → typed already-processed no-op), 403
tests: recording-only payment creates nothing; rejected validation creates nothing; successful validation creates one entry; duplicate validation idempotent; partial-validation basis per E7; fixed-only creates nothing; cross-tenant source rejected; rollback ⇒ no entry + no audit
```

### aggregate — pending → aggregated → invoiced  (`AggregatePlatformFeesIntoSubscriptionInvoice`)
```text
actor: scheduler / Finance | class: financial | transaction_boundary: single tx | rows_locked: FOR UPDATE SKIP LOCKED on selected entries
preconditions: status=pending; one merchant + one currency + target billing period (Africa/Nairobi); not already linked
writes: one subscription_invoice_items(type=platform_fee_rollup); each entry.subscription_invoice_item_id set; status pending→aggregated; on subscription-invoice issuance aggregated→invoiced
idempotency: key per (merchant, billing_period, currency) — enforced by the one-invoice-per-period idempotency + the DB partial-unique `subscription_invoice_items (subscription_invoice_id) WHERE type='platform_fee_rollup'` cycle guard (migration `2026_07_13_000008`, Increment 5A)
implementation note (Increment 5A): because `subscription_invoices` requires a plan/price and is immutable once issued, the rollup is FOLDED INTO the P20B `IssueSubscriptionInvoice` transaction (collectEligible → immutable subtotal; writeRollup → item + link + `pending→aggregated→invoiced`) — NOT added to an already-issued invoice, and NOT a second aggregate. Transitions run through `PlatformFeeLedgerEntryStateMachine`.
audit_event: platform_fee.aggregated + platform_fee.invoiced (info; count, total, subscription invoice ULID)
failure_codes: rollback ⇒ no invoice number consumed, no partial item, no entry marked
tests: aggregate once; ineligible status excluded; cross-merchant/currency never mixed; period boundary exact (Africa/Nairobi `[start,end)`); concurrent aggregators cannot duplicate (row lock + DB cycle guard); fixed-only contributes zero; issued invoice immutable; NO Wallet call/table
```

### reverse — {pending,aggregated,invoiced} → reversed  (`RecordPlatformFeeReversal`)
```text
driver: merchant-client invoice void / full refund (P18B) — hook inside the owning correction tx
writes: NEW entry_type=reversal row (additive, negative of the reversible balance) + platform_fee_adjustments row; original row status → reversed; original monetary fields UNCHANGED
idempotency: key per source correction event
audit_event: platform_fee.reversed (warn; source cause, original ULID → reversal ULID)
failure_codes: 409 over-reversal (cannot exceed remaining reversible balance), period-lock block, 403
tests: void → full reversal; refund → reversal; original unchanged; over-reversal rejected; period-lock enforced; concurrent reversals cannot over-adjust; already-aggregated → additive (issued invoice not rewritten); rollback ⇒ no success audit
```

### adjust — {pending,aggregated,invoiced} → adjusted  (`RecordPlatformFeeAdjustment`)
```text
driver: partial refund / correction / money-changing dispute resolution — inside the owning tx
writes: NEW entry_type=adjustment row (additive) + platform_fee_adjustments row; original row status → adjusted; original monetary fields UNCHANGED; maker/checker where the source workflow requires
idempotency: key per source correction event
audit_event: platform_fee.adjusted (warn; reason, source reference, original ULID → adjustment ULID)
failure_codes: 409 over-adjust, period-lock block, 403 (self-approval where maker/checker applies)
tests: partial refund → proportional adjustment; original unchanged; over-adjust rejected; period-lock; maker/checker; concurrency; already-aggregated additive treatment; audit complete
```

## Notes
- The append-only DB trigger is the authoritative immutability guard (blocks monetary/snapshot `UPDATE`
  and all `DELETE`); the state machine + actions produce friendly errors and manage the `status` marker.
- `merchant_liability_minor == gross_platform_fee_minor` on every `earned` row regardless of tier;
  `client_shifted_minor + merchant_absorbed_minor == gross_platform_fee_minor`.
- No `settled` transition. No Wallet event drives any transition here.
- Every transition has positive, invalid-transition, immutability, idempotency, period-lock, concurrency,
  isolation, and audit tests (`tests/Feature/Billing`/`tests/Feature/PlatformFee`). Rolled-back actions
  write no success audit.
