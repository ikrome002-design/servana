# Subscription Plan Price — Lifecycle (Plan §13.9, §47; ADR-011; Phase 20A)

> Effective-dated price lifecycle for `subscription_plan_prices` — the **sole**
> plan-price source (ADR-011). Prices are not mutated in place; a change is a **new
> effective-dated row** or the withdrawal of a **not-yet-effective** future row.
> There is no `status` column: lifecycle is expressed by the effective `daterange`
> relative to today (`Africa/Nairobi`). Money is integer minor units; currency is
> uppercase ISO. Actor: **Super Administrator** (`platform.plan_price.manage`;
> `platform_mutation`; mandatory MFA + step-up on create/schedule/cancel).

## Lifecycle states (derived from the effective range, not stored)

```text
future     effective_from > today                      (a "scheduled" price; cancellable)
current    effective_from <= today < effective_to|∞    (the resolved price for its (plan,interval,currency))
historical effective_to <= today                       (retained; immutable; never edited)
```

## Operations (named actions; no generic status route)

### create price — insert a current/open price
```text
actor: Super Admin | permission: platform.plan_price.manage | class: platform_mutation | MFA: mandatory | step_up: required | idempotency: Idempotency-Key required (effective-dated financial create)
input_validation: plan_ulid (active plan); amount_minor(>=0); currency(upper ISO); billing_interval ∈ BillingInterval(5); effective_from(date); effective_to nullable (> effective_from). NO status/price-on-plan accepted.
transaction_boundary: single transaction | rows_locked: SELECT plan FOR UPDATE (advisory serialization of overlap)
preconditions: no overlapping range for (plan, interval, currency) — DB EXCLUDE USING gist is the final arbiter
writes: one subscription_plan_prices row (created_by=actor)
audit_event: platform_plan_price.created (high; plan ULID, interval, currency, amount, effective range)
failure_codes: 409 plan_price_overlap, 409 idempotency_*, 422 validation, 403, 404 (plan/foreign)
tests: all five intervals; sole source (no plan price column); overlap rejected by PG; adjacent range allowed; concurrent create cannot both win; integer minor units; uppercase currency; idempotent replay
```

### schedule price — insert a FUTURE price (effective_from > today)
```text
same controls as create; effective_from must be in the future; participates in the same overlap exclusion.
audit_event: platform_plan_price.scheduled (high)
tests: future price scheduled; overlaps existing/future rejected; does not become current until its effective_from
```

### cancel future price — withdraw a not-yet-effective price
```text
actor: Super Admin | permission: platform.plan_price.manage | class: platform_mutation | MFA: mandatory | step_up: required
preconditions: the target price is FUTURE (effective_from > today) — a current or historical (already-effective) price can NEVER be cancelled or deleted
writes: the future row is removed/withdrawn (documented lifecycle); an effective/historical price is untouched
audit_event: platform_plan_price.cancelled (high; withdrawn future price)
failure_codes: 422 cannot_cancel_effective_price, 403, 404
tests: cancel a future price; attempt to cancel a current/historical price rejected; no destructive edit of an effective price
```

## Resolution (read path — `ResolveEffectivePlanPrice`)
```text
given: plan, billing_interval, currency, date (default today Africa/Nairobi)
current price = the row whose daterange [effective_from, effective_to) contains the date.
historical lookup = the row whose range contained a past date.
Exactly one row can match per (plan, interval, currency, instant) — guaranteed by the EXCLUDE constraint.
20B captures price_id at subscription issuance; a later price change never rewrites an issued invoice snapshot.
```

## Notes
- ADR-011: this table is the only price authority; `subscription_plans` carries no
  monetary column (schema test asserts absence).
- The `EXCLUDE USING gist` over
  `(plan_id, billing_interval, currency, daterange(effective_from, effective_to,'[)'))`
  with `&&` is the authoritative non-overlap guard; adjacent `[a,b)`/`[b,c)` ranges
  are allowed.
- No `PATCH`/generic status; each operation is a named route/action.
- 20A creates **no** renewal-date/next-cycle behaviour — the `billing_interval`
  vocabulary is defined here and consumed by Phase 20B date math (§49).
- Positive/negative/overlap/concurrency/idempotency/audit tests live in
  `tests/Feature/Billing`.
