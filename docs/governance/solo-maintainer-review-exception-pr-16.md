# Solo-Maintainer Security Review Exception — PR #16

**Date:** 2026-06-22
**Product:** Servana by Citrus
**Product owner and sole repository maintainer:** Project owner
**Pull request:** #16 — Phase R4: Add idempotency replay protection
**Branch:** phase-r4-idempotency-replay-protection

## Reason

The repository currently has one eligible maintainer. An independent second
reviewer is unavailable. No GitHub approval has been fabricated, and the pull
request review decision is expected to remain blank.

## Scope

This exception applies only to PR #16 and Phase R4.

Phase R4 introduces the PostgreSQL-backed idempotency store, deterministic
request and scope hashing, encrypted response replay, concurrency locking,
expired-lock recovery, retention and pruning, financial-route coverage
enforcement, provider-callback deduplication infrastructure, tests, ADR-003,
proof, remediation evidence, and supporting documentation.

## Compensating controls

- Work was completed on a dedicated branch.
- The pull request targets protected main.
- CI/Backend passed.
- CI/Frontend passed.
- CI/Security passed.
- CI/Docker passed.
- Raw idempotency keys were verified not to be stored.
- Replay-response bodies were verified as encrypted at rest.
- Unsafe headers and secrets were verified not to be stored or replayed.
- Same-key concurrent submissions were tested against PostgreSQL.
- Active-lock and expired-lock behavior were tested.
- Same-key different-request conflicts were tested.
- Provider-callback deduplication scope was tested.
- Full backend, frontend, security, audit-chain, and Docker gates were executed.
- The change will be squash-merged through a traceable pull request.
- No independent approval is claimed.

## Decision

As product owner and sole maintainer, I authorize PR #16 to be merged without
an independent reviewer because no eligible reviewer is currently available
and the compensating controls have passed.

This is a governance exception, not reviewer approval.

## Limitation

This exception does not permanently remove the independent-review requirement.
Future security-sensitive work should receive independent review when an
eligible reviewer becomes available.
