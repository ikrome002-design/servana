# Solo-Maintainer Review Exception - PR #37

**Date:** 2026-07-12
**Product:** Servana by Citrus
**Pull request:** #37 - Phase 20C: Implement promotions and free periods
**Branch:** phase-20c-promotions-free-periods
**Verified implementation head:** 782c97313ea988d2263e35d44c325d2c7ccb25ec
**Initial successful CI run:** 29191160816
**Product owner and sole eligible repository maintainer:** Project owner

## Reason

The repository currently has one eligible maintainer. An independent reviewer
is unavailable. No GitHub approval has been fabricated, and reviewDecision
remains intentionally blank.

## Scope

This exception applies only to PR #37 and Phase 20C.

Phase 20C implements platform-governed promotional discounts and free-period
offers, normalized target rows, deterministic target resolution, immutable
subscription and invoice snapshots, approval and lifecycle state machines,
platform-only permissions and APIs, typed high-severity audit coverage, the
Super Administrator promotions surface, and merchant read-only applied-snapshot
presentation.

## Compensating controls

- PR #37 targets main.
- Backend CI passed.
- Frontend CI passed.
- Docker CI passed.
- Security CI passed.
- Linux E2E - Playwright CI passed.
- Full backend serial and parallel suites each passed with 1458 tests and 7 documented skips.
- The Phase 20C backend group passed with 110 tests.
- The billing regression group passed with 386 tests.
- The permission group passed with 92 tests.
- Vitest passed with 317 tests.
- Phase 20C Playwright passed with 18 tests.
- OpenAPI contained 219 operations and generation was byte-deterministic.
- OpenAPI/TypeScript contract parity passed.
- Permission YAML/PHP/database/TypeScript parity passed.
- Pint passed across 1189 files.
- Larastan level 8 reported no errors.
- ESLint, vue-tsc and the production frontend build passed.
- Composer validation and audit passed.
- The npm high-severity audit gate passed; two moderate development-dependency advisories were recorded truthfully.
- Gitleaks found no leaks.
- PHP development, PHP production and nginx production images built.
- Schema, raw-SQL constraints, normalized targeting and target ULIDs were tested.
- Promotion and free-period state transitions were tested.
- Resolver precedence and deterministic tie-breaking were tested.
- Percentage and fixed-amount discount arithmetic were tested.
- Trial anchoring and free-period snapshot behavior were tested.
- Invoice discount snapshot and invoice-number rollback behavior were tested.
- Existing subscriptions and issued invoices remain immutable.
- Super Administrator permission, MFA and fresh-step-up enforcement were tested.
- Merchant roles are denied the platform mutation surface.
- Responsive widths, 200 percent zoom, dark mode, keyboard behavior and axe gates passed.
- No Wallet client, registration, outbox, payment runtime, provider credential or provider callback was added.
- No percentage-fee ledger, compensation, payout or referral runtime was added.
- No independent approval is claimed.

## Decision

As product owner and sole eligible maintainer, I authorize PR #37 to merge after
all required checks pass on the governance commit.

This is a governance exception, not reviewer approval.

## Limitation

This exception applies only to PR #37. Future Wallet, payment, financial-ledger
and compensation phases should receive independent review when an eligible
reviewer becomes available.
