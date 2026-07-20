# Servana report catalogue

Canonical report definitions (Plan §80). **Ownership split:** the owning feature phase defines a
report's *semantics* (metric formula, scope, filters, currency, included states, data source, PII
class); **Phase 21N** owns scheduled delivery, subscriptions, notifications, and orchestration. A
definition here is a contract, not an implementation of scheduled delivery.

This file is seeded by **Phase 20G** with the Finance compensation-liability definitions (Plan §61/§80,
Correction 21.2). Other phases append their own report definitions.

---

## Phase 20G — compensation liabilities (Finance)

All four reports: **owner phase 20G**; **delivery/orchestration owner 21N**; **permission**
`compensation.liability.view` (Finance; merchant scope; MFA); **timezone** `Africa/Nairobi`; **currency**
integer minor units, **grouped by currency, never combined**; **data source** the append-only
`salary_ledger`, `commission_ledger`, `compensation_adjustments` facts (server-authoritative read model
`CompensationLiabilityReadModel`); **PII** none beyond the staff public ULID + display name (no contact
data, no internal ids); **export/PDF** not in Phase 20G (deferred to 21N). Money is never recomputed —
the reports read stored facts.

### Compensation Liability Summary
- **Key:** `compensation.liability.summary`
- **Definition:** per-currency net compensation liability owed by the merchant (the "earned-unpaid
  balance").
- **Metric:** `net_salary_liability = Σ salary_ledger.amount_minor WHERE status <> 'paid'`;
  `net_commission_liability = Σ commission_ledger.amount_minor WHERE status NOT IN ('paid','cancelled')`;
  `compensation_adjustment = Σ compensation_adjustments.amount_minor`;
  `combined_net = net_salary + net_commission + compensation_adjustment` (same currency only). Gross
  accrual/earned and reversal sub-totals are exposed alongside the nets.
- **Filters:** `staff_profile_ulid`, `branch_ulid` (within the actor's scope), `currency`.
- **Grouping:** currency. **Excluded states:** `paid` (salary/commission); `cancelled` (commission).
- **API:** `GET /api/v1/compensation/liabilities/summary`.

### Salary Liability Detail
- **Key:** `compensation.liability.salary_detail`
- **Definition:** the salary-ledger accrual/reversal/adjustment rows that compose the net salary
  liability, with pay-period + segment dates.
- **Included entry types:** `accrual`, `reversal`, `adjustment`. **Excluded:** `paid` from the net.
- **Filters:** `staff_profile_ulid`, `branch_ulid`, `entry_type`, `status`, `currency`, `date_from`,
  `date_to` (against `pay_period_end`).
- **API:** `GET /api/v1/compensation/liabilities?liability_type=salary`.

### Commission Liability Detail
- **Key:** `compensation.liability.commission_detail`
- **Definition:** the commission-ledger earned/reversal rows composing the net commission liability, with
  invoice / invoice-item / validation-event references and rule/plan snapshots.
- **Included entry types:** `earned`, `reversal`, `adjustment`. **Excluded:** `paid`, `cancelled` from
  the net.
- **Filters:** `staff_profile_ulid`, `branch_ulid`, `entry_type`, `status`, `currency`, `date_from`,
  `date_to` (against `earned_at`).
- **API:** `GET /api/v1/compensation/liabilities?liability_type=commission`.

### Compensation Adjustment Register
- **Key:** `compensation.adjustment.register`
- **Definition:** the append-only `compensation_adjustments` rows (Finance manual entries + system
  paid-reversal offsets), masked.
- **Filters:** `staff_profile_ulid`, `branch_ulid`, `adjustment_type`, `currency`.
- **API:** `GET /api/v1/compensation/adjustments` (+ `GET /api/v1/compensation/adjustments/{ulid}`).

**Out of scope (owned elsewhere):** payout runs/items, earnings statements, mark-paid, and any
Merchant-Administrator compensation summary → **Phase 20H**; scheduled PDF/email delivery → **Phase 21N**.
