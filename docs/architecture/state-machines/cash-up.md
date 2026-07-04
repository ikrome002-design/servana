# Cash-Up — State Machine (Plan §45; Phase 18B)

> Authoritative lifecycle for `branch_cash_ups` (evolved from the Phase 7 seam) and
> its `cash_up_lines`. Maker = Branch Manager; checker = Finance. Status changes go
> through named actions (`CreateOrUpdateCashUpDraft`, `SubmitCashUp`, `ApproveCashUp`,
> `RejectCashUp`, `RequestCashUpCorrection`, `ResubmitCashUp`, `LockApprovedCashUp`).
> Invalid transitions return `422 invalid_state_transition`. Mirrors the DB CHECK.
> Expected totals are **server-derived** (Gate H); counted totals are Branch Manager
> input; variance = counted − expected.

## States

| State | Owner | Meaning |
|---|---|---|
| `draft` | Branch Manager | Being counted; per-method `cash_up_lines` with server-computed expected + entered counted. One cash-up per (branch, business_date). |
| `submitted` | Branch Manager → Finance | Submitted for Finance review; values frozen for this cycle. |
| `approved` | Finance | Finance approved (approver ≠ submitter). |
| `rejected` | Finance | Finance rejected (terminal for this cycle; a new draft cycle may follow only via correction). |
| `correction_requested` | Finance → Branch Manager | Finance returned it for correction. |
| `locked` | (system/Finance) | Approved cash-up locked; the branch-day may close. Terminal. |

## Transitions

```text
draft                 -> submitted            [SubmitCashUp]            (Branch Manager)
submitted             -> approved              [ApproveCashUp]           (Finance; approver ≠ submitter)
submitted             -> rejected              [RejectCashUp]            (Finance; reason)
submitted             -> correction_requested  [RequestCashUpCorrection] (Finance; reason)
correction_requested  -> submitted             [ResubmitCashUp]          (Branch Manager)
approved              -> locked                [LockApprovedCashUp]      (per documented sequence)
```

Any other transition is invalid → `422 invalid_state_transition`. `draft` counts are
updated via `CreateOrUpdateCashUpDraft` (a same-state PUT, not a transition).

## Invariants

- One cash-up per branch-day (partial unique `(branch_id, business_date)`).
- Line methods are concrete payment methods; **never** `split_payment`.
- Header totals equal Σ line totals; `variance_minor = counted_minor −
  expected_minor` at header and line.
- Expected totals are server-derived (Gate H): validated components of that method
  on the Africa/Nairobi business date, minus finalized refunds of that method that
  day; pending/rejected/correction-required excluded. No client-supplied expected
  amount is authoritative.
- Maker (Branch Manager, `branch.cash_up.submit`) ≠ checker (Finance,
  `cash_up.approve`/`.reject`/`.request_correction`). `branch.cash_up.submit ⟂
  cash_up.approve`.
- Submitted/approved values are not destructively overwritten; correction opens a new
  cycle rather than a silent rewrite.
- Period / day controls enforced.

## Branch day-close guard (§45)

A branch day cannot close when: a cash-up is missing; the cash-up is not
approved/locked per the documented sequence; the cash-up has an unresolved
discrepancy / correction request; `pending_validation` payment groups exist for the
branch-day; or required receipt generation is incomplete. (Day-close / cash-up PDFs
are Phase 21N.)

## Audit / failure codes

Events: `cash_up.draft_updated`, `.submitted`, `.approved`, `.rejected`,
`.correction_requested`, `.resubmitted`, `.locked`. Codes:
`422 invalid_state_transition`, `423 financial_period_locked`, `403` (permission /
maker-is-checker), `404` (foreign tenant), `409 branch_day_close_blocked`.
