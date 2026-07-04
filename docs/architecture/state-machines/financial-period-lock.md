# Financial Period Lock — State Machine (Plan §46; Phase 18B; ADR-0007)

> Authoritative lifecycle for `financial_period_locks`. Replaces the Phase 17
> always-open `UnlockedPeriodLockRepository` with a database-backed
> `DatabasePeriodLockRepository`. Status changes go through named actions
> (`CreateFinancialPeriodLock`, `RequestPeriodReopen`, `ApprovePeriodReopenException`,
> `ExecutePeriodReopen`). No direct arbitrary status assignment. See ADR-0007
> §Decision 2/3 and the data dictionary Gate F.

## States

| State | Meaning |
|---|---|
| `locked` | Finance created a lock (`period_lock.create`) over `[period_start, period_end]` for a merchant-wide (`branch_id=null`) or branch scope. Financial mutations whose business date falls inside return `423 financial_period_locked`. |
| `reopened` | The lock was reopened via the controlled workflow; the period accepts mutations again. Terminal (a fresh lock is a new row). |
| `open` | Reserved for an explicitly opened-but-recorded period; not written by the routine flow (a period with no `locked` row is simply open). |

## Transitions

```text
(create)  -> locked                                   [CreateFinancialPeriodLock]  (Finance; no overlapping active lock)

# Routine reopen (exception_required = false):
locked    -> reopened   [RequestPeriodReopen -> ExecutePeriodReopen]  (Finance; reason; fresh MFA)

# Exceptional reopen (exception_required = true) — maker/checker separated:
locked    -> locked     [RequestPeriodReopen]              (Finance requester; records reason; stays locked)
locked    -> locked     [ApprovePeriodReopenException]     (Merchant Admin ≠ requester; records approval)
locked    -> reopened   [ExecutePeriodReopen]             (Finance; fresh MFA; requires a distinct MA approval)
```

Any other transition is invalid → `422 invalid_state_transition`.

## Invariants (ADR-0007 §Decision 3, Gate F)

- `period_start <= period_end`; **no overlapping active lock** for the same scope
  (PostgreSQL exclusion constraint over merchant, normalized branch key and
  `daterange`, WHERE `status='locked'`).
- Scope is explicit: merchant-wide (`branch_id=null`) or branch-specific. A date is
  locked if a merchant-wide **or** matching branch lock covers it.
- Finance owns `period_lock.create` and `period_lock.reopen` (execution). Reopen
  requires a mandatory reason and fresh MFA (§19.3), and is audited.
- Exceptional reopen (flagged `exception_required`) additionally requires a Merchant
  Administrator `merchant.period_reopen.approve_exception` **distinct** from the
  requester. `period_lock.reopen ⟂ merchant.period_reopen.approve_exception`; the
  same user may not request and approve. The Merchant Administrator has no routine
  locking / execution authority.
- `exception_required` is sourced from existing merchant configuration at creation;
  no new policy engine is invented.

## Enforcement coverage (Plan §19.3 `PL=enforced`)

invoice finalization/void/adjustment; payment recording + exception; duplicate
override + reference correction; group validation/rejection/correction; refund
request/approve/finalize; cash-up submit/approve/reject/request-correction; the
period-lock create/reopen workflow where the date is inside another active lock.
**NOT** locked (`PL n/a`): `receipt.reissue`, `finance_dispute.manage`,
`finance_export.create`, `finance_export.download`. Pure reads are never blocked.

## Audit / failure codes

Events: `financial_period.locked`, `.reopen_requested`, `.reopen_approved`,
`.reopened`. Codes: `422 invalid_state_transition`, `422 overlapping_period_lock`,
`423 financial_period_locked`, `403` (permission / maker-is-checker / stale
step-up), `404` (foreign tenant).
