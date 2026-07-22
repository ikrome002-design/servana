# Earnings Query — State Machine (Plan §63, §13.12, §25.4; Phase 20H)

> Named mandatory state-machine specification (Plan §25.1). An `earnings_queries` row moves through a
> personnel → triage → Finance-resolution workflow via named actions guarded by
> `EarningsQueryStateMachine`. **Resolution NEVER mutates a ledger silently** — a monetary correction
> is a separate `compensation_adjustments` row referenced by `resolved_adjustment_id`. Personnel see
> status + resolution note only.

Aggregate: `earnings_queries` (branch-owned `merchant_id` + `branch_id`; personnel own-scope
`staff_profile_id`).

## Subject types (mirror the DB CHECK)

```text
commission_ledger  a commission_ledger row the personnel disagrees with / questions
salary_ledger      a salary_ledger row
payout_item        a personnel_payout_items row (missing/incorrect payout)
```

`subject_id` is validated at create time to belong to the acting `staff_profile_id` — arbitrary ids
are rejected (no polymorphic FK).

## Query types (mirror the DB CHECK) and routing

```text
commission_disagreement  -> finance
salary_disagreement      -> finance
payout_missing           -> finance
payout_amount            -> finance
statement_request        -> hr
other                    -> finance
```

`query_type` sets the triage `assigned_role`. The **resolution permission is always
`earnings_query.respond` (Finance)** per the permission matrix (D-H12-1); routing only decides who
triages.

## States (mirror the DB CHECK)

```text
open       created by personnel; awaiting triage
assigned   a triage owner picked it up (assigned_to set)
resolved   Finance responded with a resolution (terminal)
rejected   Finance rejected the query (terminal)
```

## Transitions (mirror `EarningsQueryStatus::allowedTransitions()`)

```text
open                 -> assigned | resolved | rejected
assigned             -> resolved | rejected
resolved | rejected  -> (terminal)
```

`open → resolved|rejected` is permitted so Finance may respond directly to an untriaged query
(implicitly picking it up). A terminal query is never reopened in Phase 20H.

## Actors and permissions

```text
create                       Personnel   personnel.my_earnings_query.create (own-scope)
respond (resolve/reject)     Finance     earnings_query.respond (MFA)
monetary correction          Finance     compensation.adjustment.create (a separate compensation_adjustments row)
```

## Resolution invariant

A monetary outcome NEVER edits a ledger row. When Finance decides a correction is due, it creates a
`compensation_adjustments` entry through the existing `compensation.adjustment.create` flow and
records that adjustment's id in `resolved_adjustment_id`. The query then transitions to `resolved`
with a `resolution_note`. All lifecycle events are audited
(`earnings_query.created/assigned/responded/resolved/rejected`).
