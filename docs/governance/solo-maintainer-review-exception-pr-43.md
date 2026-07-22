# Solo-Maintainer Review Exception - PR #43

**Date:** 2026-07-22
**Product:** Servana by Citrus
**Pull request:** #43 - Phase 20H: Implement payout runs and earnings
**Branch:** phase-20h-payout-runs-earnings
**Previous failing PR head:** 309057c2f29e492bbc2602714d9c7e52ea1014b4
**Verified fixed PR head:** 16c368a96dbd3d53a5bb7fda8a3b39e55ac46b92
**Successful pre-governance CI run:** 29889697667
**Product owner and sole eligible repository maintainer:** Project owner

## Reason

The repository currently has one eligible maintainer. An independent reviewer
is unavailable. No GitHub approval has been fabricated, and reviewDecision
remains intentionally blank.

## Scope

This exception applies only to PR #43 and Phase 20H.

Phase 20H implements payout runs and earnings surfaces after Phase 20G created
salary and commission liabilities. It includes payout-run lifecycle behavior,
payout item generation, payout verification and approval behavior, mark-paid
workflow behavior, personnel earnings visibility, earnings statements, earnings
queries, Merchant Administrator compensation summary, permission activation,
API contracts, frontend surfaces, audit coverage, and test/proof evidence.

A test-only follow-up commit corrected stale hand-maintained permission test
expectations after Phase 20H activated the intended payout and earnings keys.
The product permission registry truth was preserved.

## Compensating controls

- PR #43 targets main.
- Backend CI passed after the test-only fix.
- Frontend CI passed after the test-only fix.
- Docker CI passed after the test-only fix.
- Security CI passed after the test-only fix.
- Linux E2E - Playwright CI passed after the test-only fix.
- Successful pre-governance CI run: 29889697667.
- Fixed PR head: 16c368a96dbd3d53a5bb7fda8a3b39e55ac46b92.
- Local targeted tests for the two fixed files passed: 5 passed, 143 assertions.
- Local Auth group passed: 76 passed, 564 assertions.
- Local Phase20HPermissionActivationTest passed from the actual Auth path: 6 passed, 193 assertions.
- Local route-security and audit coverage tests passed: 17 passed, 883 assertions.
- Local PermissionPlannedKeyIsolationTest passed: 2 passed.
- Pint on the two changed test files was clean.
- Full Phase 20H local closure gates had passed before the CI test-expectation hold.
- No application code was changed by the test-only fix.
- No permission source-of-truth was changed by the test-only fix.
- No generated API contract was changed by the test-only fix.
- No Wallet/provider runtime was added.
- No direct money movement was added.
- No notification-center runtime was added.
- No scheduled report delivery was added.
- No independent approval is claimed.

## Decision

As product owner and sole eligible maintainer, I authorize PR #43 to merge after
all required checks pass again on the governance commit.

This is a governance exception, not reviewer approval.

## Limitation

This exception applies only to PR #43. Future payout, Wallet, settlement,
notification, reporting, and production-readiness work should receive
independent review when an eligible reviewer becomes available.
