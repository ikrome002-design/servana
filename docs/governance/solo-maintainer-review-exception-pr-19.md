# Solo-Maintainer Security Review Exception — PR #19

**Date:** 2026-06-23
**Product:** Servana by Citrus
**Product owner and sole repository maintainer:** Project owner
**Pull request:** #19 — Phase R7: Harden probes CI isolation and parity
**Branch:** phase-r7-production-probes-ci-parity

## Reason

The repository currently has one eligible maintainer. An independent second
reviewer is unavailable. No GitHub approval has been fabricated, and the pull
request review decision is expected to remain blank.

## Scope

This exception applies only to PR #19 and Phase R7.

Phase R7 introduces dependency-free liveness, strict production readiness,
bounded dependency probes, safe 503 behavior, Redis/cache/rate-limit test
isolation, repeated CI stability verification, PHP/Node/Composer parity,
ADR-009 brand contrast evidence, proof, remediation evidence and supporting
documentation.

## Compensating controls

- Work was completed on a dedicated branch.
- The branch is based on merged PR #18.
- The pull request targets protected main.
- CI/Backend passed.
- CI/Frontend passed.
- CI/Security passed.
- CI/Docker passed.
- Required readiness dependency failures were tested.
- Readiness response redaction was tested.
- Probe timeout configuration was tested.
- Redis namespace isolation was tested.
- Cache isolation was tested.
- Rate-limit isolation was tested.
- Three consecutive parallel backend runs passed locally.
- The browser suite passed in an isolated local run.
- PHP, Node and Composer parity were machine-checked.
- WCAG CTA contrast was measured and tested.
- R6 authorization and revocation regression tests passed.
- Audit-chain verification passed.
- Full backend, frontend, security and Docker gates were executed.
- The change will be squash-merged through a traceable pull request.
- No independent approval is claimed.

## Decision

As product owner and sole maintainer, I authorize PR #19 to be merged without
an independent reviewer because no eligible reviewer is currently available
and the compensating controls have passed.

This is a governance exception, not reviewer approval.

## Limitation

This exception does not permanently remove the independent-review requirement.
Future security-sensitive work should receive independent review when an
eligible reviewer becomes available.
