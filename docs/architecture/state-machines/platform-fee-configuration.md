# Platform-Fee Configuration — State Machine (Plan §13.10, §51, §52; Phase 20E)

> Named mandatory state-machine specification (Plan §25.1). One named domain action per legal
> transition; `status` is **never** assigned directly — every change runs through its named action via
> `PlatformFeeConfigurationStateMachine`, and any unlisted pair returns `422 invalid_state_transition`.
> There is **no** generic `PATCH status` route. Money is integer minor units (`Money`); timestamps UTC;
> effective-date business logic is `Africa/Nairobi`; percentage arithmetic is round-half-up to integer
> minor units (ADR-005). Mirrors and reuses the Phase 20A effective-dated platform-configuration
> conventions (see `preferred-personnel-fee-rule.md`, `platform-billing-settings.md`).

Aggregate: `platform_fee_configurations` (platform-scoped; no `merchant_id`/`branch_id`). Actor: **Super
Administrator** only (`platform.platform_fee.configure`; `platform_mutation`; mandatory MFA; fresh step-up
on approve/supersede/cancel; `change_reason` required). All merchant/branch roles (Merchant-Admin, HR,
Finance, Front-Office, Personnel, Branch-Manager, Audit) receive **no** management authority — they only
experience the resolved result.

## States (mirror the DB CHECK)

```text
draft        editable definition; not yet effective; monetary terms may be revised in place
scheduled    approved, future effective_from; participates in overlap exclusion
active       currently effective; monetary terms IMMUTABLE (change = supersede)
superseded   terminal; replaced by a newer version; retained for history/audit
cancelled    terminal; a draft/scheduled config withdrawn before it ever took effect
```

## Transition inventory (authoritative arrow set)

```text
(none)      → draft            create configuration
draft       → draft            edit draft monetary terms in place (same action, pre-approval only)
draft       → scheduled        approve with a future effective_from
draft       → active           approve effective now
draft       → cancelled        cancel before effect
scheduled   → active           reached effective_from (activation boundary)
scheduled   → cancelled        cancel before effect
active      → superseded       a new version supersedes the current active config
```

No unlisted transition is allowed. `superseded`, `cancelled` are terminal. `active` has no `expired`
boundary (percentage configuration remains the effective version until superseded); an `effective_to` in
the past simply makes it non-effective at resolution. Every other pair → `422 invalid_state_transition`.

---

### create — (none) → draft  (`CreatePlatformFeeConfiguration`)
```text
actor: Super Admin | permission: platform.platform_fee.configure | class: platform_mutation | MFA: mandatory
input_validation: billing_mode; percentage component ⇒ percentage_basis_points (0..10000) required; fixed_amount_plus_percentage ⇒ fixed_component_minor(>=0) present; tier_behavior ∈ {customer_centric,shared,business_centric} required for percentage modes; shared ⇒ shared_split_basis_points (0..10000) present; fee_basis_type ∈ E2 vocabulary; currency char(3) upper; effective_from (date); effective_to nullable (> effective_from); change_reason non-empty. NO merchant_id/branch_id/status/approved_by accepted.
writes: configuration(status=draft, created_by=actor)
audit_event: platform_fee.configuration_created (info)
failure_codes: 422 validation, 403
tests: percentage valid; fixed_plus_percentage valid; fixed-only config rejected as unnecessary (or allowed inert per spec); shared without split rejected; over-range bps rejected; missing tier in percentage mode rejected; non-Super-Admin denied
```

### edit draft — draft → draft  (`UpdatePlatformFeeConfigurationDraft`)
```text
actor: Super Admin | permission: platform.platform_fee.configure | class: platform_mutation | MFA: mandatory
preconditions: status = draft ONLY (never active/scheduled/terminal)
writes: revised monetary terms in place; status stays draft
audit_event: platform_fee.configuration_updated (info; before/after)
failure_codes: 422 invalid_state_transition (edit of non-draft), 403
tests: draft editable; edit of active/scheduled rejected (must supersede)
```

