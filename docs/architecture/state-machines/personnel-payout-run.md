# Personnel Payout Run — State Machine (Plan §62, §13.12, §25.4/§25.5; Phase 20H)

> Named mandatory state-machine specification (Plan §25.1). A `personnel_payout_runs` row moves
> through a HR → Finance → (Merchant Admin for high-value) → Finance workflow via named actions
> guarded by `PayoutRunStateMachine`. `personnel_payout_items` **mirror** the run status inside the
> same transaction. **Servana moves no money** — mark-paid records that an EXTERNAL payment already
> happened; there is no provider/Wallet call and no dependency on Gate W. Money is integer minor
> units (ADR-005); timestamps UTC; periods `Africa/Nairobi`.

Aggregate: `personnel_payout_runs` (branch-owned `merchant_id` + `branch_id`), with child
`personnel_payout_items` mirroring status.

## States (mirror the DB CHECK)

```text
draft                            HR is building/editing the run; items are regenerable; no ledger claimed
submitted                        HR submitted (freeze); source ledgers claimed via payout_item_id
finance_verified                 Finance verified the run
pending_merchant_admin_approval  high-value run awaiting Merchant-Admin approval
approved                         approved (ordinary Finance OR high-value Merchant Admin); ready to mark paid
paid                             Finance marked paid after an external payment (terminal)
rejected                         Finance rejected a pre-paid run (terminal; correct via a new draft/run)
cancelled                        HR cancelled a draft (terminal)
```

## Transitions (mirror `PayoutRunStatus::allowedTransitions()`)

```text
draft                            -> submitted | cancelled
submitted                        -> finance_verified | rejected
finance_verified                 -> approved | pending_merchant_admin_approval | rejected
pending_merchant_admin_approval  -> approved | rejected
approved                         -> paid
paid | rejected | cancelled      -> (terminal)
```

## High-value fork

At **verify**, the run is high-value when `gross_total_minor > high_value_threshold_snapshot_minor`.
The threshold is snapshotted at **creation** from `merchant_subscriptions.high_value_payout_threshold_minor`
(Phase 20A) — never hardcoded. A **null** snapshot means the gate is inactive (ordinary Finance
approval). A high-value run routes `finance_verified → pending_merchant_admin_approval`; an ordinary
run routes `finance_verified → approved` (Finance `approve_standard`).

## Actors and permissions

```text
create / update_draft / submit / cancel_draft   HR          payout_run.create/update_draft/submit/cancel_draft (branch)
verify / approve_standard / reject / mark_paid   Finance     payout_run.verify/approve_standard/reject/mark_paid (MFA; verify/approve/mark_paid fresh step-up)
approve high-value                               Merchant    merchant.payout.approve_high_value (MFA + fresh step-up)
```

## Ledger claim / release / settle (D-H3-2)

The shipped 20G ledger enums are forward-only (`earned/pending → included_in_payout → paid`, no
backward release). A run therefore:

```text
submit      set the source ledger rows' payout_item_id (claim); ledger status UNCHANGED
reject/cancel  clear payout_item_id (release); ledger status UNCHANGED
mark_paid   advance ledger status forward earned/pending -> included_in_payout -> paid (one txn)
```

`payout_item_id` is a column the 20G append-only guards let move freely (including back to NULL), so
no 20G state-machine change is required.

## Mark-paid invariants (Plan §25.5)

```text
preconditions:  run.status = approved; merchants.status = active; fresh step-up MFA;
                external_payment_reference present; paid_date present
transaction:    single txn; personnel_payout_runs + personnel_payout_items FOR UPDATE
idempotency:    Idempotency-Key REQUIRED (one mark-paid effect; safe replay returns stored result)
writes:         run.status=paid; items.status=paid; linked salary/commission ledgers -> paid;
                external_payment_reference_encrypted; paid_at
audit:          payout_run.marked_paid (CRITICAL)
failures:       409 idempotency; 403 stale_step_up; 422 missing_reference; 422 invalid_state_transition
never:          provider/Wallet call; money movement; original monetary mutation; double mark-paid
```

## Item freeze

Items are snapshotted at draft creation and regenerable (DELETE + re-insert) while the run is
`draft`. On submit they freeze: a DB trigger blocks DELETE of a non-draft item and blocks any
snapshot-column UPDATE (only `status` mirrors the run). Corrections after submit happen via
rejection → new draft or an adjustment run — never a silent line edit. A `paid` run is corrected
only by a new adjustment run, never a status rewind.
