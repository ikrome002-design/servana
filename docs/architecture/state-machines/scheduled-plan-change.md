# Scheduled Plan Change — Status Machine (Plan §13.9, §48; Phase 20B)

> `scheduled_plan_changes.status` records a **no-proration, next-cycle** plan change.
> `status` is never assigned directly — changes run through named actions via
> `ScheduledPlanChangeStateMachine`; an unlisted pair returns `422
> invalid_state_transition`. Applied/cancelled rows are immutable. Actor: **Merchant
> Administrator** (`merchant.subscription.plan_change`; `merchant_mutation`).

## States (mirror the DB CHECK)

```text
scheduled   pending, will apply at effective_at (next cycle)
applied     effected at the cycle boundary (terminal)
cancelled   withdrawn before application (terminal)
```

## Transition inventory

```text
(none)     → scheduled    SchedulePlanChange
scheduled  → applied       ApplyScheduledPlanChange (at next-cycle boundary; exactly once)
scheduled  → cancelled     CancelScheduledPlanChange
```

`applied` and `cancelled` are terminal. Any other pair → `422 invalid_state_transition`.

---

### SchedulePlanChange — (none) → scheduled
```text
actor: Merchant Admin | permission: merchant.subscription.plan_change | class: merchant_mutation
input: target_plan_id, target_price_id (must belong to target plan), effective_at = next cycle boundary
guard: NO proration; at most one scheduled change per (subscription, effective_at) — partial unique
writes: scheduled_plan_change(status=scheduled, created_by)
audit: subscription.plan_change_scheduled
failure: 422 target price not on target plan; 409 duplicate scheduled change for the cycle; 403
tests: schedule at next cycle; second scheduled change for same cycle rejected; target consistency
```

### ApplyScheduledPlanChange — scheduled → applied
```text
actor: scheduler / system | class: financial_mutation | idempotent (row lock; applied-once)
writes: status=applied, applied_at; update merchant_subscriptions.plan_id/price_id/billing_interval
        to the target at the cycle boundary; recompute current_period_* via interval math (§49)
audit: subscription.plan_change_applied
guard: NO proration; concurrent apply protected by SELECT … FOR UPDATE; re-apply is a no-op/422
tests: apply exactly once; concurrent-apply protection; period recompute; history retained
```

### CancelScheduledPlanChange — scheduled → cancelled
```text
actor: Merchant Admin | permission: merchant.subscription.plan_change | class: merchant_mutation
writes: status=cancelled, cancelled_at
audit: subscription.plan_change_cancelled
failure: 422 invalid_state_transition (already applied/cancelled)
tests: cancel scheduled; cancel-applied rejected
```

## Notes
- No proration anywhere; the change takes effect only at the next cycle boundary.
- Applied/cancelled rows never edited in place (terminal). History preserved.
- Positive / invalid-transition / authorization / audit / concurrency tests in
  `tests/Feature/Billing`.
