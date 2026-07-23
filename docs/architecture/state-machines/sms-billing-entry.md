# State machine — SMS billing entry

**Table:** `sms_billing_entries` · **Column:** `status` · **Enum:**
`App\Domain\Messaging\Sms\Enums\SmsBillingEntryStatus` · **Guard:**
`App\Domain\Messaging\Sms\Services\SmsBillingEntryStateMachine` · **DB backstop:** trigger
`sms_billing_entries_guard` + partial unique index `sms_billing_entries_live_campaign_unique`

Plan §25.1, §64 ("roll up billable SMS charge to Servana billing"), §13.13; ADR-005 (integer minor
units), ADR-012 (Servana holds billing truth, Wallet holds money-movement truth); Phase 21S.

## States

| State | Meaning |
|---|---|
| `provisional` | Created inside the confirm transaction from the ESTIMATED quantity, so a campaign can never be sent without an accompanying charge record. |
| `billable` | The campaign settled; this is what is owed. A standing liability queue. |
| `invoiced` | Linked to a `subscription_invoice_items` line by a future billing phase. Phase 21S never sets this. |
| `credited` | Corrected after the fact. **Terminal.** |
| `cancelled` | Nothing was ever billable — the campaign was cancelled, or the settled quantity was zero. **Terminal.** |

## Transitions

```
provisional ─┬─► billable ─┬─► invoiced ─► credited (terminal)
             │             ├─► credited  (terminal)
             │             └─► cancelled (terminal)
             └─► cancelled (terminal)
```

| From | To | Driver |
|---|---|---|
| `provisional` | `billable` | `PersonnelSmsBillingEntryFinalizer::settle()` when the settled quantity equals the provisional one |
| `provisional` | `cancelled` | `PersonnelSmsBillingEntryFinalizer::cancel()` (campaign cancelled) or `settle()` with a zero quantity, or `settle()` with a CHANGED quantity — see below |
| `billable` | `invoiced` | **future billing phase** — sets `billing_invoice_line_id` |
| `billable` / `invoiced` | `credited` | **future billing phase** — an explicit correction |
| `billable` | `cancelled` | explicit correction before invoicing |

## Why a changed quantity is a NEW ROW, not an edit

Every monetary column is frozen by `sms_billing_entries_guard`, and the CHECK
`sms_billing_entries_amount_product_check` requires `amount_minor = quantity * unit_cost_minor`. So
when the settled billable count differs from the provisional one — which happens when a provider
reports a subscriber opt-out mid-flight — the finalizer **cancels** the provisional row and writes a
**new `billable`** row with the corrected quantity. Both rows remain as an auditable trail; nothing
is rewritten.

The partial unique index permits at most ONE live entry (`provisional` / `billable` / `invoiced`)
per campaign, so the cancel-then-create sequence can never leave two live charges behind and a
concurrent double-settlement is impossible.

## What is billable

| Recipient outcome | Billed |
|---|---|
| `sent` / `delivered` | yes |
| `failed` | yes — the provider still consumed the submission |
| `pending` (never dispatched) | no |
| `opted_out` / `suppressed` | **no** |

`quantity = billable recipients × segments`, `amount_minor = quantity × unit_cost_minor`. Integer
minor units throughout; no float appears anywhere in the path (`Money::multiply()` additionally
detects 64-bit overflow).

## Invariants (each one is a test)

1. **One live entry per campaign** — structural, via the partial unique index. A duplicate confirm,
   a job retry or a concurrent settlement cannot double-bill.
2. **`amount_minor` is never independently supplied** — the DB CHECK re-verifies the product.
3. **Monetary and ownership columns are immutable**; only `status` and `billing_invoice_line_id`
   may move.
4. **`credited` and `cancelled` are terminal** in the database.
5. **Only an `invoiced`/`credited` entry may carry an invoice line**
   (`sms_billing_entries_invoice_line_check`).
6. **Servana moves no money here.** No Wallet payment resource, no payment attempt, no subscription
   payment event, no provider call — ADR-012 keeps money movement in Wallet, which is 20D-W work
   behind a closed Gate W.

## Deferred owner

Rolling a `billable` entry into a subscription invoice line (`provisional → billable → invoiced`,
setting `billing_invoice_line_id`) is **not** Phase 21S work. Phase 21S owns the queue and its
correctness; the invoicing linkage lands with the billing phase that owns SMS charge aggregation,
and the nullable FK to `subscription_invoice_items` is the seam that waits for it.
