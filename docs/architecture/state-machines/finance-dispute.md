# Finance Dispute — State Machine (Plan §44; Phase 18B)

> Authoritative lifecycle for `finance_disputes`. Finance-only investigation record
> over an invoice or payment record; it **never** mutates the disputed source row.
> Status changes go through named actions (`CreateFinanceDispute`,
> `StartFinanceDisputeReview`, `ResolveFinanceDispute`, `RejectFinanceDispute`).
> Invalid transitions return `422 invalid_state_transition`. Mirrors the DB CHECK.
> Uses the authoritative Plan 4-state set (the broader Scope-only list is not added).

## States

| State | Meaning |
|---|---|
| `open` | Created by Finance (`finance_dispute.manage`) with a mandatory reason and a link to an invoice and/or payment record. Optional private `dispute_evidence` file. |
| `under_review` | Investigation started. |
| `resolved` | Terminal. Requires a resolution note. |
| `rejected` | Terminal. Requires a resolution note. |

## Transitions

```text
open          -> under_review   [StartFinanceDisputeReview]
open          -> rejected        [RejectFinanceDispute]   (resolution note)
under_review  -> resolved        [ResolveFinanceDispute]  (resolution note)
under_review  -> rejected        [RejectFinanceDispute]   (resolution note)
```

Any other transition is invalid → `422 invalid_state_transition`.

## Invariants

- Finance-only via `finance_dispute.manage`; tenant + branch scoped.
- At least one of `invoice_id` / `payment_record_id` identifies the disputed record
  (DB CHECK).
- `resolution_note` required for `resolved`/`rejected`.
- Evidence uses the private Phase 10F file domain (`dispute_evidence`); the storage
  path is never exposed.
- The disputed source record (invoice / payment) is never modified by the workflow.
- **No** period-lock requirement (`finance_dispute.manage` is `PL n/a`).

## Audit / failure codes

Events: `finance_dispute.opened`, `.review_started`, `.resolved`, `.rejected`.
Codes: `422 invalid_state_transition`, `422` (missing linkage / missing note),
`403` (permission), `404` (foreign tenant). Phase 19 owns the broader flagged-audit
review workflow.
