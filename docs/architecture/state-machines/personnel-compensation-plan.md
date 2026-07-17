# Personnel Compensation Plan — State Machine (Plan §59, §80; Scope §12.7–§12.9; Phase 20F)

> Named mandatory state-machine specification (Plan §25.1). One named domain action
> per legal transition; `status` is **never** assigned directly — every change runs
> through its named action via `PersonnelCompensationPlanStateMachine`, and any
> unlisted pair returns `422 invalid_state_transition`. There is **no** generic
> `PATCH status` route and **no** DELETE route for effective terms. Money is integer
> minor units (`Money`); timestamps are UTC; effective-date business logic is
> **`Africa/Nairobi`**.
>
> **Configuration only.** This aggregate defines *how personnel will earn*. It creates
> **no** earned financial fact — no salary accrual, no earned commission, no ledger
> row, no payout, no statement. Those belong to **Phase 20G** (`salary_ledger`,
> `commission_ledger`, `compensation_adjustments`) and **Phase 20H** (payout runs/items,
> earnings). Plan §59 is the controlling text.

Aggregate: `personnel_compensation_plans` (**branch-owned**: `merchant_id` + `branch_id`;
subject `staff_profile_id`). Actor: **HR** (`compensation.plan.*`; `branch_mutation`;
branch-scoped — HR is same-branch only per Plan §179 / Scope §529). Merchant
Administrator, Finance, Front Office, Personnel, Audit, and Super Administrator receive
**no** compensation-configuration mutation authority (Plan §10.2: the Merchant
Administrator "never configures services/pricing/**commissions**/personnel assignment").
Audit reads masked compensation-domain events via the existing
`audit.compensation.view`. Merchant-Administrator compensation visibility arrives as
`merchant.compensation_summary.view` in **Phase 20H**, not here.

**A compensation plan grants no access** (Plan §59): never login, role, branch
assignment, availability, or service eligibility.

## States (mirror the DB CHECK; Scope §12.9)

```text
draft             HR started, not submitted; the ONLY state whose terms may be edited in place
pending_approval  submitted; waiting for an approver; terms frozen
scheduled         approved, future effective_from; participates in overlap exclusion
active            currently effective; monetary terms IMMUTABLE (change = supersede)
expired           terminal; effective_to reached
superseded        terminal; replaced by a newer version; retained for history/audit
rejected          terminal; approver rejected the submission
cancelled         terminal; a draft/scheduled plan withdrawn before it ever took effect
```

## Transition inventory (authoritative arrow set)

```text
(none)            → draft             create plan draft
draft             → draft             update draft terms in place (the only in-place edit)
draft             → pending_approval  submit for approval
draft             → cancelled         cancel before submission
pending_approval  → active            approve; effective_from <= today (incl. backdated)
pending_approval  → scheduled         approve; effective_from > today
pending_approval  → rejected          reject
scheduled         → active            effective_from reached (activation boundary)
scheduled         → cancelled         cancel before activation
active            → superseded        a successor version activates over this plan
active            → expired           effective_to reached
```

No unlisted transition is allowed. `expired`, `superseded`, `rejected`, `cancelled` are
terminal. A `rejected` plan is never re-submitted — HR creates a new draft. Every other
pair is invalid → `422 invalid_state_transition`.

### Supersede is a consequence, not a user action

