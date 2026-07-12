# Merchant Billing Status — Projection Machine (Plan §21, §22, §25.2; Phase 20B)

> `merchants.billing_status` is the **sole** request-authorization billing-access authority
> (§22). It is **projected transactionally** from the active `merchant_subscriptions.status`
> by the billing-status projection service — never edited directly by request handlers, and
> never derived from `merchant_subscriptions.status` at request time. Distinct from
> `merchants.status` (operational/governance): a billing transition **never** clears a
> fraud/security/legal/compliance/manual/deactivation suspension on `merchants.status`
> (§21). `merchants.billing_status` is indexed for gate lookups.

## States (mirror the DB CHECK)

```text
trialing            trial active — mutations allowed
active              paid current — mutations allowed
read_only_grace     temporary — reads allowed, mutations + new exports/PDFs blocked
overdue             pre-suspension — mutations allowed (grace still open) per §25.2
suspended_billing   hard block — reads allowed, mutations + new exports/PDFs blocked
```

(No `cancelled`/`expired` value exists — terminal subscription records project to
`suspended_billing`, Gate B2.)

## Transition inventory (§25.2)

```text
trialing          → active | read_only_grace
read_only_grace   → active | suspended_billing
active            → overdue ; active/overdue → suspended_billing
suspended_billing → active   (ONLY via fully validated payment + billing-only reason; 20D-W)
```

Plus terminal projections (Gate B2): subscription `cancelled` → `suspended_billing`
(reason `subscription_cancelled`); subscription `expired` → `suspended_billing`
(reason `subscription_expired`).

## Projection service — `ProjectMerchantBillingStatus`

```text
class: internal action (no direct HTTP route) | transactional
steps (all in one transaction):
  1 lock merchant row (FOR UPDATE)
  2 lock active subscription row (FOR UPDATE)
  3 apply the subscription transition (via MerchantSubscriptionStateMachine)
  4 map subscription.status → merchants.billing_status (table below)
  5 write merchants.billing_status + billing_status_reason
  6 emit merchant.billing_status_changed audit event
  7 rollback ALL on any failure
guards:
  - never reads merchant_subscriptions.status in request authorization (gate reads billing_status only)
  - never writes merchants.status (operational untouched)
  - suspended_billing → active requires billing-only reason
```

### Projection map

```text
trialing          → trialing
active            → active
read_only_grace   → read_only_grace
overdue           → overdue
suspended_billing → suspended_billing
cancelled         → suspended_billing   (reason subscription_cancelled)
expired           → suspended_billing   (reason subscription_expired)
```

## Billing-status gates (§9.4 step 9, §22)

```text
mutation gate: blocks mutating routes when billing_status ∈ {read_only_grace, suspended_billing}
file/PDF/export gate: blocks NEW generation when billing_status ∈ {read_only_grace, suspended_billing};
                      existing authorized files remain downloadable (Phase 10F FileGenerationPolicy
                      now reads real billing_status, replacing the temporary boolean seam)
recovery allowlist: middleware limits which routes a suspended_billing merchant may reach; recovery
                    only via validated payment (20D-W) + billing-only reason
read access: always allowed regardless of billing_status
```

## Tests
- Projection synchronizes `merchants.billing_status` from subscription transitions transactionally.
- Gate reads `merchants.billing_status` only; subscription status alone never grants access.
- `read_only_grace` / `suspended_billing`: reads allowed, mutations blocked, new export/report/PDF
  blocked, existing authorized download allowed.
- Terminal cancelled/expired → suspended_billing with distinct reasons; not projected before the
  effective boundary.
- Billing transition never clears a fraud/manual/legal/security `merchants.status` suspension.
- Projection + audit writes atomic; rollback on failure.
- No automatic terminal→active transition.
