# Service Session — State Machine (Plan §25.1, §25.2, §13.7; Phase 16C)

> Named mandatory state-machine specification (Plan §25.1). One transition record
> per legal Phase 16C transition. Status is **never** assigned directly — every
> change runs through its named domain action via the `ServiceSessionStateMachine`
> guard; a `422 invalid_state_transition` is returned for any unlisted pair, and a
> source-scan/test enforces no direct status writes (no generic `PATCH status`).
> Times are branch business time in `Africa/Nairobi`; timestamps are UTC.

Aggregate: `service_sessions` (Front Office operated; Personnel strict own-scope
read). Actor for every operational mutation: **Front Office**, within its resolved
merchant + assigned branch. Branch Manager, Personnel, HR, Merchant Admin, Finance,
Audit, and Super Admin receive **no** service-session mutation authority. Personnel
may view only sessions assigned to the authenticated Personnel user
(`personnel.my_sessions.view`).

## States (Phase 16C — authoritative set)

```text
pending       created, service not yet started (transient in the queue path —
              created and started atomically; rests at pending only on a future
              non-queue path)
in_progress   the service is being performed (personnel derives `busy`)
completed     terminal: the service finished (Phase 17 then invoices)
cancelled     terminal: the session was aborted before completion (reason required)
```

Active (work-occupying) states that block branch day-close / archival and project
personnel `busy`: `pending`, `in_progress`. Terminal states that release the
constraint and do not block: `completed`, `cancelled`.

## Transition inventory (the authoritative arrow set)

```text
pending     → in_progress
pending     → cancelled
in_progress → completed
in_progress → cancelled
```

No unlisted transition is allowed. Terminal states (`completed`, `cancelled`)
cannot reopen. Every other pair is invalid → `422 invalid_state_transition`.

---

### start (queue-linked) — (none) → pending → in_progress
```text
aggregate: service_session | current_state: (none) then pending | next_state: in_progress
actor: Front Office | required_permission: service_session.start (+ queue.assign on the queue orchestration route)
origin: a queue_entries row at status `called` (its `appointment_id`/`walk_in_id` carries provenance)
tenant_conditions: source/client/service/personnel all merchant_id == ctx.merchant
branch_conditions: source branch in active assignments; client/service/personnel same branch
operational_status_conditions: merchant active; branch active
input_validation: none from the body (all authoritative values derived from the locked source); no merchant_id/branch_id/status/started_at/staff override accepted
preconditions: queue entry status = called; assigned personnel present; NO existing session for this queue entry; personnel has no other active (pending/in_progress) session
transaction_boundary: single transaction | rows_locked: pg_advisory_xact_lock(merchant,branch) + queue_entry FOR UPDATE + service_session active partial-unique
scheduling_gate: QueuePersonnelAssignmentValidator → PersonnelSchedulingValidator (15B: merchant/branch/lifecycle/active-assignment/service-status/eligibility) — NO duplication
preferred_personnel: PreferredPersonnelExecutionValidator — assigned personnel must satisfy the source preferred request OR be an authorised override (permission + non-empty sanitised reason already on the queue entry); never bypasses eligibility
duplicate_protection: UNIQUE(queue_entry_id) (one session per entry) + partial UNIQUE(staff_profile_id) WHERE status in (pending,in_progress)
writes: create session (status=pending, derived client/service/branch/merchant, preferred_personnel_honored snapshot); status → in_progress; started_at = now(); queue entry status → in_service; queue started_at = now()
generated_records: exactly one service_sessions row | NO invoice, payment, receipt, commission ledger
audit_event: service_session.started (info) [+ queue_entry.started (info) on the orchestration route]
failure_codes: 422 invalid_state_transition, 422 personnel_* scheduling code, 409 duplicate_active_service_session, 403, 404
tests: one session created + started; queue → in_service; repeat/concurrent start cannot duplicate; wrong queue state denied; missing assignment denied; eligibility/branch-assignment/preferred failures denied; induced failure rolls back queue+session+audit
```

### complete (queue-linked) — in_progress → completed
```text
aggregate: service_session | current_state: in_progress | next_state: completed
actor: Front Office | required_permission: service_session.complete (+ queue.assign on the queue orchestration route)
preconditions: session status = in_progress; queue entry status = in_service
transaction_boundary: single transaction | rows_locked: pg_advisory_xact_lock(merchant,branch) + queue_entry FOR UPDATE + session FOR UPDATE
writes: session status → completed; completed_at = now(); queue entry status → completed; queue completed_at = now() (active position released + waiting compacted)
generated_records: NONE — produces a typed NON-PAYABLE CommissionPreviewResult only (preview_status unavailable/not_applicable/not_configured; earned=false; payable=false); NO invoice, NO commission_ledger
audit_event: service_session.completed (info) [+ queue_entry.completed (info) on the orchestration route]
failure_codes: 422 invalid_state_transition, 403, 404
tests: complete from in_progress only; queue+session complete together; preview returned never earned/payable; no invoice/commission ledger row; rollback on induced failure
```

### cancel — pending → cancelled  (in_progress → cancelled defined but deferred at workflow level)
```text
aggregate: service_session | current_state: pending (workflow) / in_progress (machine-only, deferred) | next_state: cancelled
actor: Front Office | required_permission: service_session.cancel
input_validation: non-empty cancellation_reason REQUIRED
preconditions: session status = pending; the session is not coupled to a queue entry already at in_service (the cancel action rejects an in-progress queue-linked session — see Gate C)
transaction_boundary: single transaction | rows_locked: session FOR UPDATE (+ queue_entry FOR UPDATE when linked)
writes: status → cancelled; cancelled_at = now(); cancellation_reason = sanitised; releases the active partial-unique + busy projection
generated_records: NONE
audit_event: service_session.cancelled (warning; sanitised reason)
failure_codes: 422 invalid_state_transition, 422 reason_required, 409 (queue-linked in-progress abort deferred), 403, 404
tests: pending cancellation valid; reason required; releases duplicate-active constraint + busy; cancelled does not block branch close; terminal cancellation denied; rollback on induced failure
```

> **Gate C (source-coupling resolution).** The Queue Entry machine defines no
> `in_service → cancelled`. Since queue-linked sessions are created and started
> atomically (`pending` is transient on the queue path) and the queue's only exit
> from `in_service` is `completed`, exposing `in_progress → cancelled` for a
> queue-linked session would strand the queue entry, mark it completed
> (semantically wrong for an aborted service), or require an undocumented queue
> transition — all forbidden. The state machine still **defines and unit-tests**
> `in_progress → cancelled`; the cancel action/route exposes it only where it does
> not strand a queue entry (effectively `pending`, plus any future
> `queue_entry_id IS NULL` direct path). Workflow-level in-progress abort is
> **explicitly deferred** pending an authoritative Queue Entry
> `in_service → (cancelled|aborted)` extension (recommended product decision; a
> future scheduling correction owns it). No queue transition is invented here.

## Notes
- Generic `PATCH status` does not exist; one route/action per transition.
- Terminal states are immutable (no reopen/edit); records are retained (no
  hard-delete API).
- The non-payable completion preview never creates or updates any commission
  ledger, rule, compensation plan, earned status, payable status, or payout
  liability. Only validated payment (later payment/compensation workflow) may
  create earned commission.
- Every transition has positive, invalid-transition, authorization, concurrency,
  and audit tests (`tests/Feature/Scheduling` / `tests/Feature/ServiceSession`).
- One mutation writes one coherent domain audit event; the queue orchestration
  routes additionally carry the queue entry's own start/complete event because two
  first-class aggregates change in one atomic operation.