### approve — draft → active | scheduled  (`ApprovePlatformFeeConfiguration`)
```text
actor: Super Admin | permission: platform.platform_fee.configure | class: platform_mutation | MFA: mandatory | step_up: required | severity: critical
transaction_boundary: single transaction | rows_locked: advisory lock on the applicability boundary (billing_mode + currency) to serialize overlap
preconditions: status ∈ {draft, scheduled}; no overlapping active/scheduled window for the same applicability boundary — DB EXCLUDE is the final arbiter
writes: approved_by=actor; approved_at=now(); status → active (effective_from<=today) OR scheduled (effective_from>today); monetary terms now IMMUTABLE
audit_event: platform_fee.configuration_approved (critical; rate/basis/tier/split/currency, effective range)
failure_codes: 409 platform_fee_config_overlap, 422 invalid_state_transition, 403
tests: approve activates now; future effective_from → scheduled; overlapping active/scheduled rejected by PG; step-up enforced; concurrency cannot double-activate an overlap
```

### supersede — active → superseded (+ new successor version)  (`SupersedePlatformFeeConfiguration`)
```text
actor: Super Admin | permission: platform.platform_fee.configure | class: platform_mutation | MFA: mandatory | step_up: required | severity: critical
input_validation: new terms (as create) + change_reason; NO in-place edit of the active row's monetary terms
transaction_boundary: single transaction | rows_locked: advisory lock on the applicability boundary
writes: current active row status → superseded; NEW row created as successor (draft/scheduled/active per effective_from); the superseded row's monetary terms UNCHANGED
audit_event: platform_fee.configuration_superseded (critical; old ULID → new ULID, before/after terms, reason)
failure_codes: 409 platform_fee_config_overlap, 422 invalid_state_transition, 403
tests: active terms immutable (edit attempt rejected); supersede creates a new version + marks old superseded; history preserved; overlap still rejected; already-issued ledger entries reference the superseded snapshot unchanged
```

### cancel — draft | scheduled → cancelled  (`CancelPlatformFeeConfiguration`)
```text
actor: Super Admin | permission: platform.platform_fee.configure | class: platform_mutation | MFA: mandatory | step_up: required | severity: critical
preconditions: status ∈ {draft, scheduled} (never active — active is superseded, not cancelled)
writes: status → cancelled
audit_event: platform_fee.configuration_cancelled (critical; reason)
failure_codes: 422 invalid_state_transition (cancel of active/terminal), 403
tests: cancel a future scheduled config; cancel of an active config rejected (must supersede); cancelled config never resolves
```

## Resolution (read path — `ResolveEffectivePlatformFeeConfiguration`)
```text
given: billing_mode (from platform_billing_settings), currency, finalization/validation date (Africa/Nairobi)
effective config = the `active` config whose daterange [effective_from, effective_to) contains the date for the applicable boundary.
percentage mode + no effective active config → typed domain error (fail closed; no fee, finalization/validation blocked where a percentage component is required).
fixed_amount mode → NO config resolved (engine inert).
The resolved config (id, rate, fixed component, tier, split, basis, currency) is snapshotted onto the invoice at finalization and onto the ledger entry at validation; a later config change NEVER recalculates an issued invoice or entry.
```

## Notes
- Generic `PATCH status` does not exist; one route/action per transition.
- `active` monetary terms are immutable — a source-scan/behaviour test asserts no action edits
  `percentage_basis_points`/`fixed_component_minor`/`shared_split_basis_points`/`fee_basis_type`/
  `currency` of an `active` row; changes go through supersede.
- The DB `EXCLUDE USING gist` (over `active`+`scheduled` for the applicability boundary) is the
  authoritative overlap guard; the action pre-check + advisory lock only produce a friendly 409.
- No legacy backfill — Phase 20E ships no historical configurations; the engine is inert until a
  percentage component is configured. See `docs/architecture/data-dictionary/billing-and-wallet.md`.
