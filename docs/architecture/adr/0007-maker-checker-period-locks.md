# ADR-0007 — Maker/Checker Separation and Financial Period Locks

- **Status:** Accepted (Phase 18B)
- **Date:** 2026-07-02
- **Deciders:** Servana engineering (solo-maintainer governance exception — not
  independent review)
- **Controlling sources:** Plan §2.1, §5.3 (REM-PAY-001, REM-SM-001, REM-DDL-001),
  §8 (ADR-005/ADR-007), §9.3, §10/§19 (role boundaries + canonical permission
  matrix), §19.3 (step-up), §41–§46 (validation, receipts, refunds, disputes,
  cash-up, period locks), §80 (Phase 18B), §85 (traceability); Scope §4.5, PART B.
- **Supersedes for the finance domain:** the Phase 17 always-open period-lock
  binding (`UnlockedPeriodLockRepository`).

Related: [ADR-0002 tenancy](0002-tenancy-enforcement-model.md),
[ADR-0003 idempotency](0003-idempotency-and-replay-protection.md),
[ADR-0008 audit immutability](0008-audit-immutability-and-chain.md).

---

## Context

Phase 18A made merchant-client payment **recording** durable but deliberately left
the money **controls** to Phase 18B: no group validation, no receipts, no refunds,
no disputes, no cash-up reconciliation, and no database-backed period locks (the
Phase 17 `PeriodLockRepository` was bound to an always-open stub). Phase 18B must
close the auditable money lifecycle:

```
recorded group → Finance group validation/rejection/correction
→ invoice validated balance + payment state → one automatic receipt
→ branch cash-up + reconciliation → financial period lock
→ external refund/dispute controls → scoped finance export
```

Two cross-cutting mechanisms govern the whole lifecycle and are therefore recorded
here once, rather than re-decided per table: **maker/checker separation** and
**financial period locks** (including the exceptional reopen path).

## Decision 1 — Maker/checker separation is a hard, permission-level invariant

Money-affecting decisions require two distinct human principals: the **maker**
(who records/requests) and the **checker** (who validates/approves). Separation is
enforced at three layers, in this order of authority:

1. **Permission incompatibility (registry-level).** A single role/user may not
   hold both sides of a maker/checker pair. The registry rejects any grant that
   would combine an incompatible pair. Phase 18B pairs:

   | Maker key | ⟂ Checker key |
   |---|---|
   | `customer_payment.record` | `customer_payment.validate` |
   | `customer_payment.record_exception` | `customer_payment.validate` |
   | `branch.cash_up.submit` | `cash_up.approve` |
   | `refund.create` | `refund.approve` |
   | `period_lock.reopen` | `merchant.period_reopen.approve_exception` |

2. **Actor identity (per-transaction).** Even where an operator legitimately holds
   a checker key, the checker user id must differ from the recorded maker user id
   of the specific artifact. A Finance operator who recorded a payment under the
   `customer_payment.record_exception` path may not later validate that same group;
   the Branch Manager who submitted a cash-up may not approve it. Enforced by a
   dedicated guard (`PaymentMakerCheckerGuard` for payments; equivalent inline
   guards for refunds, cash-up and reopen) → `403 maker_is_checker`.

3. **State machine (per-artifact).** A decision may only be taken from the correct
   source state (e.g. a group must be `pending_validation` to validate) → an
   out-of-state attempt returns `422 invalid_state_transition`.

There is **no** "small-team bypass". A solo operator cannot self-validate their own
recording; the correct answer is that recording and validation are separate role
grants and the same person cannot do both for the same artifact. If no eligible
distinct checker exists, the artifact simply waits — the invariant is not relaxed.

## Decision 2 — Financial period locks are database-backed and enforced everywhere

Phase 18B replaces `UnlockedPeriodLockRepository` with a database-backed
`DatabasePeriodLockRepository` reading `financial_period_locks`. The
`FinancialPeriodGuard` contract is unchanged, so every existing Phase 17/18A
financial mutation gains real enforcement with no call-site change.

- **Scope.** A lock is either **merchant-wide** (`branch_id = null`) or
  **branch-specific** (`branch_id` set). A business date is locked if a
  merchant-wide lock **or** a matching branch-specific lock covers it. Enforcement
  uses the mutation's effective **business date** in `Africa/Nairobi`.
- **Response.** A blocked mutation returns `HTTP 423` with
  `error.code = financial_period_locked`. Pure reads are never blocked.
- **Coverage (Plan §19.3 `PL=enforced`).** invoice finalization/void/adjustment;
  payment recording + exception recording; duplicate override + reference
  correction; group validation/rejection/correction; refund
  request/approval/finalization; cash-up submit/approve/reject/request-correction;
  and the period-lock create/reopen workflow itself where the affected date is
  already inside another active lock.
