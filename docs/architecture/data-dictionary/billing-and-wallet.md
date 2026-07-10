# Billing and Wallet Integration — Data Dictionary (Plan §13.9–§13.11; Phases 20A–20D-W)

> **Architecture specification only.** This document defines future tables and columns for
> the Servana↔Wallet integration boundary. **No business migrations ship in the v4 plan-adoption
> PR.** Migrations are authored in their owning phases (20A, 20B, 20D-W) after Gate W where
> required.
>
> **Ownership:** Servana owns business-billing truth (plans, subscriptions, invoices,
> allocations, billing-status recovery). **Wallet owns money-movement truth** (provider
> credentials, STK/C2B, raw payloads, provider reconciliation, ledger postings). Servana never
> calls Safaricom/Daraja directly (ADR-012; Plan §9 rule 20).
>
> The v3 file `billing-and-mpesa.md` was never shipped; this file replaces that planned name.
> Historical `mpesa_callback_inbox` / `mpesa_reconciliation_events` names were removed before
> build (SUP-02).

---

## Controlling sources

- Plan §13.9–§13.11, §25.4 (subscription invoice + payment attempt machines), §49, §56–§58,
  §80.1–§80.2 (Gate W), ADR-012, ADR-014, ADR-015
- `docs/architecture/adr/0012-wallet-by-citrus-payment-orchestration-boundary.md`
- `Wallet_by_Citrus_Platform_Project_Scope.md` (**not present in this repository** — contract
  pins deferred to External Gate W)

---

## Phase 20A — Platform billing configuration (no Wallet dependency)

| Table | Owner phase | Purpose |
|---|---|---|
| `platform_billing_settings` | 20A | Billing mode, trial/grace defaults, effective dating |
| `subscription_plans` | 20A | Plan catalogue (non-price metadata) |
| `subscription_plan_prices` | 20A | Sole price source (ADR-011) |
| `plan_entitlements` | 20A | Entitlement limits per plan |
| `preferred_personnel_fee_rules` | 20A | Effective-dated fixed/percentage rules; expand-and-contract from legacy `services.preferred_personnel_fee_minor` |

**Phase 17 note:** Completed invoices used the legacy fixed `services.preferred_personnel_fee_minor`
seam. Phase 20A migrates values into `preferred_personnel_fee_rules` and changes **future**
finalization to resolve the effective rule; **finalized invoices are never rewritten.**

---

## Phase 20B — Subscriptions and invoices (nullable Wallet projections only)

| Table / column | Owner phase | Purpose |
|---|---|---|
| `merchant_subscriptions` | 20B | Subscription lifecycle; `merchants.billing_status` is access authority |
| `scheduled_plan_changes` | 20B | Next-cycle plan changes (no proration) |
| `subscription_invoices` | 20B | Issued invoice financial snapshot |
| `subscription_invoice_items` | 20B | Line items (plan fee, rollups, adjustments) |
| `subscription_invoices.wallet_payment_id` | 20B | Nullable; Wallet payment resource ID (populated 20D-W) |
| `subscription_invoices.wallet_registration_status` | 20B | `unregistered` \| `pending` \| `registered` \| `failed` |
| `subscription_invoices.wallet_registered_at` | 20B | Nullable timestamp |
| `subscription_invoices.account_reference` | 20B | Nullable until Wallet registration; immutable `SRV-PAY-*` once set (ADR-014) |

**Explicit non-deliverable in 20B:** no `RegisterInvoicePayment` outbox table, no registration
consumer, no Wallet HTTP client routes. Registration runtime is Phase 20D-W only (Correction 14.5).

---

## Phase 20D-W — Wallet integration (requires Gate W)

| Table | Owner phase | Purpose |
|---|---|---|
| `wallet_merchant_account_links` | 20D-W | Servana merchant ↔ Wallet merchant-account ID |
| `subscription_payment_attempts` | 20D-W | User/product-initiated attempts (STK); includes `submission_unknown` state |
| `subscription_payments` | 20D-W | Confirmed Wallet payments (including direct C2B without attempt row) |
| `subscription_payment_receipts` | 20D-W | Append-only partial receipt/allocation child rows (Correction 14.10) |
| `wallet_webhook_inbox` | 20D-W | Verified first-seen `wallet_event_id` only |
| `billing_reconciliation_exceptions` | 20D-W | Servana-side reconciliation cases (masked provider refs) |
| `subscription_invoice_payment_locks` | 20D-W | Bounded cooldown/lock for `submission_unknown` retries |
| `merchant_billing_credits` | 20D-W | Overpayment credits (A-10) |

### Payment attempt states (Plan §25.4; Correction 14.6)

Includes: `initiated`, `submitting_to_wallet`, `submitted_to_wallet`, **`submission_unknown`**,
`prompt_sent`, `confirmed`, `applied_to_invoice`, plus terminal failure/cancel states.

**`submission_unknown`:** entered on Wallet submission timeout or ambiguous transport failure.
Must retain the **original idempotency key**, prevent duplicate attempts under a new key, retry/query
with the original identity under a bounded lock, and resolve through authoritative Wallet status —
timeout ≠ proof the request was not accepted.

### Direct C2B (Correction 14.9)

- **`subscription_payment_attempts`:** user/product-initiated attempts (STK).
- **`subscription_payments`:** confirmed Wallet payments, **including direct C2B**.
- Direct C2B has **no attempt row** unless Wallet correlates to an existing product-created attempt.
- Do **not** fabricate `initiated_by_user_id`, `initiated_by_role_snapshot`, or Servana initiation
  idempotency keys for orphan C2B.

### Partial payments (Correction 14.10)

- One **`subscription_payments` aggregate row per Wallet payment** (`wallet_payment_id` unique on
  the aggregate).
- Multiple partial receipts via append-only **`subscription_payment_receipts`** with unique
  `confirming_wallet_event_id`.
- If Gate W requires: `unique(wallet_payment_id, wallet_receipt_sequence)` on child rows.

### Webhook ordering (Correction 14.8)

Wallet contract must publish a monotonic per-resource field (`resource_version` or
`state_sequence` — **exact name pinned at Gate W**). Servana applies only strictly newer versions;
`occurred_at` and event ID alone are not ordering authority.

### Webhook verification (Correction 14.7)

Unverified requests must not insert into the canonical verified `wallet_event_id` uniqueness
constraint. Failed verification → security audit + metrics; no ad-hoc rejection table in adoption PR.

---

## Forbidden in Servana (never assign to Servana schema)

| Forbidden concern | Owner |
|---|---|
| Provider credentials / OAuth | Wallet |
| Raw provider callbacks / payloads | Wallet |
| Provider identifiers (checkout request IDs, etc.) | Wallet |
| Provider receipt-uniqueness enforcement | Wallet |
| Provider reconciliation ledger rows | Wallet |
| `services.mpesa` configuration keys | — (must not exist) |
| Direct Daraja/Safaricom API hostnames in executable config | — |

**Permitted terminology:** `mpesa_offline` as a **merchant-client payment method** enum value
(Phase 18A) — not platform billing provider integration.

---

## Retention and audit

Billing and reconciliation rows follow financial retention (Plan §13). Masked provider references
only in Servana; full provider detail remains in Wallet.

---

## Migration manifest

Entries are added to `docs/architecture/migrations/manifest.yaml` when each owning phase ships
its forward-only migrations — not in the adoption PR.
