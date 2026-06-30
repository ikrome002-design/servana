# Appointment — State Machine (Plan §25.1, §25.2; Phase 16A, extended 16B)

> **Phase 16B extension (forward-only expand).** Phase 16B adds exactly one
> transition — `checked_in → queued` — and one state, `queued`, to this aggregate.
> No existing state or arrow is removed. `queued` is added to the enum, the DB
> CHECK, the Resource, and the generated contract by a forward-only expand
> migration (`..._add_queued_status_to_appointments.php`). `in_service`/`completed`
> are **not** added to the appointment aggregate (those belong to the Queue Entry
> and, later, the Service Session machines). The `checked_in → queued` transition
> is specified at the foot of this file under "Phase 16B additions".


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

Added by Phase 16B (expand): `queued` — terminal-for-appointment hand-off to the
queue (see "Phase 16B additions"). Deferred to 16C: `checked_in → in_service`,
`completed` are **not** added to the appointment aggregate (Queue Entry / Service
Session own those).

```text
queued                 the checked-in client has been placed on the branch queue
                       (exactly one queue_entries row; appointment is now read-only
                       from the appointment workflow — the queue owns the lifecycle)
```

## Transition inventory (the authoritative arrow set)

```text
scheduled   → confirmed
scheduled   → cancelled
confirmed   → checked_in
confirmed   → rescheduled
confirmed   → cancelled
confirmed   → no_show
checked_in  → cancelled_with_reason
checked_in  → queued                  (Phase 16B)
rescheduled → scheduled
rescheduled → confirmed
```

Conflict-reserving statuses (participate in the personnel double-booking
exclusion): `scheduled`, `confirmed`, `checked_in`. Non-reserving: `rescheduled`,
`cancelled`, `cancelled_with_reason`, `no_show`, `queued`. (`queued` does **not**
reserve the appointment interval — the queue entry it spawns owns the live
operational state; the personnel exclusion no longer applies once the client is on
the queue.)

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

## Phase 16B additions

### checked_in → queued (place the checked-in client on the branch queue)
```text
aggregate: appointment | current_state: checked_in | next_state: queued
actor: Front Office | required_permission: queue.create
tenant_conditions: appointment.merchant_id == ctx.merchant
branch_conditions: appointment.branch_id in active branch assignments AND == target queue branch
own_scope_conditions: n/a | entitlement_conditions: none
operational_status_conditions: merchants.status = active; branch active; Branch Day open; effective queue open; capacity not reached
period_lock_conditions: n/a
input_validation: appointment resolves in tenant+branch by ULID; optional assignment mode/target/preferred for the spawned entry
preconditions: appointment.status = checked_in; NO existing queue_entries row for this appointment
transaction_boundary: single DB transaction | rows_locked: appointment FOR UPDATE | advisory_lock: pg_advisory_xact_lock(merchant_id, branch_id) for queue position
side_effects: create EXACTLY ONE queue_entries row (appointment_id set, walk_in_id null, status=waiting or assigned, next position, estimate snapshot); appointment.status = queued
generated_records: one queue_entries row | NO service_session, invoice, payment, commission preview
duplicate_protection: unique (appointment_id) on queue_entries — repeated conversion → deterministic 409 queue_conversion_exists
notifications: none (Phase 21N) | queue_jobs: none
audit_event: appointment.queued (info) + queue_entry.created (info) — two first-class aggregates created in one atomic operation
failure_codes: 422 invalid_state_transition, 409 queue_conversion_exists, 409 branch_day_not_open, 409 queue_closed, 409 queue_capacity_reached, 403 permission, 404 foreign id
retry_behavior: re-assert state + appointment_id uniqueness under lock | reversal_or_correction: the queue entry's own lifecycle (cancel/no-show/complete)
tests: convert once; convert twice → 409; wrong-state appointment → 422; foreign tenant → 404; out-of-branch → 403; closed/capacity-full → 409; both queue row + appointment status commit or both roll back; no walk-in/session duplicate
```

`queued` is terminal for the **appointment** workflow: the appointment exposes no
further mutation route once queued; the spawned queue entry owns the operational
lifecycle. The personnel double-booking exclusion `WHERE` clause is unchanged (it
already only covers `scheduled|confirmed|checked_in`), so a queued appointment no
longer reserves the interval.

## Notes
- `rescheduled` is transient — every reschedule resolves immediately to `scheduled`
  or `confirmed` in the same transaction; an appointment never persists as
  `rescheduled` at rest.
- Generic `PATCH status` does not exist; there is one route per transition action.
- Terminal states are immutable (no reopen/edit).
- Every transition has positive, invalid-transition, authorization, concurrency,
  and audit tests (`tests/Feature/Scheduling`).
