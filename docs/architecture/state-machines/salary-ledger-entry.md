# Salary Ledger Entry — State Machine (Plan §60, §13.12; Phase 20G)

> Named mandatory state-machine specification (Plan §25.1). `salary_ledger` is an **append-only
> financial fact**: monetary/period columns are immutable (DB trigger blocks their `UPDATE` and
> blocks all `DELETE`); only `status` and the Phase 20H `payout_item_id` link transition, through
> named actions via `SalaryLedgerStateMachine`. Money is integer minor units; timestamps UTC;
> pay-period boundaries and day counts `Africa/Nairobi`; rounding ADR-005.

Aggregate: `salary_ledger` (branch-owned `merchant_id` + `branch_id`). Corrections are **additive**
rows, never edits of the original `accrual`.

## Entry types (mirror the DB CHECK)

```text
accrual     one payable pay-period SEGMENT accrual, created by the salary-accrual scheduler
reversal    additive NEGATIVE fact (exact negative of an original accrual), source_entry_id → original
adjustment  additive fact for a documented salary correction
```

## States (mirror the DB CHECK)

```text
pending             accrued + available for a future Phase 20H payout
included_in_payout  linked to a personnel_payout_item (Phase 20H)
paid                the payout run was marked paid (Phase 20H)
reversed            a reversal row has offset this accrual (non-monetary marker)
adjusted            an adjustment offset this accrual (non-monetary marker)
```

## Proration convention (G8 — Actual/Actual calendar-day; product-owner decision)

```text
monthly period  [first Nairobi day 00:00, first day of next month 00:00); denominator = actual days in month (28..31)
weekly period   ISO Nairobi week [Mon 00:00, next Mon 00:00); denominator = 7
segment amount  exact = period_salary_minor * payable_days_in_segment / denominator (no float)
rounding        round the PERIOD total once (round-half-up); floor each segment; residual by largest remainder
tie-break       ascending segment start date → ascending compensation-plan ULID → ascending salary-ledger ULID / segment key
segmentation    split on plan effective_from/effective_to, superseding plan, prospective pause, resumption, termination, period boundary
suspension      continue = accrues; prospective pause = first non-payable at its effective date; resumption = first payable after pause
termination     final payable calendar day (exclusive boundary termination_date + 1 day)
fail-closed     daily/hourly/per_shift accrue NOTHING — no approved attendance/shift source exists (G9)
```

## Transition inventory (authoritative arrow set)

```text
(none)              → pending             AccrueSalaryForPayPeriod (scheduler; one row per plan/staff/segment)
pending             → included_in_payout  Phase 20H (payout run build) — NOT Phase 20G
included_in_payout  → paid               Phase 20H (mark-paid) — NOT Phase 20G
pending             → reversed            ReverseSalaryAccrual (not-yet-paid original)
(none)              → pending (reversal)  ReverseSalaryAccrual writes the additive negative row
```

No unlisted transition. No `UPDATE` of `amount_minor`/`pay_period_*`/`pay_period_segment_key`/
currency; no `DELETE`. Any attempt → DB trigger error / `422 invalid_state_transition`.

---

### accrue — (none) → pending  (`AccrueSalaryForPayPeriod`)
```text
actor: salary-accrual scheduler | class: financial | transaction_boundary: per (plan, staff, segment) | tenant-aware job
driver: scheduled cadence in Africa/Nairobi over active salary_plus_commission / salary_only plans
computation: segment the pay period by effective dates + suspension/resumption/termination; Actual/Actual proration (above); monthly/weekly only
idempotency: DB UNIQUE (compensation_plan_id, staff_profile_id, pay_period_segment_key, entry_type='accrual') — replay/concurrent runs create one row per segment
writes: accrual row(s) with the segment's payable pay_period_start/end, pay_period_segment_key, amount_minor, currency, status=pending
guarantee: commission_only plans accrue nothing; a genuine configuration gap accrues nothing; daily/hourly/per_shift fail closed (no inferred hours); a locked financial period is respected (correction via additive adjustment)
audit_event: salary.accrued (info; plan/staff ULIDs, segment, amount)
failure_codes: fail-closed on sub-monthly cadence without an approved attendance source; period-lock respected
tests: full monthly (28/29/30/31) = one full salary; partial monthly; full/partial weekly; mid-period plan change; suspension continue; prospective pause; resumption; termination final day; plan gap; largest-remainder residual; deterministic tie-break; replay idempotent; concurrent runs single row; no float; daily/hourly/per_shift fail closed
```

### reverse — pending → reversed (+ additive negative row)  (`ReverseSalaryAccrual`)
```text
precondition: the original accrual is NOT yet paid; an ALREADY-PAID accrual goes to compensation_adjustments (paid_salary_reversal) — paid history never rewritten
writes: NEW entry_type=reversal row, amount_minor = EXACT NEGATIVE of the original, source_entry_id → original; original status → reversed; original fields UNCHANGED
idempotency: DB UNIQUE (source_entry_id) WHERE entry_type='reversal'
audit_event: salary.reversed (warning; original ULID → reversal ULID)
tests: reverse once; exact negative; original unchanged; already-paid → adjustment; period-lock; audit; rollback ⇒ no success audit
```

## Notes
- The append-only DB trigger is the authoritative immutability guard; the state machine + actions
  produce friendly errors and manage `status` + the Phase 20H `payout_item_id` link.
- `payout_item_id` is nullable + UN-CONSTRAINED until Phase 20H adds its FK (ADR-004 expand).
- Every transition has positive, invalid-transition, immutability, idempotency, concurrency,
  isolation, period-lock, and audit tests (`tests/Feature/Compensation`).
