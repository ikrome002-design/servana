# Subscription Invoice — Status Machine (Plan §13.9, §25.4, §49, ADR-014; Phase 20B)

> `subscription_invoices.status` is the invoice financial machine. `status` is never
> assigned directly — changes run through named actions; an unlisted pair returns `422
> invalid_state_transition`. Issued invoices are **immutable** (financial fields). The Wallet
> registration status (`unregistered→pending→registered|failed`) is an **orthogonal technical
> field**, not part of this machine, and never blocks issuance (ADR-014). Cancellation
> terminology is **`void` only** (never `cancelled`).

## States (mirror the DB CHECK)

```text
draft                  pre-issuance working state
issued                 number allocated; financial snapshot immutable
pending_payment        awaiting payment (20D-W)
partially_paid         partial verified Wallet receipt (20D-W)
paid                   fully paid (20D-W)
overdue                past due (from issued/partially_paid)
payment_failed         attempt failed (20D-W)
reconciliation_required funds cannot be safely applied (20D-W)
void                   terminal pre-payment supersession
```

## Transition inventory (§25.4)

```text
(none)                    → draft
draft                     → issued            IssueSubscriptionInvoice
draft/issued              → void              VoidSubscriptionInvoice (terminal, pre-payment)
issued                    → pending_payment   (20D-W)
issued/partially_paid     → overdue           MarkSubscriptionInvoiceOverdue
pending_payment           → partially_paid | paid | payment_failed   (20D-W, verified Wallet events only)
pending_payment           → issued            (after attempt expiry; 20D-W)
any payable               → reconciliation_required   (20D-W)
```

**Phase 20B implements** the system/action-driven transitions: `draft → issued`,
`issued/partially_paid → overdue`, `draft/issued → void`. The
`pending_payment/partially_paid/paid/payment_failed/reconciliation_required` transitions are driven
**exclusively** by verified Wallet events or exception-resolution linkage in **Phase 20D-W** — not
implemented in 20B. Any other pair → `422 invalid_state_transition`.

---

### IssueSubscriptionInvoice — draft → issued
```text
actor: system/action (no merchant-facing issue route in 20B) | class: financial_mutation | idempotent
sequence (under subscription + numbering row lock):
  1 Gate B5: assert effective billing_mode = fixed_amount, else 422 billing_mode_not_supported (issue nothing)
  2 resolve captured plan price (price_id, billing_interval)
  3 allocate invoice_number from invoice_number_sequences (merchant_id, scope='subscription_invoice')
  4 create immutable subscription_invoice_items (single plan_fee line = captured price in fixed mode)
  5 subtotal_minor = sum(items); discount_minor = 0 (no promotions until 20C); total_minor = subtotal - discount
  6 balance_minor = total_minor; currency = price currency
  7 Wallet fields = 20B defaults (account_reference null, wallet_payment_id null,
    wallet_registration_status='unregistered', wallet_registered_at null) — NO Wallet call, NO outbox intent
  8 issued_at = now; due_at = interval math (§49)
audit: subscription_invoice.issued
invariants: NO percentage ledger row; NO Wallet call; issued financial fields immutable thereafter
tests: fixed total = captured price; per-merchant number; idempotent; Wallet columns default/null;
       percentage mode fails closed; no ledger/Wallet artifacts
```

### VoidSubscriptionInvoice — draft/issued → void
```text
actor: Finance/system | class: financial_mutation
guard: void ONLY (never 'cancelled'); pre-payment supersession
writes: status=void
audit: subscription_invoice.voided
tests: void terminology; void-after-payment path not available in 20B
```

### MarkSubscriptionInvoiceOverdue — issued/partially_paid → overdue
```text
actor: scheduler/system | class: financial_mutation | idempotent
writes: status=overdue
audit: subscription_invoice.overdue
tests: overdue transition; idempotent re-run
```

### GenerateSubscriptionInvoicePdf — file action (not a status transition)
```text
actor: Merchant Admin (download) / system (generate) | uses Phase 10F private file domain
guard: NEW generation blocked in read_only_grace/suspended_billing; existing authorized file downloadable
render: while account_reference is null → "Payment reference pending — see your billing dashboard"
        (no fake reference); versioned regeneration after Wallet registration is Phase 20D-W
audit: subscription_invoice.pdf_generated
tests: placeholder when unregistered; existing download allowed; new-generation blocked in read-only
```

## Notes
- Issued invoices immutable; Wallet registration fields are orthogonal and never block issuance.
- No payment-driven transitions in 20B (those are 20D-W, verified Wallet events only).
- Positive / invalid-transition / immutability / fail-closed / audit tests in `tests/Feature/Billing`.
