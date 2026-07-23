# State machine — Personnel SMS campaign

**Table:** `personnel_sms_campaigns` · **Column:** `status` · **Enum:**
`App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus` · **Guard:**
`App\Domain\Messaging\Sms\Services\PersonnelSmsCampaignStateMachine` · **DB backstop:** trigger
`personnel_sms_campaigns_guard`

Plan §25.1 (state machines), §64 (Personnel Bulk SMS), §13.13 (canonical DDL); ADR-010; Phase 21S.

Status is **never assigned directly**. Every change goes through a named domain action, which calls
`PersonnelSmsCampaignStateMachine::ensure()` first; an unlisted transition raises 422
`invalid_state_transition`. The database trigger independently rejects any status change out of a
terminal state and freezes the composition/pricing snapshot once the campaign leaves `draft`.

## States

| State | Meaning | Billing | Provider |
|---|---|---|---|
| `draft` | Composed. Recipient snapshots exist; the message and selection are still editable in the sense that the campaign can be abandoned. | none | nothing sent |
| `confirmed` | The commitment point: consent snapshotted, recipients revalidated, one **provisional** `sms_billing_entries` row created. | provisional | nothing sent yet |
| `queued` | Delivery jobs dispatched (after commit). | provisional | nothing sent yet |
| `sending` | At least one recipient has been handed to the provider. | provisional | in flight |
| `completed` | Settled: every recipient succeeded. **Terminal.** | billable (or cancelled if nothing was dispatched) | done |
| `partially_failed` | Settled: some succeeded, some failed. **Terminal.** | billable | done |
| `failed` | Settled: no recipient succeeded. **Terminal.** | billable (the provider still consumed the submissions) | done |
| `cancelled` | Abandoned before anything reached the provider. **Terminal.** | cancelled — a cancelled campaign owes nothing | nothing sent |

## Transitions

```
draft ─────────────► confirmed ─────► queued ─────► sending ─┬─► completed        (terminal)
  │                     │                │                   ├─► partially_failed (terminal)
  │                     │                │                   └─► failed           (terminal)
  │                     │                │
  └────────┬────────────┴────────────────┘
           ▼
       cancelled (terminal)

partially_failed ─► completed | failed      (only when a delivery-receipt channel exists)
```

| From | To | Action | Notes |
|---|---|---|---|
| `draft` | `confirmed` | `ConfirmSmsCampaign` | Revalidates every pending recipient, suppresses those that no longer qualify, refuses with 422 `no_eligible_recipients` if none survives, re-prices from the survivors, snapshots consent, creates the provisional billing entry — all in ONE transaction under a row lock. |
| `draft` | `cancelled` | `CancelSmsCampaign` | Suppresses every pending recipient; cancels the billing entry. |
| `confirmed` | `queued` | `QueueSmsCampaign` | Runs in `DB::afterCommit` only, then dispatches one `DeliverSmsRecipientJob` per pending recipient. |
| `confirmed` | `cancelled` | `CancelSmsCampaign` | Nothing has reached the provider yet. |
| `queued` | `sending` | `DeliverSmsRecipient` | The first delivery flips the campaign, once, under a row lock. |
| `queued` | `cancelled` | `CancelSmsCampaign` | Still nothing sent — the per-recipient jobs find their recipients suppressed and no-op. |
| `sending` | `completed` / `partially_failed` / `failed` | `FinalizeSmsCampaign` | Decided from the recipient roll-up, never from a client. |
| `partially_failed` | `completed` / `failed` | `FinalizeSmsCampaign` | Reachable only when `sms.delivery.receipts_enabled` is true, i.e. when a late receipt can still resolve a `sent` recipient. Phase 21S ships with receipts **off** (REM-SMS-002). |

**`sending` is NOT cancellable.** Once a message has left, Servana will not pretend otherwise.

## Settlement rule

`FinalizeSmsCampaign` is a no-op while any recipient is still outstanding, and a no-op once the
campaign is terminal — so it is safe to call from every worker, as often as the queue calls it.

What counts as *outstanding* depends on whether a provider delivery-receipt channel exists
(`sms.delivery.receipts_enabled`):

- **receipts off (Phase 21S default):** `sent` is the final knowledge Servana has, so it counts as
  success and only `pending` is outstanding;
- **receipts on:** `sent` stays outstanding until a receipt resolves it to `delivered` or `failed`.

Servana **never** claims `delivered` without a receipt.

| Recipient roll-up | Campaign result |
|---|---|
| no failures | `completed` |
| some success, some failure | `partially_failed` |
| no success at all | `failed` |
| nothing was ever dispatched | stays as-is (the cancellation path owns that case) |

## Invariants (each one is a test)

1. **A confirmed campaign always has ≥ 1 recipient.** Enforced by the action (422
   `no_eligible_recipients`) and by the CHECK `personnel_sms_campaigns_recipient_count_check`.
2. **Confirmation is idempotent.** A campaign already at or past `confirmed` is returned untouched:
   no new recipients, no second billing entry, no second dispatch. `EnsureIdempotentRequest` replays
   the stored response, and `sms_billing_entries_live_campaign_unique` makes a second live charge
   impossible even under concurrency.
3. **Delivery is queued only after commit.** A rolled-back confirmation can never leave a dispatched
   job behind.
4. **The composition/pricing snapshot is frozen past `draft`** (`message_body_encrypted`,
   `message_template_id`, `recipient_count`, `message_character_count`, `segment_count`,
   `estimated_cost_minor`, `currency`, `consent_snapshot_at`, `confirmed_at`, `created_at`) —
   trigger-enforced.
5. **Ownership columns are immutable at all times** (`ulid`, `merchant_id`, `branch_id`,
   `staff_profile_id`, `created_by`).
6. **Terminal is terminal.** `completed`, `failed` and `cancelled` reject any further status change
   in the database.
7. **A cancelled campaign owes nothing** — its live billing entry is cancelled in the same
   transaction.
