# Queue Entry — State Machine (Plan §25.1, §25.2, §37; Phase 16B)

> Named mandatory state-machine specification (Plan §25.1). One transition record
> per legal Phase 16B transition. Status is **never** assigned directly — every
> change runs through its named domain action via the `QueueEntryStateMachine`
> guard; a `422 invalid_state_transition` is returned for any unlisted pair, and a
> source-scan/test enforces no direct status writes (no generic `PATCH status`).
> Times are branch business time in `Africa/Nairobi`; timestamps are UTC.

Aggregate: `queue_entries` (Front Office operated; Branch Manager read-only +
queue configuration; Personnel strict own-scope read). Actor for every operational
mutation: **Front Office**, within its resolved merchant + assigned branch.
Branch Manager, Personnel, HR, Merchant Admin, Finance, Audit, and Super Admin
receive **no** operational queue mutation authority. Branch Manager configures the
queue (open/close, capacity, default assignment mode) through the separate Branch
Day configuration route using `branch.profile.manage` + `day.open_close` — that is
**not** an operational entry mutation.

## States (Phase 16B — authoritative set)

```text
waiting       on the queue, not yet assigned to a personnel member
assigned      a personnel member is assigned (reservation established)
called        the client has been called for service
in_service    service is being performed (Phase 16C couples a service session here)
completed     terminal: queue service finished (Phase 16C completes the session)
transferred   transient: being moved between personnel / back to waiting
cancelled     terminal: removed before service (reason required)
no_show       terminal: client did not present when called
```

Active (queue-occupying) states that hold a branch position and block branch
day-close / archival: `waiting`, `assigned`, `called`, `in_service`,
`transferred`. Terminal states that release the position and do not block:
`completed`, `cancelled`, `no_show`.

Ordered active states (carry a unique, contiguous, positive branch position):
`waiting`, `assigned`, `called`. (`in_service`/`transferred` keep their last
position value for audit but are excluded from the active-ordered partial-unique
index; only `waiting|assigned|called` participate in the position uniqueness set —
see the data dictionary.)

## Transition inventory (the authoritative arrow set)

```text
waiting     → assigned
waiting     → transferred
waiting     → cancelled
waiting     → no_show

assigned    → called
assigned    → transferred
assigned    → cancelled
assigned    → no_show

called      → in_service
called      → transferred
called      → cancelled
called      → no_show

in_service  → completed

transferred → assigned
transferred → waiting
```

No unlisted transition is allowed. Terminal states (`completed`, `cancelled`,
`no_show`) cannot reopen. `transferred` is transient — every transfer resolves in
the same transaction to `assigned` (a new personnel member) or `waiting` (returned
to the pool); a queue entry never persists as `transferred` at rest.

Phase 16C coupling point (NOT implemented in 16B): `called → in_service` will
additionally create/start exactly one `service_sessions` row, and
`in_service → completed` will complete it. Phase 16B creates **no** service
session, invoice, payment, commission preview, or invoice trigger.

---

### create (origin) — waiting | assigned
```text
aggregate: queue_entry | current_state: (none) | next_state: waiting OR assigned
actor: Front Office | required_permission: queue.create
origin: EXACTLY ONE of walk_in_id (POST /walk-ins) or appointment_id (appointments.queue.store)
tenant_conditions: client/service/personnel all merchant_id == ctx.merchant
branch_conditions: branch in active assignments; client/service/personnel same branch
operational_status_conditions: merchant active; branch active; Branch Day open; queue_is_open; capacity not reached
transaction_boundary: single transaction | rows_locked: pg_advisory_xact_lock(merchant,branch) | duplicate_protection: unique(walk_in_id), unique(appointment_id)
writes: status = assigned when an eligible personnel member is resolved at creation (manual/preferred/next_available success), else waiting; position = next active position; queued_at = now(); estimated_wait_minutes snapshot
generated_records: the queue entry (+ the walk_in row when origin is a walk-in) | NO service session
audit_event: walk_in.created (+ queue_entry.created) for walk-in origin; appointment.queued (+ queue_entry.created) for appointment origin
failure_codes: 409 branch_day_not_open, 409 queue_closed, 409 queue_capacity_reached, 409 queue_conversion_exists, 422 validation, 403, 404
tests: contiguous unique positions; capacity blocks; closed queue/day blocks; duplicate source 409; atomic rollback
```