- **Explicitly NOT locked (Plan `PL n/a`).** `receipt.reissue`,
  `finance_dispute.manage`, `finance_export.create`, `finance_export.download`.
  These are audit/derived/reporting operations that must remain available after a
  period closes; adding a lock here would be incorrect.

## Decision 3 — Period locking is Finance-owned; exceptional reopen is maker/checker-separated (Gate F)

- **Routine locking** (`period_lock.create`) is owned by **Finance**. Creating a
  lock requires no external approval but is audited and requires the actor to hold
  the key (which is incompatible with none of the recording keys, but is a Finance
  role key). A lock may not overlap an existing active lock for the same scope.
- **Routine reopen execution** (`period_lock.reopen`) is owned by **Finance**. A
  reopen requires a mandatory reason and a fresh MFA step-up (§19.3), and is
  audited.
- **Exceptional reopen** — where policy marks a period as requiring higher
  authority to reopen — additionally requires a **Merchant Administrator** to
  approve via `merchant.period_reopen.approve_exception`. The Merchant
  Administrator has **no** routine locking or reopen-execution authority; the
  Finance reopener has **no** exception-approval authority (`period_lock.reopen ⟂
  merchant.period_reopen.approve_exception`). The **same user may not both request
  and approve** an exceptional reopen.

### Reopen sequence (schema-minimal)

The workflow is expressed on `financial_period_locks` plus the smallest set of
request/approval columns, avoiding a separate request table:

1. **request** — a Finance user calls the reopen endpoint on a `locked` row with a
   reason. If the lock is `exception_required`, the row records
   `reopen_requested_by` + `reopen_reason` + `reopen_requested_at` and stays
   `locked` (a request, not yet an execution). If it is not exception-required, the
   Finance user proceeds directly to execution (steps 2–3 collapse).
2. **approve (exception only)** — a Merchant Administrator holding
   `merchant.period_reopen.approve_exception`, who is **not** the requester, sets
   `reopen_approved_by` + `reopen_approved_at`.
3. **execute** — a Finance user holding `period_lock.reopen` with fresh MFA
   transitions the lock `locked → reopened`, recording `reopened_by`,
   `reopened_at`. For an exception-required lock, execution is refused unless an
   approval by a distinct Merchant Administrator is present.

Each step emits a typed audit event (`financial_period.reopen_requested`,
`.reopen_approved`, `.reopened`) with masked context; `financial_period.locked` is
emitted at creation.

The `exception_required` flag is set at lock creation from the existing
merchant/policy configuration inspected during Gate F; Phase 18B does **not**
invent a new policy engine — absent an explicit exceptional-policy signal, a lock
is routine-reopenable by Finance, and the Merchant Administrator approval path is
exercised only when the flag is set.

## Decision 4 — Rounding and multi-component allocation (integer minor units)

All money is integer minor units via the `Money` value object (never float). Where
a single amount must be split across multiple components or items — the only Phase
18B case is a refund/reversal that spans several validated components — the split
uses **largest-remainder allocation** by validated-amount weight: compute each
share as `floor(total * weight_i / Σweight)`, then distribute the leftover minor
units one-by-one to the components with the largest fractional remainders (ties
broken by ascending component id for determinism). This guarantees the parts sum
exactly to the whole with no minor unit created or lost. Implemented as
`Money::allocateByWeights()` and unit-tested. (This is the rule ADR-005 would have
recorded; ADR-005 is not present in the repository, so the rule is stated here and
referenced by the refund workflow.)

## Consequences

- **Positive.** One place defines separation and locking; every finance action is
  consistent; enforcement is at the security boundary (backend), not the UI; the
  existing guard contract means zero call-site churn for period locks; the reopen
  path proves maker/checker separation at the schema level.
- **Negative / trade-offs.** The `exception_required` policy signal is minimal
  (a boolean sourced from existing config) rather than a full policy engine —
  acceptable for Phase 18B and revisited if richer policy is required. A solo
  operator genuinely cannot complete a two-person flow alone; this is intended.
- **Neutral.** No new idempotency, billing, or audit infrastructure is introduced;
  Phase 18B reuses R4 idempotency, the billing-mutation gate, and the append-only
  hash-chained audit log.

## Compliance / verification

- `Phase18BMakerCheckerTest`, `PeriodReopenGovernanceTest`,
  `FinancialPeriodLockTest`, `FinancialMutationLockCoverageTest`,
  `PermissionMatrixTest` (incompatibilities), and the per-workflow feature tests
  prove each decision. See `docs/proof/phase-18b.md`.
