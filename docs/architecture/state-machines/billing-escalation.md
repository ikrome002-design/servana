# Billing Escalation — Append-Only Event Log (Plan §13.15, §22, §54, §67; Phase 20B)

> `billing_escalation_events` is an **append-only** log that drives and records the shared
> overdue escalation pathway. It is not a mutable status machine — each event is written once
> and never updated or deleted. Each event both records an escalation step and applies the
> corresponding `merchants.billing_status` transition via the projection service (§22).
> Scheduler-driven (§67); **idempotent per `(merchant_subscription_id, event_type,
> period_boundary)`** (Gate B4 — enforced by a UNIQUE constraint, never by `created_at`).

## Event types (mirror the DB CHECK)

```text
reminder            pre-due / due reminder
grace_entered       active/trialing → read_only_grace
overdue             active → overdue
suspended_billing   active/overdue/read_only_grace → suspended_billing
recovered           suspended_billing → active (validated payment; 20D-W)
```

## Escalation pathway (§54)

```text
active → overdue → suspended_billing   (per configured grace, regardless of billing mode)
trialing/active → read_only_grace (grace window) → suspended_billing
suspended_billing → active (recovered; validated payment + billing-only reason; 20D-W)
```

Each step:
1. computes the current `period_boundary` (via the §49 interval calculator);
2. writes one `billing_escalation_events` row **iff** `(merchant_subscription_id, event_type,
   period_boundary)` is new (`ON CONFLICT DO NOTHING` under the UNIQUE constraint) — a replay is a
   no-op;
3. records `from_billing_status`/`to_billing_status`/`reason`;
4. applies the `merchants.billing_status` transition through `ProjectMerchantBillingStatus` in the
   same transaction, emitting the escalation + `merchant.billing_status_changed` audit events.

## Scheduler (§67)

```text
cadence: safe established DAILY billing cadence (documented; §54 defines no finer cadence).
registration: standard scheduler registration + withoutOverlapping + advisory lock (established
              scheduler conventions); tenant-aware where queued; bounded batch.
observability: redacted failure signal (Section 71 alert on scheduler failure); centralized
               paging/runbooks remain Phase 25.
idempotency: per (merchant_subscription_id, event_type, period_boundary) — safe to re-run.
```

## Audit
`billing_escalation.reminder`, `.grace_entered`, `.overdue`, `.suspended`, `.recovered`
(+ the paired `subscription.*` and `merchant.billing_status_changed` events from the projection).

## Tests
- One row per `(subscription, event_type, period_boundary)`; duplicate rejected by PG (durable
  idempotency); no idempotency claimed from `created_at`.
- Append-only: no UPDATE/DELETE path.
- Escalation drives `active → overdue → suspended_billing`; projection applied atomically.
- Scheduler run is idempotent, bounded, tenant-aware, audit-covered, emits a redacted failure signal.
- Feeds Super-Admin overdue-escalation reporting (§69).