### waiting → assigned (assignment establishes a reservation)
```text
actor: Front Office | required_permission: queue.assign
assignment_modes: next_available (deterministic NextAvailablePersonnelSelector) | manual (explicit target ULID) | preferred_personnel (needs preferred_personnel.select + explicit preferred ULID)
tenant_conditions: target staff_profile.merchant_id == ctx.merchant | branch_conditions: target active branch assignment == entry branch
input_validation: manual requires target ULID; preferred requires preferred ULID + (override target requires non-empty reason)
preconditions: status = waiting
transaction_boundary: single transaction | rows_locked: queue_entry FOR UPDATE + advisory queue lock
scheduling_gate: QueuePersonnelAssignmentValidator → PersonnelSchedulingValidator (15B: merchant/branch/lifecycle/active-assignment/service-status/eligibility/availability) — NO duplication; NOT suspended; not already in a conflicting active queue/service state
writes: staff_profile_id = target; assignment_mode; assigned_at = now(); status = assigned; preferred_personnel_override_reason when overriding a preferred request; recalc estimate
audit_event: queue_entry.assigned (info; old/new personnel, assignment mode, sanitised override reason)
failure_codes: 422 invalid_state_transition, 422 personnel_* scheduling code, 403, 404
tests: next-available lowest-load deterministic + stable tie-break; manual requires target; ineligible/unavailable/suspended/wrong-branch deny; preferred needs permission; preferred unavailable may stay waiting; override requires reason
```

### assigned → called
```text
actor: Front Office | required_permission: queue.assign
preconditions: status = assigned; staff_profile_id not null
scheduling_gate: revalidate personnel (QueuePersonnelAssignmentValidator)
writes: status = called; called_at = now(); recalc estimate | generated_records: NONE (no service session)
audit_event: queue_entry.called (info) | failure_codes: 422 invalid_state_transition, 422 personnel_*, 403, 404
tests: call from assigned only; revalidates personnel; no session created
```

### called → in_service
```text
actor: Front Office | required_permission: queue.assign
preconditions: status = called
scheduling_gate: revalidate personnel
writes: status = in_service; started_at = now(); recalc estimate
generated_records: NONE in 16B (Phase 16C creates/starts exactly one service session here)
audit_event: queue_entry.started (info) | failure_codes: 422 invalid_state_transition, 422 personnel_*, 403, 404
tests: start from called only; no service session / invoice in 16B
```

### in_service → completed
```text
actor: Front Office | required_permission: queue.assign
preconditions: status = in_service
writes: status = completed; completed_at = now(); release active position (compact waiting); recalc estimate
generated_records: NONE in 16B (Phase 16C completes the linked session; Phase 17 then invoices)
audit_event: queue_entry.completed (info) | failure_codes: 422 invalid_state_transition, 403, 404
tests: complete from in_service only; releases position; NO invoice/payment/receipt/commission
```

### (waiting|assigned|called) → transferred → (assigned|waiting)
```text
actor: Front Office | required_permission: queue.transfer
input_validation: non-empty transfer_reason REQUIRED; resolve to a DIFFERENT eligible target (→assigned) OR explicit return to pool (→waiting)
preconditions: status in {waiting, assigned, called}
transaction_boundary: single transaction | rows_locked: queue_entry FOR UPDATE + advisory queue lock
scheduling_gate: when targeting a person, revalidate via QueuePersonnelAssignmentValidator
writes: passes through transferred; transferred_at, transferred_from_staff_profile_id, transferred_to_staff_profile_id, transfer_reason; resolves to assigned (target set, assigned_at) or waiting (staff_profile_id null); position kept safe/compacted
audit_event: queue_entry.transferred (warning; old + new personnel, sanitised reason)
failure_codes: 422 invalid_state_transition, 422 reason_required, 422 personnel_*, 403, 404
tests: transfer revalidates target; preserves source metadata; finishes assigned/waiting; Branch Manager 403; integrity preserved
```

