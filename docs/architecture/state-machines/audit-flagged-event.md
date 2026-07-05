# Audit Flagged Event — State Machine (Plan §13.2, §25, §80; Phase 19)

> Authoritative review lifecycle for `audit_flagged_events`. Every status change goes
> through a named action (`FlagAuditEvent`, `StartFlaggedEventReview`, `ResolveFlaggedEvent`,
> `DismissFlaggedEvent`, `ReopenFlaggedEvent`) — **no** generic status endpoint and **no**
> controller sets a status directly. Invalid transitions return
> `422 invalid_state_transition`. Mirrors the `audit_flagged_events_status_check` DB CHECK
> and `AuditFlaggedEventStatus`. Only review **metadata** changes — the linked
> `audit_logs` row is append-only and hash-chain protected (ADR-008) and is never mutated.

## States

| State | Owner | Meaning |
|---|---|---|
| `open` | Audit | A newly flagged audit event awaiting review. |
| `under_review` | Audit (assignee) | Assigned to a reviewer and being investigated. Requires `assigned_to`. |
| `resolved` | Audit | Investigated and closed with a substantive outcome. Requires `resolved_by` + `review_notes`. |
| `dismissed` | Audit | Reviewed and closed as benign / not actionable. Requires `resolved_by` + `review_notes`. |
| `reopened` | Audit | A previously resolved/dismissed flag re-opened for further review. |

## Transitions

```text
(create)      -> open           [FlagAuditEvent]           (audit.flagged_event.create; over one branch-scoped audit row)
open          -> under_review   [StartFlaggedEventReview]  (audit.flagged_event.update_status; sets assigned_to)
reopened      -> under_review   [StartFlaggedEventReview]  (audit.flagged_event.update_status; sets assigned_to)
under_review  -> resolved       [ResolveFlaggedEvent]      (audit.flagged_event.resolve_metadata; resolved_by + notes)
under_review  -> dismissed      [DismissFlaggedEvent]      (audit.flagged_event.resolve_metadata; resolved_by + notes)
resolved      -> reopened       [ReopenFlaggedEvent]       (audit.flagged_event.update_status)
dismissed     -> reopened       [ReopenFlaggedEvent]       (audit.flagged_event.update_status)
```

Any other transition is invalid → `422 invalid_state_transition`.

## Per-transition contract (Plan §25 format)

| Transition | Actor / permission | Tenant/branch | Validation | Txn / lock | Writes | Audit event | Failure |
|---|---|---|---|---|---|---|---|
| `FlagAuditEvent` → `open` | Audit / `audit.flagged_event.create` | flag inherits the audited row's merchant+branch; only branch-scoped audit rows are flaggable | audit row exists in tenant scope; optional initial note | txn; lock the audit row (read) | insert flag (`open`, `created_by`) | `AuditEventFlagged` (warning) | foreign row → 404; non-branch-scoped → 422 |
| `StartFlaggedEventReview` → `under_review` | Audit / `audit.flagged_event.update_status` | same branch | current status ∈ {`open`,`reopened`} | txn; `lockForUpdate` the flag | `status`, `assigned_to` | `AuditFlaggedReviewStarted` (notice) | invalid state → 422 |
| `ResolveFlaggedEvent` → `resolved` | Audit / `audit.flagged_event.resolve_metadata` | same branch | status = `under_review`; `review_notes` required | txn; `lockForUpdate` | `status`, `resolved_by`, `review_notes` | `AuditFlaggedResolved` (notice) | invalid state → 422; missing notes → 422 |
| `DismissFlaggedEvent` → `dismissed` | Audit / `audit.flagged_event.resolve_metadata` | same branch | status = `under_review`; `review_notes` required | txn; `lockForUpdate` | `status`, `resolved_by`, `review_notes` | `AuditFlaggedDismissed` (notice) | invalid state → 422; missing notes → 422 |
| `ReopenFlaggedEvent` → `reopened` | Audit / `audit.flagged_event.update_status` | same branch | current status ∈ {`resolved`,`dismissed`} | txn; `lockForUpdate` | `status`; clears `assigned_to`/`resolved_by`/`review_notes` (who/when preserved in the audit trail) | `AuditFlaggedReopened` (warning) | invalid state → 422 |

## Invariants

- The source `audit_logs` row is **never** mutated; `audit_log_id` is write-once and
  `ON DELETE RESTRICT`. No flag action writes to `audit_logs`, any operational/financial/
  identity record, or the permission tables.
- `under_review` requires `assigned_to` (DB assignment CHECK); `resolved`/`dismissed`
  require `resolved_by` + `review_notes` (DB resolution CHECK).
- No destructive delete and no soft-delete: a mistaken flag is `dismissed`, never removed.
- Cross-tenant access → non-enumerating `404`; same-tenant wrong-branch → the established
  branch-denial posture. ULIDs resolve **inside** tenant scope.
