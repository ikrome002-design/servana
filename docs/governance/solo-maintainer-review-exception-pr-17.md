# Solo-Maintainer Security Review Exception — PR #17

**Date:** 2026-06-22
**Product:** Servana by Citrus
**Product owner and sole repository maintainer:** Project owner
**Pull request:** #17 — Phase R5: Harden tenant and branch schema
**Branch:** phase-r5-tenant-branch-schema-hardening

## Reason

The repository currently has one eligible maintainer. An independent second
reviewer is unavailable. No GitHub approval has been fabricated, and the pull
request review decision is expected to remain blank.

## Scope

This exception applies only to PR #17 and Phase R5.

Phase R5 introduces tenant and branch ownership-column corrections, forward-only
backfills, PostgreSQL tenant/branch consistency constraints, model scoping,
tenant-safe route binding, automated schema and source coverage, isolation
tests, ADR-002, proof, remediation evidence, and supporting documentation.

## Compensating controls

- Work was completed on a dedicated branch.
- The pull request targets protected main.
- CI/Backend passed.
- CI/Frontend passed.
- CI/Security passed.
- CI/Docker passed.
- Legacy-row backfill behavior was tested using PostgreSQL.
- Null, orphaned, and mismatched ownership checks were performed.
- PostgreSQL merchant and branch consistency constraints were tested.
- Cross-tenant model isolation was tested.
- Cross-branch authorization behavior was tested.
- Tenant-safe route binding was tested.
- Tenant-column schema coverage was tested.
- Model tenancy-trait and static-analysis coverage was tested.
- Full backend, frontend, security, audit-chain, and Docker gates were executed.
- The change will be squash-merged through a traceable pull request.
- No independent approval is claimed.

## Decision

As product owner and sole maintainer, I authorize PR #17 to be merged without
an independent reviewer because no eligible reviewer is currently available
and the compensating controls have passed.

This is a governance exception, not reviewer approval.

## Limitation

This exception does not permanently remove the independent-review requirement.
Future security-sensitive work should receive independent review when an
eligible reviewer becomes available.
