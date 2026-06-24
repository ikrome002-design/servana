# Solo-Maintainer Security Review Exception — PR #21

**Date:** 2026-06-24
**Product:** Servana by Citrus
**Product owner and sole repository maintainer:** Project owner
**Pull request:** #21 — Phase 10: Establish API contract foundation
**Branch:** `phase-10-api-foundation`
**Verified head:** `a6b3e4c`

## Reason

The repository currently has one eligible maintainer. An independent second
reviewer is unavailable. No GitHub approval has been fabricated, and the pull
request review decision is intentionally blank.

## Scope

This exception applies only to PR #21 and Phase 10.

Phase 10 introduces:

- canonical production route classification
- route-security contract enforcement
- pagination, filtering and sorting conventions
- policy-derived resource capability maps
- maintained `dedoc/scramble` OpenAPI generation
- deterministic committed OpenAPI and TypeScript contracts
- migration governance and ADR-004
- an explicit Linux Playwright CI gate
- supporting tests, proof and documentation

## Verification evidence

PR #21 passed all required checks on commit `a6b3e4c`:

- CI/Backend — Pint, Larastan, Pest
- CI/Frontend — ESLint, vue-tsc, Vitest, build
- CI/Docker — build images
- CI/Security — gitleaks
- CI/E2E — Playwright

Additional verified controls:

- all production mutations have exactly one route classification
- forbidden merchant-creation and personnel contact-export routes remain absent
- financial routes retain idempotency coverage
- collection pagination cannot become unlimited
- filters and sorts are allowlisted
- resource capability maps are policy-derived
- OpenAPI generation uses maintained `dedoc/scramble`
- generated OpenAPI and TypeScript contracts are deterministic
- migration-manifest completeness is test-enforced
- serial and parallel backend suites pass
- dependency audits pass
- both Docker images build successfully

## Failure history retained

The first PR #21 Backend run failed because the committed OpenAPI document was
not identical to the document generated in fresh Linux CI.

The issue was corrected in commit `a6b3e4c` without weakening the stale-contract
test. The corrected OpenAPI and TypeScript artifacts are deterministic, and the
subsequent five-check CI run passed.

The local Windows Playwright run did not complete. No local E2E success is
claimed. The explicit Linux `CI/E2E — Playwright` job passed and is the
authoritative browser gate for PR #21.

## Decision

As product owner and sole maintainer, I authorize PR #21 to be merged without an
independent reviewer because no eligible reviewer is currently available and
the documented compensating controls have passed.

This is a governance exception, not reviewer approval.

## Limitation

This exception applies only to PR #21. It does not permanently remove the
independent-review requirement. Future security-sensitive work should receive
independent review when an eligible reviewer becomes available.
