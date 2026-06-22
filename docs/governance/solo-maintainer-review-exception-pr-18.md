# Solo-Maintainer Security Review Exception — PR #18

**Date:** 2026-06-22
**Product:** Servana by Citrus
**Product owner and sole repository maintainer:** Project owner
**Pull request:** #18 — Phase R6: Enforce session authorization revocation
**Branch:** phase-r6-session-authorization-revocation

## Reason

The repository currently has one eligible maintainer. An independent second
reviewer is unavailable. No GitHub approval has been fabricated, and the pull
request review decision is expected to remain blank.

## Scope

This exception applies only to PR #18 and Phase R6.

Phase R6 introduces centralized credential revocation, active-principal
middleware, per-request membership, role, branch and permission freshness,
session and Sanctum-token revocation, Magic-Link and invitation invalidation,
lifecycle integrations, audit-safe evidence, frontend 401 handling, tests,
proof, remediation evidence and supporting documentation.

## Compensating controls

- Work was completed on a dedicated branch.
- The branch is based on merged PR #17.
- The pull request targets protected main.
- CI/Backend passed.
- CI/Frontend passed.
- CI/Security passed.
- CI/Docker passed.
- Database-session revocation was tested.
- Multi-device revocation was tested.
- Sanctum-token revocation was tested where applicable.
- Magic-Link invalidation was tested.
- Invitation invalidation was tested.
- Revocation idempotency was tested.
- Active-principal middleware ordering was tested.
- Role, permission and branch-assignment freshness were tested.
- Cross-tenant 404 and same-tenant cross-branch 403 behavior were tested.
- Audit-chain verification passed.
- Logs and audit evidence were checked for secret identifiers.
- Full backend, frontend, security and Docker gates were executed.
- The change will be squash-merged through a traceable pull request.
- No independent approval is claimed.

## Decision

As product owner and sole maintainer, I authorize PR #18 to be merged without
an independent reviewer because no eligible reviewer is currently available
and the compensating controls have passed.

This is a governance exception, not reviewer approval.

## Limitation

This exception does not permanently remove the independent-review requirement.
Future security-sensitive work should receive independent review when an
eligible reviewer becomes available.
