# ADR-012 — Wallet by Citrus Payment-Orchestration Boundary

- **Status:** Accepted (v4 plan-adoption PR; architecture-only — no runtime integration).
- **Date:** 2026-07-07
- **Required by:** Plan §8.1 ADR-012, §2.2 ownership matrix, §§55–58, Phase 20D-W;
  `Wallet_by_Citrus_Platform_Project_Scope.md` (authoritative integration contract — **not
  present in this repository**; deferred contract pins at External Gate W, §80.2).
- **Supersedes (for current architecture):** inline ADR-006 (direct Daraja/M-Pesa in Servana —
  historical only; never shipped).
- **Related:** ADR-014 (invoice registration), ADR-015 (webhook signing); REM-WALLET-001;
  `docs/architecture/data-dictionary/billing-and-wallet.md`.

## Context

v3 Phase 20D assigned Servana direct Safaricom/Daraja credentials, STK submission, C2B
callbacks, raw provider payloads, provider receipt uniqueness, and provider reconciliation.
Wallet by Citrus is the centralized payment-orchestration product for Citrus source products.
Building provider logic inside Servana would duplicate Wallet, violate data minimization, and
create dual financial truth. Phase 20 has not started; adoption is a plan-and-guard change only.

## Authoritative references

| Source | Role |
|---|---|
| Plan §1.2 SUP-01…SUP-06 | Supersession record |
| Plan §2.2 | Ownership matrix |
| `Wallet_by_Citrus_Platform_Project_Scope.md` | Servana↔Wallet contract (missing from repo) |
| ADR-006 (historical, inline Plan §8) | Abandoned direct-Daraja design |

## Proven problem

Servana must collect merchant subscription fees without holding provider credentials, without
raw provider callbacks, and without provider reconciliation rows. Wallet scope mandates that
products request STK through Wallet and that provider credentials live only in Wallet.

## Decision

**Servana owns business-billing truth:**

- Platform billing settings, plans, prices, entitlements
- Merchant subscriptions and scheduled plan changes
- Subscription invoices and invoice items
- Promotions and free-period offers
- Billing credits and invoice allocation
- Billing-status recovery (`suspended_billing` → `active` when Wallet-confirmed payments apply)

**Wallet owns money-movement truth:**

- Provider credentials, provider accounts, provider routing
- STK submission and C2B callback processing (Safaricom → Wallet)
- Raw provider payloads and provider identifiers
- Provider receipt uniqueness and payment/settlement status
- Provider reconciliation and financial-ledger postings
- Provider exceptions at the provider layer

**Servana never calls Safaricom/Daraja directly.** Servana implements only its side of the
Wallet product API and verified webhook contract (Phases 20D-W onward).

## Ownership boundary

| Concern | Owner |
|---|---|
| Invoice balance, billing access, promotions | Servana |
| STK, PayBill/Till collection, provider state | Wallet |
| Structured payment reference `SRV-PAY-*` issuance | Wallet (Servana stores projection) |
| Subscription payment application to invoice | Servana (under verified Wallet events) |

## Data stored in Servana (local projections only)

`wallet_payment_id`, `wallet_attempt_id`, `wallet_event_id`, payment/settlement status
projections, `provider_method`, `provider_reference_masked`, amounts, timestamps,
`account_reference` (Wallet-issued `SRV-PAY-*`), registration status columns on
`subscription_invoices`.

## Data forbidden in Servana

Provider credentials; OAuth tokens; raw Daraja/provider callbacks; provider callback
endpoints; `mpesa_callback_inbox`-style tables; provider reconciliation records; provider
balances; Wallet ledger rows; provider-specific state machines; direct bank/card adapters for
platform billing.

## Security implications

No provider secrets in Servana `.env` for billing collection. Webhook verification and
machine credentials per ADR-015. `NoDirectProviderIntegrationTest` and Plan §9 rule 20 guard
against regression.

## Tenant / isolation implications

Wallet webhooks resolve tenant via `wallet_payment_id → subscription_invoice → merchant_id`.
Cross-tenant application is denied at the invoice row lock; unknown payments open reconciliation
exceptions, never guess a tenant.

## Migration implications

No provider tables are created in Servana. Phase 20B ships nullable Wallet projection columns
only; Phase 20D-W adds integration tables per `billing-and-wallet.md`. Expand-and-contract only;
never edit shipped migrations.

## Rollout sequence

1. v4 plan-adoption PR (this ADR + guards + data dictionary — **no runtime**).
2. Phase 20A–20C (no Wallet dependency).
3. External Gate W open (Wallet sandbox contract pinned).
4. Phase 20D-W (Servana integration runtime).

## Rejected alternatives

- **Direct Daraja in Servana (v3 Phase 20D):** rejected — conflicts with Wallet product
  mandate and Gate W sequencing.
- **Stub Wallet in production:** rejected — Plan §80.2 forbids partial/simulated Wallet in
  production paths.

## Consequences

Positive: single provider truth, smaller PCI/provider scope in Servana, aligned with sibling
Citrus products. Negative: external Gate W dependency; contract pins required before 20D-W code.

## Test requirements

- `NoDirectProviderIntegrationTest` green (no direct provider symbols/routes/config).
- Phase 20D-W: webhook verification, idempotent registration, exactly-once application,
  reconciliation exception paths (future).

## Review requirements

Plan-adoption PR review verifies ADR presence, permission renames, and guard tests before any
Phase 20A code merges.

## Superseded decisions

- **ADR-006 (M-Pesa callback security, direct Daraja):** superseded for current architecture;
  retained in Plan §8 as historical evidence only.

## Deferred external-contract pins

Wallet OpenAPI event names, exact signing algorithm/header names, and monotonic
`resource_version`/`state_sequence` field name — pinned at **External Gate W** (Plan §80.2)
before Phase 20D-W implementation.
