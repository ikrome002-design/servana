# Subscription Plan — Status Machine (Plan §13.9, §47; Phase 20A)

> `subscription_plans.status` is a two-state catalogue lifecycle. `status` is never
> assigned directly — changes run through named actions via
> `SubscriptionPlanStateMachine`; an unlisted pair returns
> `422 invalid_state_transition`. Non-price metadata only (ADR-011 — price lives in
> `subscription_plan_prices`). Actor: **Super Administrator**
> (`platform.plan.manage`; `platform_mutation`; mandatory MFA + step-up on retire).

## States (mirror the DB CHECK)

```text
active    selectable catalogue plan
retired   withdrawn from new selection; history, prices, entitlements PRESERVED (never deleted)
```

## Transition inventory

```text
(none)  → active    create plan (non-price metadata)
active  → retired   retire plan
```

`retired` is terminal in Phase 20A (reinstatement, if ever needed, is a documented
later workflow — not a destructive edit). Any other pair → `422
invalid_state_transition`.

---

### create — (none) → active
```text
actor: Super Admin | permission: platform.plan.manage | class: platform_mutation | MFA: mandatory
input_validation: key(unique, stable machine key); name; description?; tier?; metadata jsonb OBJECT (non-price limits); sort_order?. NO price/amount columns accepted (ADR-011).
writes: plan(status=active)
audit_event: platform_plan.created (info)
failure_codes: 409 duplicate_key, 422 validation (incl. any price field), 403
tests: create active plan; duplicate key rejected; price field rejected (no such column); non-Super-Admin denied
```

### update metadata — active → active (no transition)
```text
actor: Super Admin | permission: platform.plan.manage | class: platform_mutation | MFA: mandatory
input_validation: name/description/tier/metadata/sort_order; NO key change to a colliding key; NO price
writes: metadata fields; status unchanged
audit_event: platform_plan.metadata_changed (info)
tests: metadata updates; price field still rejected; retired plan metadata edit policy per action
```

### retire — active → retired
```text
actor: Super Admin | permission: platform.plan.manage | class: platform_mutation | MFA: mandatory | step_up: required | severity: high
writes: status → retired; prices + entitlements UNTOUCHED (preserved for history/20B subscriptions)
audit_event: platform_plan.retired (high)
failure_codes: 422 invalid_state_transition (already retired), 403
tests: retire preserves prices+entitlements (no deletion); re-retire rejected; MFA + step-up
```

## Notes
- No price column exists on this table (schema test asserts absence; ADR-011).
- Retirement is non-destructive: prices and entitlements remain for historical and
  Phase-20B subscription reference.
- Positive/invalid-transition/authorization/MFA/audit tests in `tests/Feature/Billing`.
