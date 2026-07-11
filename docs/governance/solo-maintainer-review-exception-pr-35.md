# Solo-Maintainer Review Exception - PR #35

**Date:** 2026-07-11
**Product:** Servana by Citrus
**Pull request:** #35 - Phase 20A: Implement billing catalogue settings and fee rules
**Branch:** phase-20a-billing-catalogue-settings
**Verified implementation head:** a31cd000f84a0a19f1d8b526a4fdf5d01aefc090
**Initial successful CI run:** 29145005108
**Product owner and sole eligible repository maintainer:** Project owner

## Reason

The repository currently has one eligible maintainer. An independent reviewer
is unavailable. No GitHub approval has been fabricated, and reviewDecision
remains intentionally blank.

## Scope

This exception applies only to PR #35 and Phase 20A.

Phase 20A implements the billing catalogue, versioned prices, entitlements,
billing settings, preferred-personnel fee rules, Phase 20A permission
reconciliation, audit events and the related platform and branch frontend
surfaces.

## Compensating controls

- PR #35 targets main.
- Backend CI passed.
- Frontend CI passed.
- Docker CI passed.
- Security CI passed.
- Linux E2E - Playwright CI passed.
- PostgreSQL constraints and effective-dated financial configuration were tested.
- Backend serial and parallel suites each passed with 1164 tests and 7 documented skips.
- Vitest passed with 279 tests.
- Full local Playwright passed with 269 tests.
- Pint and Larastan level 8 passed.
- OpenAPI and generated TypeScript parity passed.
- Permission YAML, PHP, database and TypeScript parity passed.
- MFA and billing-configuration step-up were tested.
- Tenant and authorization boundaries were tested.
- Responsive, dark-mode, keyboard and axe gates passed.
- Dependency audits, gitleaks and both Docker builds passed.
- Phase 20B and later billing domains were not implemented.
- No independent approval is claimed.

## Decision

As product owner and sole eligible maintainer, I authorize PR #35 to merge after
all required checks pass on the governance commit.

This is a governance exception, not reviewer approval.

## Limitation

This exception applies only to PR #35. Future financial and integration phases
should receive independent review when an eligible reviewer becomes available.
