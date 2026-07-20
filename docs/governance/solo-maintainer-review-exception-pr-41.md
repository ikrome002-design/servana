# Solo-Maintainer Review Exception - PR #41

**Date:** 2026-07-20
**Product:** Servana by Citrus
**Pull request:** #41 - Phase 20G: Implement salary and commission ledgers
**Branch:** phase-20g-salary-commission-ledgers
**Verified implementation head:** 51ebb5dd0c44c858c7afadd828dea5891da17fa0
**Initial successful CI run:** 29739428584
**Product owner and sole eligible repository maintainer:** Project owner

## Reason

The repository currently has one eligible maintainer. An independent reviewer
is unavailable. No GitHub approval has been fabricated, and reviewDecision
remains intentionally blank.

## Scope

This exception applies only to PR #41 and Phase 20G.

Phase 20G creates append-only, tenant-scoped, branch-scoped, idempotent salary
and commission financial liability facts. It implements salary ledger,
commission ledger, compensation adjustments, selected-service commission
membership needed for truthful calculation, suspension salary policy,
Finance liability visibility, Finance adjustment creation, contract updates,
frontend liability surfaces, audit coverage, and proof.

Phase 20G does not implement Wallet/provider runtime, payout runs, payout items,
earnings statements, earnings queries, mark-paid flows, Merchant Administrator
compensation summary, Phase 20H payout runtime, Phase 20D-W Wallet runtime, or
direct money movement.

## Compensating controls

- PR #41 targets main.
- Backend CI passed.
- Frontend CI passed.
- Docker CI passed.
- Security CI passed.
- Linux E2E - Playwright CI passed.
- Local Composer validation passed.
- Local Pint passed across 1398 files.
- Local Larastan level 8 reported no errors across 1077 files.
- Local full backend serial passed with 1578 tests, 0 failures, 7 documented skips, and 9088 assertions.
- Local full backend parallel passed with 1578 tests, 0 failures, and 7 documented skips.
- Local ESLint reported 0 errors.
- Local vue-tsc was clean.
- Local Vitest passed with 435 tests across 87 files.
- Local production build passed.
- Local full Playwright passed with 397 tests and 0 failures.
- Local axe serious/critical result was zero.
- Local OpenAPI contained 212 paths and 254 operations.
- OpenAPI, generated API TypeScript, and generated permission TypeScript were deterministic.
- Permission projection and contract checks passed.
- Local composer audit found no advisories.
- Local npm high-severity audit gate passed.
- Local gitleaks found no leaks.
- Docker dev app, production app, and production nginx images built.
- Disposable PostgreSQL 16 proof passed from zero migrations.
- Forbidden payout and earnings tables were absent.
- No Wallet/provider runtime was added.
- No payout or earnings runtime was added.
- No independent approval is claimed.

## Decision

As product owner and sole eligible maintainer, I authorize PR #41 to merge after
all required checks pass on the governance commit.

This is a governance exception, not reviewer approval.

## Limitation

This exception applies only to PR #41. Future payout, earnings, Wallet,
settlement, notification, reporting, and production-readiness phases should
receive independent review when an eligible reviewer becomes available.
