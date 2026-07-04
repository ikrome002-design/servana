# Refund — State Machine (Plan §44; Phase 18B)

> Authoritative lifecycle for `refunds`. Servana records an **external** refund; it
> never moves funds. Status is never assigned directly; every change goes through a
> named domain action (`RequestRefund`, `ApproveRefund`, `RejectRefund`,
> `FinalizeRefund`). Invalid transitions return `422 invalid_state_transition`.
> Mirrors the DB `status` CHECK. See ADR-0007 (maker/checker) and the data
> dictionary Gates D/E.

## States

| State | Meaning |
|---|---|
| `requested` | A Finance maker created the refund against a validated component (`refund.create`), with amount ≤ remaining refundable, mandatory allocation, reason and (per method) encrypted external reference. Invoice moves to `refund_pending`, preserving the prior payable state for recovery. |
| `approved` | A distinct checker approved (`refund.approve`, fresh step-up). Not yet money-recognized. |
| `finalized` | Terminal. The external refund is confirmed; recognized balance reduced non-destructively; per-component reversal handoff written. Requires fresh step-up. |
| `rejected` | Terminal. Refund refused; the invoice's prior derived paid state is restored; `validated_paid_minor` unchanged. |

## Transitions

```text
requested  -> approved     [ApproveRefund]   (approver ≠ requester; fresh MFA step-up; period open)
requested  -> rejected     [RejectRefund]    (Finance; reason)
approved   -> finalized    [FinalizeRefund]  (fresh MFA step-up; period open; non-destructive)
approved   -> rejected     [RejectRefund]    (only if documented policy permits reversal of an approval)
```

Any other transition is invalid → `422 invalid_state_transition`. Terminal states
(`finalized`, `rejected`) have no onward transition.

## Invariants (enforced by the actions + DB, under the payment_record row lock)

- `payment_record.status = validated`; `refund.currency = invoice/payment currency`.
- `amount_minor > 0` and ≤ the component's **remaining refundable** amount
  (`validated_amount_minor − Σ finalized/in-flight refunds for the component`).
- Mandatory component allocation (`payment_record_id` non-null, Gate D). A
  multi-component refund is one atomic workflow of multiple `refunds` rows sharing a
  `refund_group_ulid`; the amount is split by largest-remainder over validated
  weights (ADR-0007 §Decision 4). No unallocated group-level refund amount.
- `approved_by ≠ requested_by` (maker/checker). Finalizer satisfies the documented
  approval separation. Approval and finalization each require **fresh** MFA (§19.3).
- Period lock enforced (`423 financial_period_locked`) on request/approve/finalize.
- External reference required + encrypted where the method requires it; masked in
  any API/audit/log.

## Invoice / component effects

```text
request  (from paid | partially_paid): invoice -> refund_pending (prior payable state preserved)
reject   : restore prior derived paid state; validated_paid_minor unchanged
finalize : preserve payment + receipt rows;
           reduce invoice.validated_paid_minor by the allocated amount;
             validated_paid = 0            -> invoice issued
             0 < validated_paid < total    -> invoice partially_paid
             validated_paid = total        -> invoice paid
             validated_paid outside 0..total -> FAIL + roll back
           full reversal of a component  -> component reversed
           partial reversal of a component -> component adjusted
           group -> reversed only if the entire validated group is reversed;
             otherwise group stays validated with additive refund evidence
           write durable per-component proportional commission reversal handoff (20G)
```

## Audit / failure codes

Events: `refund.requested`, `.approved`, `.rejected`, `.finalized` (masked context).
Codes: `422 invalid_state_transition`, `422 refund_exceeds_refundable`,
`422 mixed_currency`, `423 financial_period_locked`, `403` (permission /
maker-is-checker / stale step-up), `404` (foreign tenant).
