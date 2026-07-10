# ADR-013 — Citrus Refer & Earn Integration Authority

- **Status:** Accepted (v4 plan-adoption PR; architecture-only — no runtime integration).
- **Date:** 2026-07-07
- **Required by:** Plan §8.1 ADR-013, §2.2, §58B, Phases 21R-A/21R-B;
  `Refer_and_Earn_Project_Scope.md` + `Citrus_Refer_and_Earn_Production_Software_Development_Plan.md`
  (**not present in this repository**; deferred contract pins at Phase 21R-A entry).
- **Related:** ADR-015 (signing); REM-RE-001;
  `docs/architecture/data-dictionary/refer-earn-integration.md`.

## Context

Citrus Refer & Earn (R&E) is a separate product. Servana is a **source product**: it captures
referrals, emits signed activity facts, answers reconciliation queries, and runs final
qualification decisions for Servana-specific rules. Reward calculation, referrer accounts,
campaigns, ledgers, and payouts belong to R&E — not Servana.

## Authoritative references

| Source | Role |
|---|---|
| Plan §1.2 item 2 | Why R&E integration is mandatory |
| Plan §58B | Event catalogue and qualification authority |
| R&E scope + dev plan (missing from repo) | Event shapes, `X-Citrus-*` signing |

## Proven problem

v3 contained zero R&E references. Without an explicit boundary, Servana could not honor the
cross-product referral contract or emit reconcilable facts for Servana acquisitions.

## Decision

**Servana owns:**

- Referral capture at registration (non-blocking)
- Local referral snapshot (`referral_snapshots`)
- Servana lifecycle and activity facts (subscription, payment, clearing events)
- Servana activity qualification (final authority for Servana-attributed merchants)
- Servana reconciliation answers (inbound signed requests from R&E)
- Outbound event delivery infrastructure (`re_outbound_events`, `re_event_deliveries`)

**R&E owns:**

- Referrer accounts and effective attribution system of record
- Campaigns and reward calculation
- Reward ledger and referrer payouts
- Reward statements

**No reward, referrer-account, or payout tables exist in Servana.**

## Ownership boundary

Servana stores minimal referral snapshots and append-only integration outbox/inbox rows.
R&E stores all monetary reward truth. Servana never computes payout amounts.

## Data stored in Servana

See `refer-earn-integration.md`: `referral_snapshots`, `re_outbound_events`,
`re_event_deliveries`, `re_activity_rule_versions`, `re_qualification_periods`,
`re_qualification_decisions`, `re_inbound_requests`.

## Data forbidden in Servana

Referrer wallets; reward balances; payout batches; R&E campaign definitions as authoritative
source; free-form qualification overrides without engine re-run.

## Security implications

Outbound events signed per R&E contract (ADR-015). Inbound reconciliation verified with
distinct inbound secret. PII minimization in snapshots and evidence tuples.

## Tenant / isolation implications

Outbox rows carry `merchant_public_id` bound inside the originating tenant transaction.
Reconciliation queries scoped to one merchant return only that merchant's facts.

## Migration implications

Tables created in Phases 21R-A/21R-B only. Adoption PR creates data-dictionary specification
only — no business migrations.

## Rollout sequence

1. v4 plan-adoption PR (this ADR + data dictionary + planned permissions).
2. Phases 20A–20D-W (parallel-eligible: 21R-A may start after 20B per Plan §80.1).
3. Phase 21R-B (qualification engine + clearing integration).

## Rejected alternatives

- **Implement R&E reward logic in Servana:** rejected — dual reward truth.
- **Skip referral capture until R&E live:** rejected — breaks attribution window at registration.

## Consequences

Servana remains a fact emitter and qualification authority; R&E remains payout authority.
Operational coupling via signed events and reconciliation API only.

## Test requirements

- Planned permission isolation (`platform.referral.*`, `platform.integrations.refer_earn.*`).
- Phase 21R-A: outbox atomicity, signing, delivery retries, inbound verification (future).

## Review requirements

Adoption PR verifies no R&E runtime code, no reward tables, and traceability rows
`SRV-RE-*` marked `architecture_adopted` / `runtime_not_started`.

## Superseded decisions

None (greenfield integration in v4).

## Deferred external-contract pins

Exact R&E event schema versions, Servana product code assignment, confirm-window for
attribution expiry, and campaign rule sync availability — pinned at **Phase 21R-A entry**
(Plan §82 blocking ambiguities).
