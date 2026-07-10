# Refer & Earn Integration — Data Dictionary (Plan §13.17, §58B; Phases 21R-A, 21R-B)

> **Architecture specification only.** No business migrations ship in the v4 plan-adoption PR.
> Servana implements referral capture, signed outbound facts, qualification decisions, and
> inbound reconciliation — **not** reward ledgers, referrer accounts, or payouts (ADR-013).
>
> **Ownership:** Servana owns referral-activity truth and qualification answers. **R&E owns
> referral-reward truth** (campaigns, calculation, ledger, payouts).

---

## Controlling sources

- Plan §13.17, §58B, §80.1 (21R-A parallel with 20C..20E; 21R-B dependencies)
- ADR-013, ADR-015
- `Refer_and_Earn_Project_Scope.md` + `Citrus_Refer_and_Earn_Production_Software_Development_Plan.md`
  (**not present in this repository** — contract pins deferred to Phase 21R-A entry)

---

## Tables (future phases)

### `referral_snapshots` (21R-A)

Append-only capture at merchant registration when a referral code is present.

| Column (summary) | Notes |
|---|---|
| `merchant_id` | Tenant scope |
| `referral_code` | As presented (normalized per R&E contract when pinned) |
| `captured_at` | UTC |
| `source` | e.g. registration form |
| `re_attribution_status` | Projection of R&E confirm outcome when known |

**Data minimization:** no client PII beyond what registration already stores; no reward amounts.

### `re_outbound_events` (21R-A)

Transactional outbox for Servana→R&E facts (same DB transaction as the business mutation).

| Column (summary) | Notes |
|---|---|
| `merchant_id` | Bound at insert inside tenant transaction |
| `event_type` | Plan §58B catalogue |
| `payload_json` | Schema-validated against pinned R&E contract |
| `sequence_no` | Per-merchant ordering for dispatcher |
| `delivery_status` | pending / delivered / dead_letter |
| `idempotency_key` | Stable per business fact |

### `re_event_deliveries` (21R-A)

Delivery attempts, HTTP status, next retry — append-only attempt log.

### `re_activity_rule_versions` (21R-B)

Prospective Servana-specific qualification rule versions (platform-managed; effective forward only).

### `re_qualification_periods` (21R-B)

Bounded evaluation windows per merchant/fact class.

### `re_qualification_decisions` (21R-B)

Final Servana authority decisions with evidence tuple references; corrections via
`platform.referral.qualification.correct` (step-up, engine re-run — never free-form override).

### `re_inbound_requests` (21R-A)

Signed R&E→Servana reconciliation queries; verified per ADR-015 inbound secret.

---

## Append-only and immutability

- Outbox and delivery logs: append-only; no UPDATE/DELETE of emitted facts.
- Qualification decisions: supersede-by-reference on correction (monotonic `decision_version`).

---

## Forbidden in Servana

| Forbidden | Owner |
|---|---|
| Referrer account balances | R&E |
| Reward ledger / payout batches | R&E |
| Campaign definition as system of record | R&E |
| Payout amount calculation | R&E |

---

## Permissions (planned; adoption PR metadata only)

- `platform.integrations.refer_earn.manage` — rule versions, dead-letter replay, inbound key sets
- `platform.referral.qualification.view` — decisions + evidence summaries (no client PII in evidence)
- `platform.referral.qualification.correct` — documented correction re-run only

Merchant roles receive **none** of the above.

---

## Audit events (future)

`activity.qualification_decided`, `activity.qualification_corrected`, outbox delivery failures —
owned by Phases 21R-A/21R-B; not fabricated in adoption PR.

---

## Migration manifest

Manifest entries added when 21R-A/21R-B migrations ship — not in adoption PR.
