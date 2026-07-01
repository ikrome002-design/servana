# Merchant-Client Invoice — State Machine (Plan §25.3, §40, §13.8; Phase 17)

> Named mandatory state-machine specification (Plan §25.1/§25.3). One transition
> record per legal transition. Status is **never** assigned directly — every change
> runs through its named domain action via the `InvoiceStateMachine` guard; a
> `422 invalid_state_transition` is returned for any unlisted pair, and a
> source-scan/test enforces no direct status writes (no generic `PATCH status`,
> no `mark-paid`, no `mark-void` shortcut). Money is integer minor units (`Money`);
> timestamps are UTC; business time is `Africa/Nairobi`.

Aggregate: `invoices`. Actors: **Front Office** creates/drafts/finalizes
(`invoice.create`); **Finance** voids and adjusts (`invoice.void.request_or_execute
_as_policy`, `invoice.adjustment.manage`; MFA mandatory, step-up on void). Branch
Manager, Merchant Admin, HR, Personnel, Audit, and Super Admin receive **no**
invoice-mutation authority. Reads: Front Office + Finance (`invoice.view`); other
roles map to existing report/audit read permissions only.

## States (Phase 17 — authoritative set, mirrors the DB CHECK)

```text
draft                editable; no number; no finalized_at; Front Office only
issued               finalized: number allocated; snapshots written; balance owed
partially_paid       some validated payment recorded (Phase 18B writes this)
paid                 validated payment == total (Phase 18B writes this)
void_pending         Finance void requested; prior payable state preserved
voided               terminal: voided; number retained; snapshots intact
adjusted             terminal: superseded by an additive correction
refund_pending       paid invoice under refund (Phase 18B-driven)
adjustment_required  paid invoice flagged for additive adjustment (Phase 18B-driven)
```

## Transition inventory (the authoritative arrow set, Plan §25.3)

```text
draft          → issued
issued         → partially_paid
issued         → paid
issued         → void_pending
issued         → adjusted
partially_paid → paid
partially_paid → void_pending
partially_paid → adjusted
void_pending   → voided
void_pending   → issued            (rejection, when previous_status = issued)
void_pending   → partially_paid    (rejection, when previous_status = partially_paid)
paid           → refund_pending
paid           → adjustment_required
```

No unlisted transition is allowed. `voided`, `adjusted`, `refund_pending`,
`adjustment_required` are terminal/correction states with no Phase-17 onward
transition. Every other pair is invalid → `422 invalid_state_transition`.

**Phase 17 reachability.** No invoice can reach `paid`/`partially_paid` in Phase 17
(there is no validated-payment path until Phase 18A/18B; `validated_paid_minor`
starts at 0 and is never writable through a Phase 17 route). The payment-entry and
post-payment transitions (`issued/partially_paid → partially_paid/paid`,
`paid → refund_pending|adjustment_required`, and the `void_pending → partially_paid`
rejection) are **defined and unit-tested** here but are **Phase-18B-driven** — Phase
17 exposes **no** public endpoint that simulates payment. The reachable Phase 17
arrows are `draft → issued`, `issued → void_pending → voided|issued`, and
`issued → adjusted`.

---

### create draft — (none) → draft
```text
aggregate: invoice | current_state: (none) | next_state: draft
actor: Front Office | required_permission: invoice.create (drafting; finalization re-checks)
input_validation: client_ulid + one-or-more completed service_session_ulids; NO merchant_id/branch_id/status/invoice_number/totals/finalized_at/created_by/validated_paid accepted
tenant/branch_conditions: client + every session merchant_id == ctx.merchant; same branch; same client; same currency
source_conditions: every service_session.status = completed; not already on another invoice_items row
billing_gate: billing_mutation (read_only_grace / suspended_billing block create)
period_lock: FinancialPeriodGuard (423 financial_period_locked if the source period is locked)
transaction_boundary: single transaction | rows_locked: source service_sessions FOR UPDATE
writes: invoice(status=draft, currency, created_by, totals computed from sources, NO number, NO finalized_at); one invoice_items row per session (price/personnel/preferred-fee derived under lock); recompute header totals
generated_records: one invoices row + N invoice_items rows | NO number, NO payment, NO receipt, NO commission ledger
audit_event: invoice.created (info)
failure_codes: 422 validation, 422 invalid_source_session_state, 409 service_session_already_invoiced, 423 financial_period_locked, 403, 404
tests: FO drafts from one+many completed sessions; pending/in_progress/cancelled source denied; wrong client/branch/merchant denied; duplicate source denied; browser totals ignored; billing/period-lock denial
```

