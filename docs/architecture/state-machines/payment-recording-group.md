# Payment Recording Group — State Machine (Plan §25, §41, §42; Phases 18A, 18B)

> Authoritative lifecycle for `payment_recording_groups` and, by reference, the
> component `payment_records`. Status is **never** assigned directly in a
> controller; every change goes through a named domain action via
> `PaymentRecordingGroupStateMachine`. Invalid transitions return
> `422 invalid_state_transition`. Mirrors the DB `status` CHECK.
>
> **Phase 18A owns only the recording transitions** (`-> recorded ->
> pending_validation`). Validation, rejection, correction and reversal are defined
> here for completeness but are **Phase 18B** — Phase 18A exposes no action or route
> that reaches them, and the machine rejects any 18A attempt to do so.

## Group states

| State | Owner | Meaning |
|---|---|---|
| `draft` | (reserved) | Reserved for a future "save incomplete recording"; **not written in 18A**. |
| `recorded` | **18A** | Group + components + allocations + reference checks committed. Either transient (about to submit) or **held** because a component reference is `duplicate_suspected` and awaits a Finance override. Not yet in Finance validation queue. |
| `pending_validation` | **18A (terminal)** | All references cleared (`unique` or `override_approved`); group submitted for Finance validation (`submitted_for_validation_at` set). Finance notified. This is the Phase-18A success terminal. |
| `validated` | 18B | Finance validated the whole group; invoice validated-paid increased; receipt issued; commission earned. |
| `rejected` | 18B | Finance rejected the group. |
| `correction_required` | 18B | Finance returned the group for correction. |
| `reversed` | 18B | Group reversed (post-validation reversal path). |

## Transitions

```text
(create)               -> recorded                 [RecordCustomerPaymentGroup / RecordCustomerPaymentException]
recorded               -> pending_validation        [same txn when no duplicate suspected;
                                                      else ApproveDuplicatePaymentReference after Finance override]

# ---- Phase 18B (now reachable) ----
pending_validation     -> validated                 [ValidatePaymentRecordingGroup]     (18B)
pending_validation     -> rejected                   [RejectPaymentRecordingGroup]       (18B)
pending_validation     -> correction_required        [RequestPaymentGroupCorrection]     (18B)
correction_required    -> pending_validation         [ResubmitPaymentRecordingGroup]     (18B; after explicit correction)
validated              -> reversed                   [finalized refund/reversal only]    (18B; whole-group reversal)
```

Any transition not listed is invalid → `422 invalid_state_transition`. In Phase 18A
the stricter `ensurePhase18a()` guard refuses every 18B transition (defence in depth
against a mis-wired 18A caller). Phase 18B actions call `ensure()` and reach the 18B
transitions. **Phase 18B adds `correction_required -> pending_validation`** (via
`ResubmitPaymentRecordingGroup`, only after an explicit reference/amount correction);
the enum `allowedTransitions()` and the DB coherence are updated accordingly.

`validated -> reversed` happens **only** when a finalized refund/reversal reverses
the *entire* validated group; a partial-component refund leaves the group
`validated` with additive refund evidence (see `refund.md`).

## Component (`payment_records`) states

Phase 18A creates every component at `pending_validation`. Phase 18B transitions
components **coherently with the group** — no component may diverge from the group's
final decision:

```text
pending_validation  -> validated             (with group -> validated; validated_amount_minor set)
pending_validation  -> rejected              (with group -> rejected)
pending_validation  -> correction_required   (with group -> correction_required)
correction_required -> pending_validation    (with group resubmit)
validated           -> adjusted              (partial refund/reversal of the component)
validated           -> reversed              (full refund/reversal of the component)
```

All component transitions are written atomically with the owning group action; a
partial group validation (some components validated, others not) is impossible.

## Recording invariants (enforced by the composer + DB, under the invoice row lock)

- Invoice must be `issued` or `partially_paid` (Gate A) else `422 invoice_not_recordable`.
- One invoice, one currency; `group.total_amount_minor = Σ(component.amount_minor)`;
  every component amount `> 0`; at least one component.
- `available_to_record = (invoice.total_minor − invoice.validated_paid_minor) −
  active_pending_total`, where `active_pending_total` sums non-terminal,
  not-yet-validated groups (`recorded` + `pending_validation`) for the invoice; the
  new group total must not exceed it. Overpayment → `422 payment_overpayment`.
  The invoice row `FOR UPDATE` lock is the concurrency authority (two concurrent
  submissions cannot collectively exceed the balance).
- `split_payment` is never a component method (Gate B).
- Reference-requiring methods run a durable duplicate check (Gate C); a
  `duplicate_suspected` result holds the group in `recorded` and returns
  `409 payment_reference_duplicate_suspected`.
- Period open (`FinancialPeriodGuard` → `423`); billing-mutation allowed; idempotent
  (R4, keyed on the group). Any failure rolls back **all** rows and emits no success
  event.

## Duplicate-override transition detail

`ApproveDuplicatePaymentReference` (Finance, `customer_payment.duplicate_override`,
MFA + fresh step-up, non-empty sanitized reason) inserts an `override_approved`
`payment_reference_checks` row (preserving the original reference), then moves the
held group `recorded -> pending_validation`. The recording maker
(`group.maker_user_id`) may not be the override actor for the same group; the
`PaymentMakerCheckerGuard` preserves this and the `customer_payment.record`
incompatibility with `customer_payment.validate` for the Phase-18B checker boundary.

## Failure codes

`422 invalid_state_transition`, `422 invoice_not_recordable`, `422 payment_overpayment`,
`422 mixed_currency`, `409 payment_reference_duplicate_suspected`, `409 idempotency`
(reused-key/payload-mismatch), `423 financial_period_locked`, `403` (permission /
maker-is-checker / billing-suspended), `404` (foreign tenant).
