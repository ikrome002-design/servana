# Appointment — State Machine (Plan §25.1, §25.2; Phase 16A)

> Named mandatory state-machine specification (Plan §25.1). One transition record
> per legal Phase 16A transition. Status is **never** assigned directly — every
> change runs through its named domain action via the `AppointmentStateMachine`
> guard; a `422 invalid_state_transition` is returned for any unlisted pair, and a
> source-scan/test enforces no direct status writes. Times are branch business time
> in `Africa/Nairobi`; timestamps are UTC.

Aggregate: `appointments` (Front Office owned). Actor for every mutation: **Front
Office**, within its resolved merchant + assigned branch. Branch Manager,
Personnel, HR, Merchant Admin, Finance, Audit, and Super Admin receive **no**
appointment mutation authority (Branch Manager and Personnel are read-only;
Personnel is own-scope).

## States (Phase 16A)

```text
scheduled              initial state on create
confirmed              eligible personnel assigned (reservation established)
checked_in             client physically arrived (same-day, branch day open)
rescheduled            transient: a new interval is being applied
cancelled              terminal: cancelled before check-in
cancelled_with_reason  terminal: cancelled after check-in (reason required)
no_show                terminal: client did not arrive
```

Deferred to later phases (NOT in 16A, added by expand-and-contract):
`checked_in → queued` (16B), `checked_in → in_service` (16C), `completed` (16C).

## Transition inventory (the authoritative arrow set)

```text
scheduled   → confirmed
scheduled   → cancelled
confirmed   → checked_in
confirmed   → rescheduled
confirmed   → cancelled
confirmed   → no_show
checked_in  → cancelled_with_reason
rescheduled → scheduled
rescheduled → confirmed
```

Conflict-reserving statuses (participate in the personnel double-booking
exclusion): `scheduled`, `confirmed`, `checked_in`. Non-reserving: `rescheduled`,
`cancelled`, `cancelled_with_reason`, `no_show`.

---

### scheduled → confirmed (assignment establishes a reservation)
```text
aggregate: appointment | current_state: scheduled | next_state: confirmed
actor: Front Office | required_permission: appointment.assign
tenant_conditions: appointment.merchant_id == ctx.merchant
branch_conditions: appointment.branch_id in active branch assignments
own_scope_conditions: n/a | entitlement_conditions: none
billing_status_conditions: billing gate inherited automatically when 20A–20E supply it (none in 16A)
operational_status_conditions: merchants.status = active | period_lock_conditions: n/a
input_validation: target personnel ULID resolves in tenant+branch; differs only required for transfer
preconditions: appointment.status = scheduled; assigned personnel currently null
transaction_boundary: single DB transaction | rows_locked: appointment FOR UPDATE | advisory_lock: none
scheduling_gate: PersonnelSchedulingValidator::ensure (merchant/branch/lifecycle/active-assignment/service-status/eligibility/availability/interval) + AppointmentBranchScheduleValidator + appointment conflict (DB exclusion)
writes: assigned_personnel_staff_profile_id = target; status = confirmed
generated_records: none | ledger_effects: none | compensation_effects: none
notifications: none in 16A (Phase 21N) | queue_jobs: none
audit_event: appointment.assigned (info) | failure_codes: 422 invalid_state_transition, 422 scheduling code, 409 appointment_schedule_conflict, 403 permission, 404 foreign id
retry_behavior: re-assert state under lock | reversal_or_correction: transfer/cancel
tests: happy-path confirm; ineligible/unavailable deny; conflict 409; cross-branch deny; invalid from non-scheduled
```

### scheduled → cancelled
```text
aggregate: appointment | current_state: scheduled | next_state: cancelled
actor: Front Office | required_permission: appointment.cancel
preconditions: appointment.status = scheduled
transaction_boundary: single transaction | rows_locked: appointment FOR UPDATE
writes: status = cancelled; cancelled_at = now(); (cancellation_reason optional)
audit_event: appointment.cancelled (warning) | failure_codes: 422 invalid_state_transition, 403, 404
reversal_or_correction: terminal (new appointment only)
tests: cancel from scheduled; releases conflict interval; terminal cannot reopen
```