The permission matrix declares **no** `compensation.plan.supersede` key, and Plan §59
requires "supersede with a new `effective_from` version". Therefore superseding is **not**
a separate user-invoked transition: HR creates a **new plan draft** for the same
(`staff_profile_id`, `branch_id`) with a later `effective_from`, submits it, and an
approver approves it. Inside that **single approval transaction**, the previously `active`
plan is closed out (`active → superseded`, its open-ended `effective_to` set to the
successor's `effective_from`) and the successor becomes `active`/`scheduled`. Half-open
`[from, to)` ranges make the two windows **adjacent, not overlapping**, so the DB
`EXCLUDE` accepts them. The superseded row's monetary terms are **never** rewritten.
Governing permissions are `compensation.plan.create` (successor draft) and
`compensation.plan.approve` (activation) — no permission is invented.

---

### create — (none) → draft
```text
actor: HR | permission: compensation.plan.create | class: branch_mutation | billing_read_only: block | severity: warn
input_validation: compensation_model ∈ (commission_only, salary_plus_commission, salary_only);
  model shape (F1, Plan §59):
    commission_only        ⇒ salary_amount_minor/salary_currency/salary_period NULL AND commission_rule_id NOT NULL
    salary_only            ⇒ salary fields NOT NULL (salary_amount_minor > 0) AND commission_rule_id NULL
    salary_plus_commission ⇒ salary fields NOT NULL (salary_amount_minor > 0) AND commission_rule_id NOT NULL
  salary_currency uppercase ISO-3; salary_period ∈ (monthly, weekly, daily, hourly, per_shift);
  salary_payout_day nullable 1..31; effective_from (date); effective_to nullable (> effective_from);
  change_reason non-empty; staff_profile ULID resolved INSIDE tenant+branch scope;
  commission_rule ULID resolved INSIDE tenant+branch scope and must not be terminal.
  NO merchant_id/branch_id/status/is_backdated/approved_by/approved_at/submitted_by accepted (server-owned).
writes: plan(status=draft, created_by=actor, merchant_id/branch_id from context)
audit_event: compensation.plan.created (warn)
failure_codes: 422 validation, 403, 404 (staff profile / commission rule outside branch scope)
tests: each model's valid shape; salary_only + commission_rule_id rejected (Plan §80 named test);
  commission_only + salary fields rejected; salary_plus_commission missing either rejected;
  salary_amount_minor <= 0 rejected; float salary rejected; foreign staff_profile ULID → 404;
  cross-branch staff_profile → 404; non-HR denied; server-owned fields rejected
```

### update_draft — draft → draft
```text
actor: HR | permission: compensation.plan.update_draft | class: branch_mutation | billing_read_only: block | severity: info
preconditions: status = draft (the ONLY editable state — F7)
input_validation: as create; change_reason non-empty
writes: the draft's terms in place
audit_event: compensation.plan.updated_draft (info)
failure_codes: 422 invalid_state_transition (edit of pending_approval/scheduled/active/terminal), 403, 404
tests: draft edit succeeds; editing a pending_approval plan rejected; editing an ACTIVE plan rejected by the
  DB trigger AND the state machine (F7 supersede-not-edit); editing a superseded/expired/rejected/cancelled
  plan rejected; model shape re-validated on every edit
```

### submit — draft → pending_approval
```text
actor: HR | permission: compensation.plan.submit | class: branch_mutation | billing_read_only: block | severity: warn
maker_checker: INCOMPATIBLE with compensation.plan.approve (matrix) — the submitter can never approve this plan
preconditions: status = draft; model shape valid; change_reason non-empty
backdating (F8): is_backdated := effective_from < CURRENT_DATE in Africa/Nairobi — computed at submission and stored
writes: status → pending_approval; submitted_by = actor; submitted_at = now(); is_backdated
audit_event: compensation.plan.submitted (warn; includes is_backdated + impact preview reference)
failure_codes: 422 invalid_state_transition, 422 validation, 403
tests: submit freezes terms (subsequent update_draft rejected); backdated submission sets is_backdated;
  non-backdated submission does not; a user holding BOTH submit and approve cannot later approve
  their own submission (maker/checker denial)
```

### approve — pending_approval → active | scheduled
```text
actor: HR (approver) | permission: compensation.plan.approve | class: branch_mutation | billing_read_only: block
step_up: REQUIRED (fresh) | severity: high — or CRITICAL when is_backdated (F8, Plan §59)
maker_checker: INCOMPATIBLE with compensation.plan.submit — approved_by MUST NOT equal submitted_by
  (enforced by policy AND the DB CHECK approved_by <> submitted_by)
preconditions: status = pending_approval; no overlapping active/scheduled window for the same
  (staff_profile_id, branch_id) — the DB EXCLUDE is the final arbiter
transaction_boundary: single transaction | rows_locked: advisory lock on (staff_profile_id, branch_id)
  to serialize overlap; row lock on the incumbent active plan
writes: approved_by = actor; approved_at = now();
  status → active (effective_from <= today, incl. backdated) OR scheduled (effective_from > today);
  IF an incumbent active plan exists for the same subject: incumbent.effective_to := this.effective_from
  (when open-ended) and incumbent.status → superseded; this.supersedes_plan_id := incumbent.id;
  a compensation_plan_history row is appended for BOTH rows in the SAME transaction
audit_event: compensation.plan.approved (high)
  + compensation.plan.backdated_change_approved (CRITICAL) when is_backdated — Plan §59
  + compensation.plan.superseded (high) on the incumbent when one was closed out
failure_codes: 409 compensation_plan_overlap, 422 invalid_state_transition, 403 (maker/checker or missing
  step-up), 404
tests: approve activates now; future effective_from → scheduled; backdated approval emits a CRITICAL audit
  event; approval without fresh step-up denied; submitter approving own plan denied (maker/checker);
  overlapping active/scheduled rejected by PG; concurrency cannot double-activate an overlap;
  supersede closes the incumbent adjacently (no overlap) and never rewrites its terms;
  rolled-back approval writes no success audit
```

### reject — pending_approval → rejected
```text
actor: HR (approver) | permission: compensation.plan.reject | class: branch_mutation | billing_read_only: block | severity: warn
maker_checker: the submitter should not reject their own submission (same separation as approve)
preconditions: status = pending_approval; change_reason non-empty
writes: status → rejected; rejected_by = actor; rejected_at = now()
audit_event: compensation.plan.rejected (warn; reason)
failure_codes: 422 invalid_state_transition, 403
tests: reject from pending_approval succeeds; reject of draft/active/terminal rejected; a rejected plan
  never resolves as effective; a rejected plan cannot be re-submitted (HR creates a new draft)
```

### cancel — draft | scheduled → cancelled
```text
actor: HR | permission: compensation.plan.cancel | class: branch_mutation | billing_read_only: block | severity: warn
preconditions: status ∈ {draft, scheduled} — NEVER active (an active plan is superseded, not cancelled);
  change_reason non-empty
writes: status → cancelled
audit_event: compensation.plan.cancelled (warn; reason)
failure_codes: 422 invalid_state_transition (cancel of pending_approval/active/terminal), 403
tests: cancel a draft; cancel a future scheduled plan; cancel of an ACTIVE plan rejected (must supersede);
  a cancelled plan never resolves and never blocks a new effective window
```

### activate — scheduled → active (boundary)
```text
actor: system boundary (ActivateScheduledCompensationPlan) | class: branch_mutation | severity: info
driver: effective_from reached. Resolution treats a plan whose daterange contains today as effective.
  A scheduled row becomes active at its effective-date boundary; monetary terms unchanged.
preconditions: status = scheduled; effective_from <= today (Africa/Nairobi)
writes: status → active. NO monetary/effective/subject field is touched (the DB immutability
  trigger permits the status-only change).
history: compensation_plan_history event `activated` (scheduled → active) — the symmetric partner
  of `expired`. Added by the Increment 3 correction; recording activation as `approved` would
  collapse two distinct lifecycle moments and omitting it would make activation invisible.
audit_event: compensation.plan.activated (info) — emitted when the boundary transition is applied.
failure_codes: 422 invalid_state_transition (activate of a non-scheduled plan), 422 (boundary not reached)
tests: a scheduled plan resolves only from effective_from; before that the incumbent still resolves;
  activation writes an `activated` history row; activating before the boundary is rejected;
  activating a draft/active/terminal plan is rejected
```

### expire — active → expired (boundary)
```text
driver: effective_to reached (resolution treats a plan whose daterange no longer contains today as
  not-effective). Monetary terms unchanged; terminal.
audit_event: compensation.plan.expired (info) — emitted when the boundary transition is applied.
tests: an expired plan does not resolve; resolution finds no effective plan (never a silent fallback)
```

## Resolution (read path — `ResolveEffectiveCompensationPlan`)
```text
given: staff_profile, branch, date (default today Africa/Nairobi)
effective plan = the `active` plan for (staff_profile_id, branch_id) whose daterange
  [effective_from, effective_to) contains the date.
  The DB EXCLUDE guarantees AT MOST ONE such row (Scope §12.9 hard rule).
none found → no effective compensation configuration (null) — NEVER a silent default.

This resolver returns CONFIGURATION ONLY. It computes no money, creates no row, and has no
side effects. Phase 20G consumes it to accrue salary and to earn commission at Finance
validation; Phase 20F never does.
```

## Backdated-change controls (F8; Plan §59)
```text
business date := CURRENT_DATE in Africa/Nairobi
backdated     := effective_from < business date

required: submission + approval (never silent) | mandatory change_reason | impact preview
          computed before submission | maker/checker separation | fresh step-up on approve
          | CRITICAL-severity audit on approval
blocked:  an unapproved backdated change can never reach active — enforced at the Form Request,
          the state machine, and the DB (status CHECK + approved_by/approved_at coherence).
```

`BuildCompensationImpactPreview` reports, for a backdated window, which **existing** business
facts fall inside it (counts only, from already-recorded Phase 16C/17/18B data). It **does not**
recalculate, reprice, or create anything — Plan §59 requires an "impact preview"; Plan §61 keeps
recalculation of already-earned commission out of Phase 20F entirely (Scope §12.7 3B: "existing
earned commissions are not recalculated unless HR explicitly applies a backdated correction
workflow" — that workflow is a **Phase 20G** adjustment, not a 20F edit).

## Notes
- Generic `PATCH status` does not exist; one route/action per transition.
- **`active` terms are immutable** — a source-scan/behaviour test asserts no action edits
  `compensation_model`, `salary_amount_minor`, `salary_currency`, `salary_period`,
  `salary_payout_day`, `commission_rule_id`, `effective_from`, `effective_to`, or the subject
  columns of a non-`draft` row; changes go through supersede. The `BEFORE UPDATE` trigger is the
  DB-authoritative guard.
- The DB `EXCLUDE USING gist` (over `active` + `scheduled`) is the authoritative overlap guard;
  the action pre-check + advisory lock only produce a friendly `409 compensation_plan_overlap`.
- `compensation_plan_history` is **append-only** (trigger-enforced) and is written in the **same
  transaction** as the transition that produced it. Its canonical event vocabulary is `created`,
  `updated_draft`, `submitted`, `approved`, **`activated`**, `rejected`, `cancelled`, `superseded`,
  `expired` — one per lifecycle moment, in parity with the DB CHECK and
  `CompensationPlanHistoryEvent` (`Phase20FEnumParityTest`).
- **Salary-only never earns commission** (Plan §59/§80; Scope §12.5): the DB model-shape CHECK
  makes `commission_rule_id` NULL for `salary_only`, so no rule can be resolved for that personnel.
  Phase 20G's earning path must honour it; Phase 20F proves the configuration invariant.
- Every transition has positive, invalid-transition, authorization (HR-only), maker/checker,
  step-up, overlap, concurrency, tenant/branch-isolation, and audit tests
  (`tests/Feature/Compensation`). Rolled-back actions write no success audit.
- **Nothing in this aggregate creates a financial fact.** No `salary_ledger`, `commission_ledger`,
  `compensation_adjustments`, payout run/item, or earnings statement is written, referenced, or
  implied. Commission is earned **only** at Finance validation in Phase 20G (Plan §61), consuming
  the merged Phase 18B `commission_handoff_events` seam — which Phase 20F does not modify.
