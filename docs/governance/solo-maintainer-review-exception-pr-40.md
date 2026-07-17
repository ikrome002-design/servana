# Solo-Maintainer Review Exception - PR #40

**Date:** 2026-07-17
**Product:** Servana by Citrus
**Pull request:** #40 - Hardening: Fix resource contracts and accessibility tokens
**Branch:** hardening/resource-contracts-and-accessibility-tokens
**Verified implementation head:** cdcb83fc89d89b2139063ce0c099ec1a84ee7748
**Initial successful CI run:** 29588324838
**Product owner and sole eligible repository maintainer:** Project owner

## Reason

The repository currently has one eligible maintainer. An independent reviewer
is unavailable. No GitHub approval has been fabricated, and reviewDecision
remains intentionally blank.

## Scope

This exception applies only to PR #40 and the post-Phase-20F deferred
hardening branch.

The branch fixes two deferred hardening items recorded by Phase 20F:

1. repo-wide nullable Resource/OpenAPI contract truth drift;
2. repo-wide dark-mode heading and warning-badge contrast drift.

The branch does not implement product features, migrations, permissions, new
routes, Wallet/provider runtime, salary/commission ledgers, payouts, earnings,
Phase 20D-W, Phase 20G or Phase 20H.

## Compensating controls

- PR #40 targets main.
- Backend CI passed.
- Frontend CI passed.
- Docker CI passed.
- Security CI passed.
- Linux E2E - Playwright CI passed.
- Local backend serial suite passed with 1469 tests and 7 documented skips.
- Local backend parallel suite passed with 1469 tests and 7 documented skips.
- Local OpenAPI contract check passed with 207 paths and 248 operations.
- Local vue-tsc was clean after truthful nullable generated types.
- Local Vitest passed with 404 tests across 84 files.
- Local full Playwright passed with 368 tests.
- Local accessibility coverage reported zero serious or critical axe violations for newly covered states.
- Composer audit passed.
- npm high-severity audit gate passed with two moderate advisories below the high gate.
- Gitleaks found no leaks.
- Docker dev app, production app and production nginx images built.
- No independent approval is claimed.

## Decision

As product owner and sole eligible maintainer, I authorize PR #40 to merge after
all required checks pass on the governance commit.

This is a governance exception, not reviewer approval.

## Limitation

This exception applies only to PR #40. Future product, payment, Wallet,
salary, commission, payout and production-readiness work should receive
independent review when an eligible reviewer becomes available.