### confirmed → checked_in
```text
aggregate: appointment | current_state: confirmed | next_state: checked_in
actor: Front Office | required_permission: appointment.check_in
operational_status_conditions: merchants.status = active; Branch Day operationally open (same-day)
preconditions: appointment.status = confirmed
transaction_boundary: single transaction | rows_locked: appointment FOR UPDATE
writes: status = checked_in; checked_in_at = now()
generated_records: NONE (no queue entry — 16B; no service session — 16C)
audit_event: appointment.checked_in (info) | failure_codes: 422 invalid_state_transition, 409 branch_day_not_open, 403, 404
tests: check-in from confirmed; branch-day-closed deny; no queue/session row created
```

### confirmed → rescheduled → (scheduled | confirmed)
```text
aggregate: appointment | current_state: confirmed | next_state: rescheduled then scheduled|confirmed
actor: Front Office | required_permission: appointment.reschedule
input_validation: new starts_at valid; new ends_at derived from service-duration snapshot; single business date
transaction_boundary: single transaction | rows_locked: appointment FOR UPDATE
scheduling_gate: AppointmentBranchScheduleValidator (new interval) + PersonnelSchedulingValidator (if assigned) + conflict exclusion
writes: starts_at/ends_at updated; passes through rescheduled; returns to confirmed when personnel remains assigned, else scheduled
generated_records: none | audit_event: appointment.rescheduled (info; old+new interval)
failure_codes: 422 invalid_state_transition, 422 scheduling code, 409 appointment_schedule_conflict, 403, 404
concurrency: stale/concurrent updates rejected under row lock
tests: reschedule confirmed→confirmed (assigned) and →scheduled (after unassign path); old interval frees; calendar/hours revalidated
```

### confirmed → no_show
```text
aggregate: appointment | current_state: confirmed | next_state: no_show
actor: Front Office | required_permission: appointment.cancel  (no separate appointment.no_show key)
preconditions: appointment.status = confirmed
transaction_boundary: single transaction | rows_locked: appointment FOR UPDATE
writes: status = no_show; no_show_at = now()
audit_event: appointment.no_show (warning) — distinct event, NOT personnel unavailability
failure_codes: 422 invalid_state_transition, 403, 404
tests: no_show from confirmed; distinct from cancellation; releases conflict interval; never marks personnel unavailable
```

### confirmed → cancelled
```text
aggregate: appointment | current_state: confirmed | next_state: cancelled
actor: Front Office | required_permission: appointment.cancel
writes: status = cancelled; cancelled_at = now()
audit_event: appointment.cancelled (warning) | failure_codes: 422 invalid_state_transition, 403, 404
tests: cancel from confirmed; releases interval
```

### checked_in → cancelled_with_reason
```text
aggregate: appointment | current_state: checked_in | next_state: cancelled_with_reason
actor: Front Office | required_permission: appointment.cancel
input_validation: non-empty cancellation_reason REQUIRED
preconditions: appointment.status = checked_in
transaction_boundary: single transaction | rows_locked: appointment FOR UPDATE
writes: status = cancelled_with_reason; cancelled_at = now(); cancellation_reason = sanitised reason
audit_event: appointment.cancelled (warning; sanitised reason)
failure_codes: 422 invalid_state_transition, 422 reason_required, 403, 404
tests: cancel-with-reason requires reason; missing reason 422; terminal cannot reopen
```

## Notes
- `rescheduled` is transient — every reschedule resolves immediately to `scheduled`
  or `confirmed` in the same transaction; an appointment never persists as
  `rescheduled` at rest.
- Generic `PATCH status` does not exist; there is one route per transition action.
- Terminal states are immutable (no reopen/edit).
- Every transition has positive, invalid-transition, authorization, concurrency,
  and audit tests (`tests/Feature/Scheduling`).
