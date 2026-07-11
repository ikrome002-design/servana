# Preferred-Personnel Fee Rule — State Machine (Plan §13.10, §47; Phase 20A)

> Named mandatory state-machine specification (Plan §25.1). One named domain action
> per legal transition; `status` is **never** assigned directly — every change runs
> through its named action via `PreferredPersonnelFeeRuleStateMachine`, and any
> unlisted pair returns `422 invalid_state_transition`. There is **no** generic
> `PATCH status` route. Money is integer minor units (`Money`); timestamps are UTC;
> effective-date business logic is `Africa/Nairobi`. Percentage arithmetic uses
> round-half-up to integer minor units (ADR-005).

Aggregate: `preferred_personnel_fee_rules` (platform-scoped; no `merchant_id`/
`branch_id`). Actor: **Super Administrator** only
(`platform.preferred_personnel_fee.manage`; `platform_mutation`; mandatory MFA;
step-up on approve/supersede/cancel; `change_reason` required). Branch users get a
**read-only** view of the effective rule for their branch context
(`preferred_personnel_fee.view_branch_rule`) — no mutation authority. Merchant
Admin, HR, Finance, Front Office, Personnel, Audit receive **no** management
authority.

## States (mirror the DB CHECK)

```text
draft        editable definition; not yet effective; monetary terms may be revised in place
scheduled    approved, future effective_from; participates in overlap exclusion
active       currently effective; monetary terms IMMUTABLE (change = supersede)
superseded   terminal; replaced by a newer version; retained for history/audit
expired      terminal; effective_to reached (system/scheduler-driven boundary)
cancelled    terminal; a draft/scheduled rule withdrawn before it ever took effect
```

## Transition inventory (authoritative arrow set)

```text
(none)      → draft            create rule (fixed or percentage)
draft       → scheduled        approve/activate with a future effective_from
draft       → active           approve/activate effective now
draft       → cancelled        cancel before effect
scheduled   → active           reached effective_from (activation)
scheduled   → cancelled        cancel before effect
active      → superseded       a new version supersedes the current active rule
active      → expired          effective_to reached
```

No unlisted transition is allowed. `superseded`, `expired`, `cancelled` are
terminal. Every other pair is invalid → `422 invalid_state_transition`.

---

### create — (none) → draft
```text
actor: Super Admin | permission: platform.preferred_personnel_fee.manage | class: platform_mutation | MFA: mandatory
input_validation: calculation_type; fixed ⇒ fixed_amount_minor(>=0)+currency(upper) & null bp; percentage ⇒ percentage_basis_points(0..10000) & null fixed/currency; calculation_basis; scope; scope=service ⇒ service_id present, scope=platform_default ⇒ service_id null; effective_from (date); effective_to nullable (> effective_from); change_reason non-empty. NO merchant_id/branch_id/status accepted.
writes: rule(status=draft, created_by=actor)
audit_event: preferred_personnel_fee_rule.created (info)
failure_codes: 422 validation, 403, 404 (service outside platform scope)
tests: fixed valid; percentage valid; fixed+bp rejected; percentage+fixed/currency rejected; platform_default+service_id rejected; service without service_id rejected; over-range bp rejected; non-Super-Admin denied
```

### approve/activate — draft|scheduled → active ; draft → scheduled
```text
actor: Super Admin | permission: platform.preferred_personnel_fee.manage | class: platform_mutation | MFA: mandatory | step_up: required | severity: high
transaction_boundary: single transaction | rows_locked: advisory lock on (scope, service_id) to serialize overlap
preconditions: status ∈ {draft, scheduled}; no overlapping active/scheduled range for the same scope(+service) — DB EXCLUDE is the final arbiter
writes: approved_by=actor; approved_at=now(); status → active (effective_from<=today) OR scheduled (effective_from>today)
audit_event: preferred_personnel_fee_rule.approved (high; scope, service ULID, terms, effective range)
failure_codes: 409 preferred_fee_rule_overlap, 422 invalid_state_transition, 403, 404
tests: approve activates now; future effective_from → scheduled; overlapping active/scheduled rejected by PG; maker/checker per policy where required; concurrency cannot double-activate an overlap
```

### supersede — active → superseded (+ new draft/scheduled/active version)
```text
actor: Super Admin | permission: platform.preferred_personnel_fee.manage | class: platform_mutation | MFA: mandatory | step_up: required | severity: high
input_validation: new terms (as create) + change_reason; NO in-place edit of the active row's monetary terms
transaction_boundary: single transaction | rows_locked: advisory lock on (scope, service_id)
writes: current active row status → superseded; NEW row created as the successor (draft/scheduled/active per effective_from); the superseded row's monetary terms are UNCHANGED
audit_event: preferred_personnel_fee_rule.superseded (high; old ULID → new ULID, before/after terms, reason)
failure_codes: 409 preferred_fee_rule_overlap, 422 invalid_state_transition, 403, 404
tests: active terms immutable (edit attempt is not an action / rejected); supersede creates a new version and marks the old superseded; history preserved; overlap still rejected
```

### cancel — draft|scheduled → cancelled
```text
actor: Super Admin | permission: platform.preferred_personnel_fee.manage | class: platform_mutation | MFA: mandatory | step_up: required | severity: high
preconditions: status ∈ {draft, scheduled} (never active — active is superseded, not cancelled)
writes: status → cancelled
audit_event: preferred_personnel_fee_rule.cancelled (high; reason)
failure_codes: 422 invalid_state_transition (cancel of active/terminal), 403, 404
tests: cancel a future scheduled rule; cancel of an active rule rejected (must supersede); cancelled rule never resolves
```

### expire — active → expired (boundary)
```text
driver: effective_to reached (resolution treats a rule whose daterange no longer contains today as not-effective). A row is marked expired by the effective-date boundary; monetary terms unchanged; terminal.
audit_event: preferred_personnel_fee_rule.expired (info) — emitted when the boundary transition is applied.
tests: an expired rule does not resolve; resolution falls back to platform_default or none
```

## Resolution (read path — `ResolveEffectivePreferredPersonnelFee`)
```text
given: service, finalization date (default today Africa/Nairobi), item net/gross basis
effective rule = the `active` rule whose daterange [effective_from, effective_to) contains the date,
  preferring scope=service (matching service_id) over scope=platform_default.
fixed      → amount = fixed_amount_minor
percentage → amount = round_half_up(basis_minor * percentage_basis_points / 10000)  (ADR-005)
none found → no fee (null)
The resolved amount is snapshotted onto the invoice item/header at finalization and is permanent —
a later rule change NEVER recalculates an issued invoice.
```

## Notes
- Generic `PATCH status` does not exist; one route/action per transition.
- `active` monetary terms are immutable — a source-scan/behaviour test asserts no
  action edits `fixed_amount_minor`/`percentage_basis_points`/`currency` of an
  `active` row; changes go through supersede.
- The DB `EXCLUDE USING gist` (over `active`+`scheduled`) is the authoritative
  overlap guard; the action pre-check + advisory lock only produce a friendly 409.
- Legacy backfill seeds `fixed_amount`/`service`/`active` rules whose amounts equal
  `services.preferred_personnel_fee_minor` exactly; cutover is the immutable
  product-owner-fixed date `DATE '2026-07-10'` (never `now()`/`today()`/
  `CURRENT_DATE`). See `docs/architecture/data-dictionary/billing-and-wallet.md`.
- Every transition has positive, invalid-transition, authorization (Super-Admin
  only), MFA/step-up, overlap, concurrency, and audit tests
  (`tests/Feature/Billing`). Rolled-back actions write no success audit.