### update draft — draft → draft (items add/remove; no transition)
```text
aggregate: invoice | current_state: draft | next_state: draft
actor: Front Office | required_permission: invoice.create
preconditions: status = draft (issued+ cannot be edited as a draft → 422 invalid_state_transition / read-only)
input_validation: completed service_session_ulids only; authoritative price/personnel never accepted
writes: replace/modify invoice_items transactionally (remove allowed only before finalization); recompute header totals; NO number
audit_event: invoice.updated_draft (info)
failure_codes: 422 validation, 409 service_session_already_invoiced, 423, 403, 404
tests: add/remove items recompute totals; issued invoice rejects draft edit; duplicate/invalid source denied
```

### finalize — draft → issued
```text
aggregate: invoice | current_state: draft | next_state: issued
actor: Front Office | required_permission: invoice.create | classification: financial_mutation
idempotency: Idempotency-Key REQUIRED (EnsureIdempotentRequest; same key+payload replays the stored success; different payload → 409; replay never consumes a second number / duplicates items / re-audits)
billing_gate: billing_mutation | period_lock: FinancialPeriodGuard (423)
preconditions: status = draft; >= 1 valid completed source; no source already invoiced
transaction_boundary: single transaction
rows_locked: invoice FOR UPDATE; source service_sessions + services FOR UPDATE; invoice_number_sequences row FOR UPDATE
resolution: service price (current, snapshotted) ; PreferredPersonnelFeeResolver (honoured ? legacy services.preferred_personnel_fee_minor : none) ; percentage_fee_config_snapshot = null (Gate E) ; totals via Money (integer)
numbering: lock-or-create invoice_number_sequences(merchant, merchant_client_invoice); allocate next_value; format {branch.code}-INV-{padded}; increment
writes: invoice_items snapshots frozen; header subtotal/preferred_fee/tax/discount/total snapshotted; invoice_number set; status → issued; finalized_at = now()
generated_records: exactly one number consumed; NO payment/receipt/commission ledger
audit_event: invoice.finalized (info; number, totals, preferred-fee snapshot+source, client/session ULIDs)
on_failure: transaction rolls back → no number consumed, no issued invoice, no snapshot change, no source marked invoiced, no success audit
failure_codes: 409 idempotency_*, 422 invalid_state_transition, 422 invalid_source_session_state, 423 financial_period_locked, 403, 404
tests: completed session finalizes; number only at finalization; price/personnel/preferred-fee snapshotted; later service-price/legacy-fee change does not alter the issued invoice; percentage config null; integer totals; atomic rollback; concurrency distinct numbers; idempotent replay
```

### void request — issued → void_pending ; partially_paid → void_pending
```text
aggregate: invoice | current_state: issued | partially_paid | next_state: void_pending
actor: Finance | required_permission: invoice.void.request_or_execute_as_policy | MFA: mandatory | step_up: required | severity: high
billing_gate: billing_mutation | period_lock: FinancialPeriodGuard (423)
input_validation: non-empty sanitised reason REQUIRED
writes: previous_status = current status; status → void_pending; void_reason = sanitised (snapshots untouched; number retained)
audit_event: invoice.void_requested (high; before/after status, reason, actor)
failure_codes: 422 invalid_state_transition, 422 reason_required, 423, 403 (non-Finance), 404
tests: only Finance; MFA+step-up; reason required; period-lock 423; invalid state denied; snapshots/number unchanged
```

