# ADR-014 — Structured Payment Reference and Invoice→Wallet Registration

- **Status:** Accepted (v4 plan-adoption PR; registration runtime deferred to Phase 20D-W).
- **Date:** 2026-07-07
- **Required by:** Plan §8.1 ADR-014, §13.9, §49, §56.1, SUP-05; Phase 20B/20D-W.
- **Related:** ADR-012 (Wallet boundary); REM-WALLET-001;
  `docs/architecture/data-dictionary/billing-and-wallet.md`.

## Context

PayBill/Till instructions and STK initiation require an immutable structured payment reference
(`SRV-PAY-*`) mapped to a registered Wallet payment resource. v3 stored an M-Pesa-shaped
`account_reference` without Wallet registration semantics.

## Authoritative references

| Source | Role |
|---|---|
| Plan SUP-05 | Reference is Wallet-issued, not Servana-invented |
| Wallet scope §21.1 (missing from repo) | Structured reference format |
| Plan §49 / §80.1 | Phase 20B vs 20D-W sequencing |

## Proven problem

C2B payments need a valid reference before the payer acts. Lazy-only registration at first
STK would leave issued invoice PDFs without payable instructions.

## Decision

1. **One Wallet payment resource per Servana subscription invoice.**
2. **`external_reference` = Servana invoice ULID** (unique within the Servana Wallet application).
3. **Wallet issues the immutable `SRV-PAY-*` reference**; Servana stores it as
   `subscription_invoices.account_reference` (nullable until registration succeeds).
4. **Registration is idempotent** with `Idempotency-Key: srv:pay-reg:{invoice_ulid}`.
5. **PayBill/Till instructions and STK initiation require successful registration** (§56.1).
6. **Phase 20B** ships only nullable forward-compatible projection columns on
   `subscription_invoices`: `wallet_payment_id`, `wallet_registration_status`,
   `wallet_registered_at`, `account_reference` — **no outbox table, no consumer, no
   `RegisterInvoicePayment` runtime.**
7. **Phase 20D-W** (1) idempotently backfills existing unregistered payable invoices,
   (2) registers newly issued invoices after commit, (3) guarantees registration before payment
   instructions or STK, (4) uses the stable idempotency key above.

Partial payments do **not** re-register; Wallet tracks received vs expected.

## Ownership boundary

Wallet creates the payment resource and reference. Servana stores projections and enforces
gating on instructions/attempts.

## Data stored in Servana

Invoice ULID as `external_reference` sent to Wallet; returned `wallet_payment_id`; immutable
`account_reference`; registration status timestamps.

## Data forbidden in Servana

Provider checkout/request IDs; Daraja OAuth artifacts; raw Wallet API secrets.

## Security implications

Registration calls use Servana→Wallet machine credentials (ADR-015). Failures surface as
503 `provider_unavailable` — never bypass registration.

## Tenant / isolation implications

Registration runs inside the invoice's merchant tenant context; Wallet merchant-account link
required (`wallet_merchant_account_links`).

## Migration implications

Phase 20B: nullable columns only. Phase 20D-W: populate via integration actions — no rewrite of
finalized invoice financial snapshots.

## Rollout sequence

20A → 20B (columns) → Gate W → 20D-W (registration runtime + backfill job).

## Rejected alternatives

- **Register only at first payment intent:** rejected — C2B needs reference on issued invoice.
- **Phase 20B `RegisterInvoicePayment` outbox without specified table:** rejected (Correction
  14.5) — undefined consumer; deferred to 20D-W with explicit actions.

## Consequences

Issued invoices may show null `account_reference` until 20D-W; UI must honest-empty-state.
Post-20D-W, instructions are gated on `wallet_registration_status = registered`.

## Test requirements

- Phase 20B: schema tests for nullable columns only.
- Phase 20D-W: idempotent registration, backfill, gating before STK/instructions, conflict
  handling (future).

## Review requirements

Adoption PR must not add `RegisterInvoicePayment` executable code or registration routes.

## Superseded decisions

v3 "exact M-Pesa reference on invoice" (SUP-05).

## Deferred external-contract pins

Exact Wallet `POST /api/v1/payments` request/response fields — pinned at Gate W OpenAPI hash.
