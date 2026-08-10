# State machine — Platform feature-flag change request

**Table:** `platform_feature_flag_change_requests` · **Column:** `status` · **Enum:**
`App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagChangeRequestStatus` · **Guard:**
`App\Domain\PlatformFeatureFlags\Services\PlatformFeatureFlagChangeRequestStateMachine` ·
**DB backstop:** `platform_feature_flag_change_requests_status_check`, the maker/checker CHECK, the
four consistency CHECKs and the partial unique index `…_pending_unique`

Plan §9 rule 7, §18, §19.3, §70; ADR-008;
[`COR-UI08-001`](../../decisions/cor-ui08-001-super-administrator-backend-enablement.md) §12.3;
Phase UI-08.

## States

| State | Meaning |
|---|---|
| `pending` | Awaiting a second administrator. At most one per flag (partial unique index). |
| `approved` | A different administrator approved it. Not yet applied. |
| `rejected` | Declined, with a mandatory decision note. **Terminal.** |
| `cancelled` | Withdrawn by the requester before a decision. **Terminal.** |
| `applied` | The approved configuration was written to the flag inside one transaction. **Terminal.** |
| `failed` | Application aborted; the flag was left unchanged, with a mandatory `failure_reason`. **Terminal.** |

## Transitions

```
(request) ─► pending ─┬─► approved ─┬─► applied  (terminal)
                      │             └─► failed   (terminal)
                      ├─► rejected  (terminal)
                      └─► cancelled (terminal)
```

| From | To | Action | Controls |
|---|---|---|---|
| — | `pending` | `RequestFeatureFlagChange` | `platform.settings.update`, MFA, fresh `platform_feature_flag_change` step-up, idempotency, **mandatory** impact statement, rollback plan, health criterion and reason |
| `pending` | `approved` | `ApproveFeatureFlagChange` | same controls, **and the approver must differ from the requester** |
| `pending` | `rejected` | `RejectFeatureFlagChange` | same controls + mandatory decision note |
| `pending` | `cancelled` | `CancelFeatureFlagChange` | the requester only |
| `approved` | `applied` | applied inside the approve transaction | writes the flag, bumps `version`, stores `approved_configuration_hash`, appends history |
| `approved` | `failed` | application aborted | mandatory `failure_reason`; the flag is untouched |

## Maker/checker is a database constraint, not a convention

```sql
CHECK (approved_by_user_id IS NULL OR approved_by_user_id <> requested_by_user_id)
```

A self-approved change **cannot exist as a row**. Even a bypassed policy, controller and service
layer could not persist one. `Ui08FeatureFlagMakerCheckerTest` proves the constraint fires at the
database as well as at the policy.

## Mandatory governance fields

`impact_statement`, `rollback_plan`, `health_criterion` and `reason` are `NOT NULL` on the table and
required by the Form Request. A production-sensitive change with no stated impact or no rollback
plan is unrepresentable, not merely discouraged.

## Configuration hashing

`proposed_configuration_hash` is the SHA-256 of the canonical serialization of
`proposed_configuration`. On application, the same hash is copied to
`platform_feature_flags.approved_configuration_hash` and recorded as `after_hash` in
`platform_feature_flag_history`, so "what exactly was approved, and is it what is live?" is
answerable from the record rather than inferred.

## History is append-only

Every transition appends one `platform_feature_flag_history` row carrying before/after
configuration, before/after hashes, actor, reason and a correlation ULID linking request → decision
→ application. The `platform_feature_flag_history_append_only` trigger raises on UPDATE and DELETE,
giving it the same guarantee `audit_logs` has (guardrail 5).

## Audit

`platform.feature_flag.change_requested` · `.change_approved` · `.change_rejected` ·
`.change_cancelled` · `.applied` · `.paused`. Severity `crit` for an applied production change;
`high` otherwise.
