# Solo-Maintainer Review Exception - PR #36

**Date:** 2026-07-12
**Product:** Servana by Citrus
**Pull request:** #36 - Phase 20B: Implement subscription lifecycle and invoices
**Branch:** phase-20b-subscription-lifecycle-invoices
**Verified implementation head:** 6790081bace7efb2a659ec8254e6eda53d3d5935
**Initial successful CI run:** 29183137798
**Product owner and sole eligible repository maintainer:** Project owner

## Reason

The repository currently has one eligible maintainer. An independent reviewer
is unavailable. No GitHub approval has been fabricated, and reviewDecision
remains intentionally blank.

## Scope

This exception applies only to PR #36 and Phase 20B.

Phase 20B implements merchant subscription lifecycle, trial anchoring,
transactional merchant billing-status projection, five-interval date
calculation, no-proration scheduled plan changes, immutable subscription
invoices and items, private invoice PDFs, billing escalation, registration
monitoring, merchant governance, Phase 20B permissions, typed audit events and
the related frontend surfaces.

## Compensating controls

- PR #36 targets main.
- Backend CI passed.
- Frontend CI passed.
- Docker CI passed.
- Security CI passed.
- Linux E2E - Playwright CI passed.
- Backend serial and parallel suites each passed with 1348 tests and 7 documented skips.
- Vitest passed with 308 tests.
- Full local Playwright passed with 292 tests.
- Phase 20B Playwright passed with 23 tests.
- OpenAPI contained 203 operations.
- Pint and Larastan level 8 passed.
- ESLint, vue-tsc and the production frontend build passed.
- Composer validation and audit passed.
- The npm high-severity audit gate passed; two moderate development-dependency advisories were recorded truthfully.
- Gitleaks found no leaks.
- PHP development and nginx production images built.
- Trial-anchor, date-boundary, plan-change, invoice, billing-gate and scheduler behavior were tested.
- Tenant isolation and route authorization were tested.
- Billing and operational merchant states remain independent.
- Existing authorized files remain downloadable in read-only billing states.
- New PDF, report and export generation is blocked by the billing gate.
- Wallet projection fields remain null or unregistered.
- No Wallet client, registration call, outbox, payment runtime, provider credential or provider callback was added.
- No merchant-creation, first-admin-creation or impersonation path was added.
- Responsive, dark-mode, keyboard and axe gates passed.
- No independent approval is claimed.

## Decision

As product owner and sole eligible maintainer, I authorize PR #36 to merge after
all required checks pass on the governance commit.

This is a governance exception, not reviewer approval.

## Limitation

This exception applies only to PR #36. Future financial, Wallet-integration and
payment phases should receive independent review when an eligible reviewer
becomes available.
