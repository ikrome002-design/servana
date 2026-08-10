# State machine — Platform SMS billing rule

**Table:** `platform_sms_billing_rules` · **Derived state:** `pending` / `effective` / `superseded`
/ `cancelled` (computed from `effective_from`, `cancelled_at` and the next uncancelled rule) ·
**Enum:** `App\Domain\Billing\Enums\PlatformSmsBillingRuleState` · **Resolver:**
`App\Domain\Billing\Queries\ResolveEffectiveSmsBillingRule` · **DB backstop:** trigger
`platform_sms_billing_rules_guard` + `UNIQUE (effective_from)`

Plan §13.9, §47, §50, §64; ADR-004 (forward-only), ADR-005 (integer minor units);
[`COR-UI08-001`](../../decisions/cor-ui08-001-super-administrator-backend-enablement.md) §9;
Phase UI-08.

## The state is derived, never stored

There is no `status` column. Storing one would create a second authority that could disagree with
the dates. The state of a rule at any instant `T` is a pure function of the row and the series:

| Derived state | Condition at instant `T` |
|---|---|
| `cancelled` | `cancelled_at IS NOT NULL` |
| `pending` | not cancelled and `effective_from > T` |
| `effective` | not cancelled, `effective_from <= T`, and no uncancelled rule has a greater `effective_from <= T` |
| `superseded` | not cancelled, `effective_from <= T`, and a later uncancelled rule is already effective |

## Transitions

```
(insert) ─► pending ─┬─► effective ─► superseded   (time only; both are irreversible)
                     └─► cancelled  (explicit, only while still pending)
```

| From | To | Driver |
|---|---|---|
| — | `pending` | `ScheduleSmsBillingRule` — `platform.billing_settings.update`, MFA, fresh `billing_configuration` step-up, mandatory reason, idempotency |
| `pending` | `cancelled` | `CancelScheduledSmsBillingRule` — same controls, mandatory cancellation reason |
| `pending` | `effective` | the clock. No actor, no write. |
| `effective` | `superseded` | the clock, when the next uncancelled rule's instant arrives. No actor, no write. |

**There is no transition out of `effective` or `superseded`.** A settled rule is permanent history.

## What the database enforces

`platform_sms_billing_rules_guard` (BEFORE UPDATE OR DELETE):

- **DELETE always raises.** The series is append-only.
- **UPDATE raises** unless the statement changes only `cancelled_at`, `cancelled_by_user_id`,
  `cancellation_reason` and `updated_at`.
- **Cancellation raises** when `cancelled_at` was already set (single-use) or when
  `effective_from <= now()` — an already-effective rule can never be cancelled.

`UNIQUE (effective_from)` is the overlap guarantee: two rules can never claim the same instant, so
"which rule applies at `T`?" always has exactly one answer.

Scheduling in the past is rejected by `ScheduleSmsBillingRuleRequest` (`effective_from` must be
`>= now()`). A backdated rule could not rewrite any charge — `sms_billing_entries` is frozen by its
own guard — but it would make the recorded pricing history untruthful, which is why it is refused
rather than merely harmless.

## Interaction with charged usage

The rule is resolved **at the usage event's effective time** and snapshotted once into
`sms_billing_entries` at campaign confirmation. Scheduling a new rule therefore never recalculates,
rewrites or re-prices an existing entry; `sms_billing_entries_guard` makes that structurally
impossible in any case. `Ui08SmsBillingSnapshotImmutabilityTest` proves it against real rows.

## Currency

The rule stores **no currency**. Currency is read from the effective `platform_billing_settings`
version at the same instant, so there is exactly one currency authority in the system.
