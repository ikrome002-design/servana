# Solo-Maintainer Review Exception - PR #29

**Date:** 2026-07-01
**Product:** Servana by Citrus
**Pull request:** #29 - Phase 17: Implement invoicing
**Branch:** phase-17-invoicing
**Verified implementation head:** c0fdd83ea539f1ccdaf9232ef9a1b8b5a027d45e
**Initial successful CI run:** 28516753439
**Product owner and sole eligible repository maintainer:** Project owner

## Reason

The repository currently has one eligible maintainer. An independent reviewer
is unavailable. No GitHub approval has been fabricated, and reviewDecision
remains intentionally blank.

## Scope

This exception applies only to PR #29 and Phase 17.

Phase 17 implements merchant-client invoice drafting, deterministic
finalization, immutable price and fee snapshots, gap-free merchant numbering,
idempotency, Finance void and additive adjustment workflows, period-lock
enforcement, billing restrictions, role boundaries, tenant and branch
isolation, audit evidence, and invoice frontend workflows.

## Compensating controls

- PR #29 targets main.
- Backend CI passed.
- Frontend CI passed.
- Docker CI passed.
- Security CI passed.
- Linux E2E - Playwright CI passed.
- PostgreSQL migrations and financial constraints were tested.
- Full backend suite passed with 892 tests and 7 documented skips.
- Vitest passed with 191 tests.
- Pint and Larastan level 8 passed.
- Replay cannot allocate a second number.
- Replay cannot duplicate invoice items or success audit events.
- Transaction rollback cannot consume an invoice number.
- Finalized price and preferred-fee snapshots are immutable.
- Duplicate completed-session invoicing is prevented.
- Tenant and branch isolation were tested.
- Front Office creation and Finance correction ownership were tested.
- Unauthorized roles were denied.
- Public APIs expose ULIDs rather than internal sequential identifiers.
- Client contact remains masked.
- No payment, receipt, refund, or commission-ledger subsystem was added.
- No independent approval is claimed.

## Financial-integrity limitations preserved

Payment recording, payment validation, receipts, refunds, disputes, and
financial-period-lock management remain owned by Phases 18A and 18B.

Percentage platform-fee configuration and ledger behavior remain owned by
Phase 20E.

Preferred-personnel fee rule administration remains owned by Phase 20A.

## Decision

As product owner and sole eligible maintainer, I authorize PR #29 to merge after
all required checks pass on the governance commit.

This is a governance exception, not reviewer approval.

## Limitation

This exception applies only to PR #29. Future security-sensitive and financial
work should receive independent review when an eligible reviewer becomes
available.
