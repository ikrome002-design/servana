# Solo-Maintainer Review Exception - PR #38

**Date:** 2026-07-14
**Product:** Servana by Citrus
**Pull request:** #38 - Phase 20E: Implement percentage platform fee engine
**Branch:** phase-20e-percentage-platform-fees
**Verified implementation head:** f6e208a90513bf5ca1c219c456b263ea0d111c5c
**Initial successful CI run:** 29310417943
**Product owner and sole eligible repository maintainer:** Project owner

## Reason

The repository currently has one eligible maintainer. An independent reviewer
is unavailable. No GitHub approval has been fabricated, and reviewDecision
remains intentionally blank.

## Scope

This exception applies only to PR #38 and Phase 20E.

Phase 20E implements the percentage platform-fee engine, including configuration,
ledger entries, validation-bound billability, subscription-invoice aggregation,
additive reversals and adjustments, dispute workflow, permission reconciliation,
audits, contracts, frontend role surfaces, documentation and proof.

## Compensating controls

- PR #38 targets main.
- Backend CI passed.
- Frontend CI passed.
- Docker CI passed.
- Security CI passed.
- Linux E2E - Playwright CI passed.
- Local full backend serial and parallel suites passed with 1181 tests and 7 documented skips.
- Local Phase 20E targeted suite passed with 138 tests and 431 assertions.
- Local Vitest passed with 352 tests.
- Local full Playwright passed with 324 tests.
- OpenAPI and generated TypeScript contracts were deterministic.
- Permission parity passed across YAML, PHP, database and TypeScript.
- Route security, financial idempotency and audit mutation coverage passed.
- Composer audit and gitleaks passed.
- npm high-severity audit gate passed with two moderate dev-only advisories disclosed.
- Docker dev app, prod app and prod nginx images built.
- No Wallet/provider/payment runtime was added.
- No independent approval is claimed.

## Decision

As product owner and sole eligible maintainer, I authorize PR #38 to merge after
all required checks pass on the governance commit.

This is a governance exception, not reviewer approval.

## Limitation

This exception applies only to PR #38. Future Wallet, payment, settlement,
compensation, payout and production-readiness phases should receive independent
review when an eligible reviewer becomes available.
