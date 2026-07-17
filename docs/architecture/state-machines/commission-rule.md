# Commission Rule — State Machine (Plan §59, §80; Scope §12.7 Step 3A, §18.3; Phase 20F)

> Named mandatory state-machine specification (Plan §25.1). One named domain action
> per legal transition; `status` is **never** assigned directly — every change runs
> through its named action via `CommissionRuleStateMachine`, and any unlisted pair
> returns `422 invalid_state_transition`. There is **no** generic `PATCH status` route
> and **no** DELETE route (Scope §12.7 Step 3C: a previously active commission rule is
> "**ended, not deleted**"). Money is integer minor units (`Money`); timestamps are UTC;
> effective-date business logic is **`Africa/Nairobi`**.
>
> **Configuration only.** A commission rule declares *how commission will be computed
> later*. It computes **no** commission, creates **no** `commission_ledger` row, and
> triggers **no** payout. Earning happens **only** at Finance validation in **Phase 20G**
> (Plan §61).

Aggregate: `commission_rules` (**branch-owned**: `merchant_id` + `branch_id`). Per
**Scope §18.3** this is a **sibling** configuration record **referenced by**
`personnel_compensation_plans.commission_rule_id` — it is **not** a child of a plan and
**not** a ledger. Actor: **HR** (branch-scoped). The permission matrix declares **no**
`commission.rule.*` namespace, so a commission rule is governed by the **same
`compensation.plan.*` keys** as the plan that references it (F9):

| Action | Permission |
|---|---|
| create rule | `compensation.plan.create` |
| edit draft rule | `compensation.plan.update_draft` |
| approve/activate rule | `compensation.plan.approve` (fresh step-up; maker/checker vs `submit`) |
| end (supersede) rule | consequence of approving a successor — no separate key |
| view rule | `compensation.plan.view` |

## States (mirror the DB CHECK)

```text
draft             editable definition; not yet effective; terms may be revised in place
pending_approval  submitted with its plan; terms frozen
scheduled         approved, future effective_from; participates in overlap exclusion
active            currently effective; monetary terms IMMUTABLE (change = supersede)
superseded        terminal; ENDED and replaced by a newer version; retained for history/audit
expired           terminal; effective_to reached
rejected          terminal; approver rejected
cancelled         terminal; a draft/scheduled rule withdrawn before it ever took effect
```

## Transition inventory (authoritative arrow set)

```text
(none)            → draft             create rule (percentage or fixed)
draft             → draft             update draft terms in place (the only in-place edit)
draft             → pending_approval  submit (with the referencing plan)
draft             → cancelled         cancel before effect
pending_approval  → active            approve; effective_from <= today
pending_approval  → scheduled         approve; effective_from > today
pending_approval  → rejected          reject
scheduled         → active            effective_from reached (activation boundary)
scheduled         → cancelled         cancel before effect
active            → superseded        ENDED by a successor version (never deleted)
active            → expired           effective_to reached
```

No unlisted transition is allowed. `superseded`, `expired`, `rejected`, `cancelled` are
terminal. Every other pair is invalid → `422 invalid_state_transition`.

---

### create — (none) → draft
```text
actor: HR | permission: compensation.plan.create | class: branch_mutation | billing_read_only: block | severity: warn
input_validation (F4/F5/F6): calculation_type ∈ (percentage, fixed_amount);
  percentage ⇒ percentage_basis_points (0..10000) present AND fixed_amount_minor/currency NULL;
  fixed_amount ⇒ fixed_amount_minor (>= 0) + currency (uppercase ISO-3) present AND percentage_basis_points NULL;
  calculation_basis ∈ (service_price, invoice_item_total, paid_amount, net_after_discount);
  applies_to ∈ (all_services, selected_services, service_category);
  applies_to = service_category ⇒ service_category_id present (resolved INSIDE tenant+branch scope);
  applies_to ∈ (all_services, selected_services) ⇒ service_category_id NULL;
  applies_to_preferred_personnel_fee boolean (default false);
  effective_from (date); effective_to nullable (> effective_from); change_reason non-empty.
  NO merchant_id/branch_id/status/approved_by/approved_at accepted (server-owned).
writes: rule(status=draft, created_by=actor, merchant_id/branch_id from context)
audit_event: commission_rule.created (warn)
failure_codes: 422 validation, 403, 404 (service category outside branch scope)
tests: percentage valid; fixed valid; percentage + fixed/currency rejected; fixed + basis points rejected;
  over-range basis points (>10000) rejected; negative basis points rejected; negative fixed amount rejected;
  float value rejected; service_category without service_category_id rejected; all_services WITH
  service_category_id rejected; lowercase currency rejected; foreign service_category ULID → 404; non-HR denied
```

