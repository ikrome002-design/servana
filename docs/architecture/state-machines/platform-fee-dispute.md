# Platform-Fee Dispute — State Machine (Plan §13.10 [Correction 3]; Phase 20E)

> Named mandatory state-machine specification (Plan §25.1). One named domain action per legal transition;
> `status` is **never** assigned directly — every change runs through its named action via
> `PlatformFeeDisputeStateMachine`; any unlisted pair returns `422 invalid_state_transition`. No generic
> `PATCH status` route. A dispute resolution that changes money creates a `platform_fee_adjustments` row
> and **never edits the original `platform_fee_ledger_entries` amount** (Plan §953).

Aggregate: `platform_fee_disputes` (tenant-owned `merchant_id`, nullable `branch_id`). A dispute targets a
`platform_fee_ledger_entry_id` (nullable) or a `subscription_invoice_id` (nullable), at least one present,
both within the actor's tenant/scope.

## States (mirror the DB CHECK)

```text
open           raised; awaiting review assignment
under_review   assigned to a reviewer; evidence being assessed
resolved       terminal; upheld/partially-upheld — a money change (if any) is a platform_fee_adjustment
rejected       terminal; dispute declined; no money change
```

The Plan §13.10 CHECK intentionally narrows the set to `{open, under_review, resolved, rejected}`. An
older Scope paragraph mentions `escalated`; it is **not** implemented (the active Plan narrowed it).
`resolved`/`rejected` are terminal.

## Transition inventory (authoritative arrow set)

```text
(none)        → open            CreatePlatformFeeDispute
open          → under_review    StartPlatformFeeDisputeReview
under_review  → resolved        ResolvePlatformFeeDispute (money change ⇒ platform_fee_adjustment)
under_review  → rejected        RejectPlatformFeeDispute
open          → rejected        RejectPlatformFeeDispute (declined before review)
```

No unlisted transition. `resolved`, `rejected` terminal. Every other pair → `422 invalid_state_transition`.

---

### create — (none) → open  (`CreatePlatformFeeDispute`)
```text
actor: Merchant Admin | Finance | permission: platform_fee.dispute (canonical §19.2/§19.3, Phase 20E; reconciled from legacy platform_fees.dispute) | class: tenant_mutation | billing_read_only: block
input_validation: reason required (sanitized); exactly-one-or-both of platform_fee_ledger_entry_id / subscription_invoice_id, each within the actor's tenant/branch scope; evidence file (optional) via the private file domain
writes: dispute(status=open, created_by=actor, merchant_id, branch_id)
audit_event: platform_fee.dispute_created (warn; safe target ULID, reason)
failure_codes: 422 validation, 403, 404 (cross-tenant source → not-found)
tests: permitted actor creates; reason required; cross-tenant source denied (404); Audit/Front-Office/Personnel denied; evidence stored privately
```

### start review — open → under_review  (`StartPlatformFeeDisputeReview`)
```text
actor: Finance (authorized reviewer) | permission: platform_fee.dispute.review | class: tenant_mutation | bodiless (validation-exempt)
writes: assigned_reviewer=actor; status → under_review
audit_event: platform_fee.dispute_review_started (info)
failure_codes: 422 invalid_state_transition (review of terminal), 403
tests: authorized reviewer starts; unauthorized denied; review of resolved/rejected rejected
```

### resolve — under_review → resolved  (`ResolvePlatformFeeDispute`)
```text
actor: Finance | permission: platform_fee.dispute.review | class: financial_mutation | step_up: required (PlatformFeeDisputeResolution) | idempotency: required | maker_checker: creator cannot self-resolve | billing_read_only: block | period_lock: enforce
input_validation: resolution_note required; money change (if any) is a server-validated amount → creates platform_fee_adjustments (never edits the ledger amount)
transaction_boundary: single tx | rows_locked: dispute + target entry
writes: status → resolved; resolved_by=actor; resolved_at=now(); IF money change ⇒ RecordPlatformFeeAdjustment (additive)
audit_event: platform_fee.dispute_resolved (Warning; a money-changing resolution records the linked platform_fee_adjustment ULID + amount in context — the original ledger fact is never rewritten, so the base severity stays warning rather than a separate critical event; Increment 5C)
failure_codes: 422 invalid_state_transition, 403, 409 (period locked), 422 (browser-supplied resolved amount without server validation)
tests: resolve with money change creates adjustment; original ledger amount unchanged; resolution_note required; self-resolve blocked where maker/checker; period-lock blocks; step-up enforced; rollback ⇒ no success audit
```

### reject — {open, under_review} → rejected  (`RejectPlatformFeeDispute`)
```text
actor: Finance | permission: platform_fee.dispute.review | class: tenant_mutation | step_up: required (PlatformFeeDisputeResolution)
input_validation: resolution_note (rejection reason) required
writes: status → rejected; resolved_by=actor; resolved_at=now(); NO money change
audit_event: platform_fee.dispute_rejected (warn; reason)
failure_codes: 422 invalid_state_transition (reject of terminal), 403
tests: reject from open; reject from under_review; reason required; reject of terminal rejected; no adjustment created
```

## Notes
- No free-form ledger amount editing, no direct delete, no status skipping, no cross-merchant references,
  no browser-supplied resolved amount without server validation, no Audit mutation, no Front-Office
  dispute control.
- Evidence uses the existing private file domain (authorization + masking per role); evidence contents are
  never logged.
- Period-lock policy: money-changing resolution is blocked when the target financial period is locked
  (same guard as refunds/adjustments).
- Every transition has positive, invalid-transition, authorization, scope-isolation, period-lock,
  maker/checker, and audit tests. Rolled-back actions write no success audit.
