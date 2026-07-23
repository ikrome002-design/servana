# State machine — Personnel SMS recipient delivery

**Table:** `personnel_sms_recipients` · **Column:** `delivery_status` · **Enum:**
`App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus` · **Guard:**
`App\Domain\Messaging\Sms\Services\PersonnelSmsRecipientStateMachine` · **DB backstop:** triggers
`personnel_sms_recipients_guard` (snapshot immutability + terminal finality) and
`personnel_sms_recipients_no_delete`

Plan §25.1, §64, §13.13; ADR-010; Phase 21S.

## States

| State | Meaning | Phone snapshot | Billed |
|---|---|---|---|
| `pending` | Eligible at composition; not yet handed to the provider. | present (encrypted) | not yet |
| `sent` | Accepted by the provider. Without a receipt channel this is the final knowledge Servana has. | present | yes |
| `delivered` | A provider **receipt** confirmed delivery. Never claimed without one. | present | yes |
| `failed` | Permanently rejected by the provider, or transient retries exhausted (dead letter). **Terminal.** | present | yes — the provider still consumed the submission |
| `opted_out` | The client had withdrawn SMS consent, or the provider reported a subscriber opt-out. **Terminal.** | **absent** when excluded at composition | no |
| `suppressed` | Excluded for any other safe reason (archived client, no consent on record, campaign cancelled). **Terminal.** | **absent** when excluded at composition | no |

**Data minimization (Plan §74):** a recipient excluded at composition never has their number
snapshotted at all — `phone_encrypted` is NULL and only the masked `phone_last_four` is retained.
The CHECK `personnel_sms_recipients_phone_required_check` enforces that a dispatchable recipient
always carries the snapshot and permits its absence only for `opted_out` / `suppressed`.

## Transitions

```
pending ─┬─► sent ─┬─► delivered   (terminal)
         │         └─► failed      (terminal)
         ├─► failed      (terminal)
         ├─► opted_out   (terminal)
         └─► suppressed  (terminal)
```

| From | To | Driver |
|---|---|---|
| `pending` | `sent` | `DeliverSmsRecipient` — provider accepted the submission |
| `pending` | `failed` | `DeliverSmsRecipient` — permanent rejection, or transient retries exhausted |
| `pending` | `opted_out` | `SuppressSmsRecipient` (consent withdrawn by confirm time) or `DeliverSmsRecipient` (provider reported a subscriber opt-out) |
| `pending` | `suppressed` | `SuppressSmsRecipient` — archived client, no consent on record, or campaign cancelled |
| `sent` | `delivered` | `RecordSmsDeliveryReceipt` — a provider receipt confirmed delivery |
| `sent` | `failed` | `RecordSmsDeliveryReceipt` — a provider receipt reported failure |

`delivered`, `failed`, `opted_out` and `suppressed` are terminal in the enum, in the state machine
and in the database trigger.

## Retry policy (Plan §64: retry transient, never permanent)

The decision input is `SmsProviderResultClass`, stored on every `sms_delivery_attempts` row, so the
policy is provable from the database without a live provider.

| Result class | Kind | Retried? | Terminal recipient status when it stops |
|---|---|---|---|
| `accepted` | success | — | `sent` |
| `invalid_recipient` | **permanent** | **never** | `failed` |
| `opted_out` | **permanent** | **never** | `opted_out` (a consent fact, not a generic failure) |
| `rate_limited` | transient | yes | `failed` (dead letter) |
| `insufficient_balance` | transient (operator condition) | yes | `failed` (dead letter) |
| `provider_error` | transient | yes | `failed` (dead letter) |
| `transport_error` | transient | yes | `failed` (dead letter) |
| `unauthorized` | transient (operator condition) | yes | `failed` (dead letter) |
| `unexpected` | transient | yes | `failed` (dead letter) |

Backoff is capped exponential: `min(cap, base × 2^(attempt−1))` with `base =
sms.delivery.backoff_base_seconds` (60 s) and `cap = sms.delivery.backoff_cap_seconds` (1 h).
`sms.delivery.max_attempts` (4) includes the first attempt. Exhausting it dead-letters the recipient
and emits `personnel.sms.delivery_dead_lettered` at **high** severity — the operator's signal that a
key, a balance or the provider itself needs attention.

Only a transient failure may schedule a retry; the CHECK
`sms_delivery_attempts_next_retry_check` enforces that `next_retry_at` is set for nothing else.

## Invariants (each one is a test)

1. **Dedupe by campaign-recipient key.** `UNIQUE (campaign_id, client_id)` — one row per client per
   campaign, so a client cannot be messaged twice by one campaign.
2. **The delivery claim is the status.** Only a `pending` recipient is ever submitted, and it leaves
   `pending` in the same transaction that records the attempt, so a duplicate dispatch, a queue
   redelivery or a concurrent worker finds nothing to do.
3. **Snapshot columns are immutable at all times** (`merchant_id`, `branch_id`, `campaign_id`,
   `client_id`, `service_session_id`, `phone_encrypted`, `phone_last_four`,
   `eligibility_snapshot_json`, `consent_status_snapshot`, `created_at`). Only `delivery_status`,
   `provider_message_id` and `cost_minor` may move.
4. **Rows are never deleted** — `personnel_sms_recipients_no_delete` blocks DELETE outright.
5. **An undispatched recipient carries no provider identity and no cost**
   (`personnel_sms_recipients_undispatched_check`).
6. **Receipts are idempotent.** Only a `sent` recipient is affected; a duplicate or out-of-order
   receipt is a no-op, and a receipt arriving after the campaign settled updates the recipient row
   but never reopens a terminal campaign.
7. **No phone in the eligibility snapshot.** The CHECK
   `personnel_sms_recipients_snapshot_no_phone_check` rejects a `phone`, `phone_encrypted`,
   `msisdn` or `phone_number` key in the jsonb outright.