### update_draft — draft → draft
```text
actor: HR | permission: compensation.plan.update_draft | class: branch_mutation | billing_read_only: block | severity: info
preconditions: status = draft (the ONLY editable state — F7)
input_validation: as create; change_reason non-empty
writes: the draft's terms in place
audit_event: commission_rule.updated_draft (info)
failure_codes: 422 invalid_state_transition (edit of active/terminal), 403, 404
tests: draft edit succeeds; editing an ACTIVE rule rejected by the state machine (F7 supersede-not-edit);
  editing a superseded/expired/cancelled rule rejected; value-shape re-validated on every edit
```

### approve/activate — pending_approval → active | scheduled
```text
actor: HR (approver) | permission: compensation.plan.approve | class: branch_mutation | billing_read_only: block
step_up: REQUIRED (fresh) | severity: high | maker_checker: INCOMPATIBLE with compensation.plan.submit
preconditions: status = pending_approval
transaction_boundary: single transaction (the SAME transaction that approves the referencing plan)
writes: approved_by = actor; approved_at = now();
  status → active (effective_from <= today) OR scheduled (effective_from > today)
audit_event: commission_rule.approved (high; terms, effective range)
failure_codes: 422 invalid_state_transition, 403 (maker/checker or missing step-up), 404
tests: approve activates now; future effective_from → scheduled; approval without fresh step-up denied;
  submitter approving own rule denied
```

### end — active → superseded (ENDED, never deleted)
```text
driver: a successor rule version activates, or the referencing plan switches to salary_only.
actor: HR | permission: compensation.plan.approve (the activation that causes it) | severity: high
transaction_boundary: single transaction with the successor's activation
writes: the ended rule's effective_to := the successor's effective_from (when open-ended);
  status → superseded. Its monetary terms are UNCHANGED.
audit_event: commission_rule.ended (high; old ULID → new ULID or null, before/after terms, reason)
failure_codes: 422 invalid_state_transition, 403
tests: switching a plan to salary_only ENDS the previously active rule and does NOT delete it
  (Scope §12.7 Step 3C — named behaviour); the ended rule is still readable in history;
  the ended rule never resolves after its effective_to; its terms are byte-identical after ending
```

### cancel — draft | scheduled → cancelled
```text
actor: HR | permission: compensation.plan.cancel | class: branch_mutation | billing_read_only: block | severity: warn
preconditions: status ∈ {draft, scheduled} — NEVER active (an active rule is ended/superseded, not cancelled)
writes: status → cancelled
audit_event: commission_rule.cancelled (warn; reason)
failure_codes: 422 invalid_state_transition, 403
tests: cancel a draft; cancel a future scheduled rule; cancel of an ACTIVE rule rejected;
  a cancelled rule never resolves
```

### activate — scheduled → active (boundary)
```text
actor: system boundary (ActivateScheduledCompensationPlan, same transaction as its plan) | severity: info
driver: effective_from reached. A scheduled rule becomes active at its effective-date boundary;
  monetary terms unchanged (status-only change; the DB immutability trigger permits it).
audit_event: commission_rule.activated (info)
tests: a scheduled rule resolves only from effective_from; activation is driven by the referencing
  plan's activation, never independently
```

### expire — active → expired (boundary)
```text
driver: effective_to reached. Monetary terms unchanged; terminal.
audit_event: commission_rule.expired (info)
tests: an expired rule does not resolve
```

## Lifecycle linkage — a rule NEVER transitions independently (Increment 3, binding)

