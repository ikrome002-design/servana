# Merchant Subscription — Status Machine (Plan §13.9, §22, §25.4, §48; Phase 20B)

> `merchant_subscriptions.status` is the **record lifecycle** of one subscription. It is
> **never** the request-authorization authority — `merchants.billing_status` is (§22,
> `merchant-billing-status.md`), projected transactionally from this record. `status` is
> never assigned directly; changes run through named actions via
> `MerchantSubscriptionStateMachine`; an unlisted pair returns `422
> invalid_state_transition`. Every transition and the billing-status projection commit in
> **one transaction** under a merchant + subscription row lock.

## States (mirror the DB CHECK)

```text
trialing           trial window active (anchored at Merchant-Admin creation, Gate B1)
active             paid period current
read_only_grace    temporary recovery window (reads allowed, mutations blocked)
overdue            payment overdue, pre-suspension
suspended_billing  hard billing block (reads allowed, mutations/new-exports blocked)
cancelled          terminal — voluntarily ended (effective at cancelled_at)
expired            terminal — lapsed (effective at expired_at)
```

## Transition inventory (§25.2 billing-status machine + §25.4 record machine)

```text
(none)           → trialing            CreateTrialSubscription (first-time setup)
trialing         → active              ActivateSubscription (trial converts / paid)
trialing         → read_only_grace     EnterReadOnlyGrace (trial ended, grace configured)
trialing         → expired             ExpireSubscription (trial ended, no grace/no payment)
active           → overdue             MarkSubscriptionOverdue
active           → read_only_grace     EnterReadOnlyGrace
active/overdue   → suspended_billing   SuspendSubscriptionForBilling
read_only_grace  → active              ActivateSubscription (recovered)
read_only_grace  → suspended_billing   SuspendSubscriptionForBilling
overdue          → active              ActivateSubscription (recovered)
suspended_billing→ active              ActivateSubscription (ONLY via fully validated payment + billing-only reason; 20D-W)
any non-terminal → cancelled           CancelSubscription (effective at cancelled_at)
any non-terminal → expired             ExpireSubscription (effective at expired_at)
```

`cancelled` / `expired` are terminal. Recovery from terminal requires an explicit authorized
re-subscription/recovery workflow — **never** an automatic terminal→active transition, and no
payment/Wallet route is fabricated in Phase 20B. Any other pair → `422 invalid_state_transition`.

## Billing-status projection (Gate B2 applied)

Every transition projects `merchants.billing_status` in the same transaction (see
`merchant-billing-status.md`): trialing→trialing · active→active · read_only_grace→read_only_grace ·
overdue→overdue · suspended_billing→suspended_billing · **cancelled→suspended_billing** (reason
`subscription_cancelled`) · **expired→suspended_billing** (reason `subscription_expired`). A cancel
scheduled for period end does **not** project to `suspended_billing` before `cancelled_at`.

---

### CreateTrialSubscription — (none) → trialing
```text
actor: system (first-time setup) | class: financial_mutation | idempotent
input: merchant, plan_id, price_id (chosen at setup); interval = price.billing_interval
writes: subscription(status=trialing, trial_started_at = Merchant-Admin creation time,
        trial_days_snapshot = effective default_trial_days, trial_ends_at, current_period_*);
        project merchants.billing_status = trialing
audit: subscription.created + subscription.trial_started + merchant.billing_status_changed
invariants: one current non-terminal subscription per merchant (partial unique); interval==price
tests: trial anchor = MA creation time; snapshot days; duplicate setup → no duplicate subscription
```

### ActivateSubscription — trialing/read_only_grace/overdue/suspended_billing → active
```text
actor: billing engine / validated-payment (20D-W for suspended→active) | class: financial_mutation
writes: status=active; recompute current_period_* via interval math; project billing_status=active
audit: subscription.activated (+ subscription.recovered when from suspended_billing) + billing_status_changed
guard: suspended_billing→active ONLY with a billing-only reason (never clears merchants.status)
tests: each source→active; suspended recovery gated; period recompute
```

### EnterReadOnlyGrace — trialing/active → read_only_grace
```text
writes: status=read_only_grace; project billing_status=read_only_grace
audit: subscription.read_only_grace_entered + billing_status_changed
tests: reads allowed, mutations blocked, new exports/PDFs blocked, existing downloads allowed
```

### MarkSubscriptionOverdue — active → overdue
```text
writes: status=overdue; project billing_status=overdue
audit: subscription.overdue + billing_status_changed
```

### SuspendSubscriptionForBilling — active/overdue/read_only_grace → suspended_billing
```text
writes: status=suspended_billing; project billing_status=suspended_billing
audit: subscription.suspended_billing + billing_status_changed
guard: never changes merchants.status (operational)
tests: mutations blocked; merchants.status unchanged; recovery allowlist narrow
```

### CancelSubscription — any non-terminal → cancelled
```text
actor: Merchant Admin / system | class: financial_mutation
writes: status=cancelled, cancelled_at; project billing_status=suspended_billing (reason subscription_cancelled) at cancelled_at
audit: subscription.cancelled + billing_status_changed
tests: projects suspended_billing only at effective boundary; distinct reason; not before period end
```

### ExpireSubscription — trialing/active/overdue/read_only_grace → expired
```text
actor: scheduler / system | class: financial_mutation | idempotent
writes: status=expired, expired_at; project billing_status=suspended_billing (reason subscription_expired)
audit: subscription.expired + billing_status_changed
tests: expiry projects suspended_billing; distinct reason subscription_expired; no auto terminal→active
```

## Notes
- Terminal records retained (no destructive delete). Access authority is always
  `merchants.billing_status`; a subscription status alone never grants access (tested).
- Projection rollback: if any step in the transaction fails, both rows roll back (tested).
- Positive / invalid-transition / authorization / audit / projection tests in
  `tests/Feature/Billing`.