### void execute — void_pending → voided
```text
aggregate: invoice | current_state: void_pending | next_state: voided
actor: Finance | required_permission: invoice.void.request_or_execute_as_policy | step_up: required
period_lock: FinancialPeriodGuard (423)
writes: status → voided; voided_at = now(); voided_by = actor; (void_reason retained); original snapshots + number UNCHANGED; NO row deleted
audit_event: invoice.voided (high; number retained, before/after, reason, actor)
failure_codes: 422 invalid_state_transition, 423, 403, 404
tests: void is additive/non-destructive; number retained & never reused; no item/header monetary mutation; no deletion; rollback on induced failure
```

### void reject — void_pending → issued | partially_paid (restoration)
```text
aggregate: invoice | current_state: void_pending | next_state: previous_status (issued | partially_paid)
actor: Finance | required_permission: invoice.void.request_or_execute_as_policy
writes: status → previous_status; previous_status = null; void_reason cleared
audit_event: invoice.void_rejected (warning; restored state)
failure_codes: 422 invalid_state_transition, 403, 404
tests: rejection restores the exact prior payable state; previous_status cleared
```

### adjust — issued → adjusted ; partially_paid → adjusted
```text
aggregate: invoice | current_state: issued | partially_paid | next_state: adjusted
actor: Finance | required_permission: invoice.adjustment.manage | MFA: mandatory | severity: high
billing_gate: billing_mutation | period_lock: FinancialPeriodGuard (423)
input_validation: non-empty sanitised reason REQUIRED
representation (Gate B): the original is marked adjusted (superseded), snapshots + number UNCHANGED, NO row deleted; the additive correcting invoice (when created) links via adjustment_of_invoice_id; before/after amounts recorded in audit
writes: status → adjusted; adjusted_at = now(); adjusted_by = actor; adjustment_reason = sanitised
audit_event: invoice.adjusted (high; before/after totals, reason, actor)
failure_codes: 422 invalid_state_transition, 422 reason_required, 423, 403 (non-Finance), 404
tests: only Finance; reason required; period-lock 423; original snapshots/number intact; additive; non-destructive
```

### Phase-18B-driven transitions (defined + unit-tested; no Phase 17 endpoint)
```text
issued/partially_paid → partially_paid|paid   payment validation (Phase 18B) writes validated_paid_minor under invoice lock
paid → refund_pending                          refund workflow (Phase 18B)
paid → adjustment_required                      post-payment adjustment (Phase 18B)
void_pending → partially_paid                   rejection restoration of a part-paid invoice (Phase 18B reachable)
```
These exist in `InvoiceStatus::allowedTransitions()` and the `InvoiceStateMachine`
data-provider tests, but no Phase 17 public route can drive an invoice to `paid`/
`partially_paid`; `validated_paid_minor` is written only by the locked Phase-18B
domain method (`Invoice::applyValidatedPayment()` seam), never by a Phase 17 request.

## Notes
- Generic `PATCH status`, `mark-paid`, `mark-void`, `receipt`, and `payment` routes
  do **not** exist; one route/action per transition.
- `issued` and later monetary snapshots (`subtotal/discount/tax/total/
  preferred_personnel_fee_snapshot/percentage_fee_config_snapshot/invoice_number`)
  are immutable; a source-scan test asserts no action mutates them after finalization.
- `void` and `adjust` are additive and non-destructive: no financial row is deleted,
  the number is retained, original snapshots are preserved.
- Every transition has positive, invalid-transition, authorization, period-lock,
  billing, idempotency (finalize), concurrency (finalize), and audit tests
  (`tests/Feature/Invoicing`). Failed/rolled-back actions write no success audit.