Per the actions above (`submit` is "with the referencing plan"; `approve` runs in "the SAME
transaction that approves the referencing plan"), a commission rule has **no independent lifecycle
action**. Every non-draft transition is driven by the plan that references it, inside that plan's
transaction:

| Plan action | Effect on `plan.commissionRule` |
|---|---|
| `CreateCompensationPlanDraft` | rule created/attached as `draft` |
| `UpdateCompensationPlanDraft` | draft rule terms updated in place |
| `SubmitCompensationPlan` | `draft → pending_approval` |
| `ApproveCompensationPlan` | `pending_approval → active`/`scheduled` (target from the RULE's own `effective_from`) |
| `ActivateScheduledCompensationPlan` | `scheduled → active` |
| `RejectCompensationPlan` | `pending_approval → rejected` |
| `CancelCompensationPlan` | `draft`/`scheduled → cancelled` |
| `ApproveCompensationPlan` (supersede) | incumbent's rule `active → superseded` (**ended**, window closed) |

**Sharing guard.** A rule row may be referenced by more than one plan (`commission_rules` is a
sibling table, not a child). A plan-driven transition therefore applies to its rule **only when the
rule is not still referenced by another non-terminal plan** — ending a rule that another live plan
still depends on would break that plan's F1 model shape. `salary_only` plans reference no rule at
all, so nothing is transitioned for them.

**Value-shape guard.** A rule is never activated into a state that would break the referencing
plan's required shape: the DB model-shape CHECK keeps `commission_rule_id` NULL for `salary_only`,
and `commission_only`/`salary_plus_commission` fail closed if their rule is missing or not active
(`effective_commission_rule_missing`).

## Resolution (read path — `ResolveEffectiveCommissionRule`)
```text
given: compensation plan (or staff_profile + branch), date (default today Africa/Nairobi)
effective rule = the `active` rule referenced by the effective plan's commission_rule_id whose
  daterange [effective_from, effective_to) contains the date.
salary_only plan → commission_rule_id is NULL by DB CHECK ⇒ NO rule, ALWAYS (Plan §80 named test:
  "salary-only has no commission rule").
none found → no effective commission configuration (null) — NEVER a silent default.

This resolver returns CONFIGURATION ONLY. It computes no commission, allocates nothing, and creates
no row. Phase 20G resolves the rule effective on the configured business event date and computes
round_half_up(basis_minor * percentage_basis_points / 10000) per ADR-005 — Phase 20F never does.
```

## Preferred-personnel-fee applicability (F6; Plan §59, Scope §969)
```text
applies_to_preferred_personnel_fee = true  → the Phase 20A preferred-personnel fee IS INCLUDED
                                             in the future commission BASIS
applies_to_preferred_personnel_fee = false → it is EXCLUDED from the future commission BASIS (default)
```

It is a **basis-inclusion flag** only — **not** a separate commission basis, **not** a rate
modifier, **not** a payout trigger, **not** an earned-commission row. Scope §969: "Personnel see
the job as preferred and see related earnings only if HR's commission rule **includes the
preferred-personnel fee in the commission basis**." **Phase 20G** consumes the flag when earning
commission against the Phase 20A `preferred_personnel_fee_rules` substrate. Phase 20F stores it and
proves it round-trips; it never applies it to money.

## Notes
- Generic `PATCH status` does not exist; one route/action per transition. **No DELETE route** —
  ending a rule is a status transition that preserves the row and its terms.
- **`active` terms are immutable** — a source-scan/behaviour test asserts no action edits
  `calculation_type`, `percentage_basis_points`, `fixed_amount_minor`, `currency`,
  `calculation_basis`, `applies_to`, `service_category_id`, `applies_to_preferred_personnel_fee`,
  `effective_from`, or `effective_to` of a non-`draft` row.
- **F4 residual:** Scope §12.7 mentions a "configured merchant/platform maximum" commission
  percentage; no such configuration exists in the repository/Plan/Scope and Plan §59 does not
  require one, so `0..10000` bp (0–100%) is the enforced structural ceiling — the merged
  `preferred_personnel_fee_rules_basis_points_range_check` precedent. See `docs/proof/phase-20f.md` §F4.
- Every transition has positive, invalid-transition, authorization (HR-only), maker/checker,
  step-up, tenant/branch-isolation, and audit tests (`tests/Feature/Compensation`). Rolled-back
  actions write no success audit.
- **Nothing in this aggregate creates a financial fact.** Companion spec:
  `docs/architecture/state-machines/personnel-compensation-plan.md`.