### (waiting|assigned|called) → cancelled
```text
actor: Front Office | required_permission: queue.assign
input_validation: non-empty cancellation_reason REQUIRED
preconditions: status in {waiting, assigned, called}
writes: status = cancelled; cancelled_at = now(); cancellation_reason = sanitised; release + compact active position; recalc estimate
audit_event: queue_entry.cancelled (warning; sanitised reason) | failure_codes: 422 invalid_state_transition, 422 reason_required, 403, 404
tests: cancel requires reason; compacts waiting positions; record preserved; terminal cannot reopen
```

### (waiting|assigned|called) → no_show
```text
actor: Front Office | required_permission: queue.assign
preconditions: status in {waiting, assigned, called}
writes: status = no_show; no_show_at = now(); release + compact active position; recalc estimate
audit_event: queue_entry.no_show (warning) — distinct from cancellation, NOT personnel unavailability
failure_codes: 422 invalid_state_transition, 403, 404
tests: no_show distinct from cancel; compacts positions; never marks personnel unavailable
```

## Non-transition operations (queue management; no state change)

### reorder (waiting entries only)
```text
actor: Front Office | required_permission: queue.reorder
input: the COMPLETE ordered set of active waiting queue-entry ULIDs for the branch
validation: reject duplicates, omissions, foreign entries, terminal entries, entries from another branch, stale snapshot
transaction_boundary: single transaction | rows_locked: advisory queue lock + waiting rows FOR UPDATE
writes: position reassigned 1..N contiguous for the supplied order; recalc estimates
failure_codes: 409 queue_order_changed (stale snapshot), 422 validation, 403, 404
tests: applies requested order; rejects duplicate/omitted/foreign/terminal; stale → 409; concurrency-safe
```

### wait estimate override
```text
actor: Front Office | required_permission: queue.assign
input: explicit estimated_wait_override_minutes + non-empty estimated_wait_override_reason
writes: estimated_wait_override_minutes, estimated_wait_override_reason, estimated_wait_overridden_by = actor; CALCULATED estimated_wait_minutes preserved separately
audit_event: queue_entry.wait_estimate_overridden (info) | failure_codes: 422 reason_required, 403, 404
tests: override requires reason; calculated + overridden remain distinguishable; audited
```

### queue configuration (Branch Manager)
```text
actor: Branch Manager | required_permission: branch.profile.manage + day.open_close (NOT an operational queue key)
target: branch_day_records (queue_is_open, queue_capacity, queue_default_assignment_mode)
rules: capacity > 0 when set; capacity below current active count rejected; closing blocks new entries but never deletes/cancels existing; default mode ∈ {next_available, manual}
audit_event: queue.configuration.updated (notice) | failure_codes: 422 validation (e.g. capacity_below_active), 403, 404
tests: BM read+configure; capacity-below-active rejected; FO cannot configure; BM cannot operate entries
```

## Notes
- Generic `PATCH status` does not exist; one route per transition action.
- Terminal states are immutable (no reopen/edit); records are retained (no
  hard-delete API).
- Every transition has positive, invalid-transition, authorization, concurrency,
  and audit tests (`tests/Feature/Scheduling` / `tests/Feature/Queue`).
- One mutation writes one coherent domain audit event, except create from a
  walk-in / appointment, where two first-class aggregates (walk_in/appointment +
  queue_entry) are created in the single atomic operation and each carries its own
  creation event.
