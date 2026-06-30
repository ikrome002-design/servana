# Solo-Maintainer Review Exception — PR #28

**Date:** 2026-06-30
**Product:** Servana by Citrus
**Pull request:** #28 — Phase 16C: Implement service sessions
**Branch:** phase-16c-service-sessions
**Verified head:** ac5751aa7a643438118a23c2d5817a04eef9ad8a
**Product owner and sole eligible repository maintainer:** Project owner

## Reason

The repository currently has one eligible maintainer. An independent reviewer
is unavailable. No GitHub approval has been fabricated, and reviewDecision
remains intentionally blank.

## Scope

This exception applies only to PR #28 and Phase 16C.

Phase 16C introduces the Service Session domain, queue/session transactional
coupling, duplicate-active-session protection, eligibility and branch-assignment
validation, preferred-personnel execution validation, Personnel own-scope,
branch-close protection, derived busy state, safe session audit events, and an
explicitly non-payable commission preview.

## Compensating controls

- Work was completed on a dedicated Phase 16C branch.
- PR #28 targets main.
- Backend CI passed.
- Frontend CI passed.
- Docker CI passed.
- Security CI passed.
- Linux E2E — Playwright CI passed.
- PostgreSQL schema constraints were tested.
- The partial-unique duplicate-active-session constraint was tested.
- Tenant and branch isolation were tested.
- Personnel own-scope was tested.
- Front Office role ownership was tested.
- Non-authorized roles were denied.
- Queue and session state changes were tested transactionally.
- Failed operations were tested for rollback.
- Session start, completion and cancellation audit events were tested.
- OpenAPI and TypeScript contract parity passed.
- No invoice, commission ledger, payment or receipt subsystem was added.
- No independent approval is claimed.

## Product limitations preserved

The queue-linked in-progress abort workflow remains deferred because the Queue
Entry state machine does not define an authoritative in_service abort or
cancellation transition.

The completion preview is not earned and not payable. Where compensation
configuration does not exist, it is represented as not configured rather than
zero.

## Decision

As product owner and sole eligible maintainer, I authorize PR #28 to merge after
all required checks pass.

This is a governance exception, not reviewer approval.

## Limitation

This exception applies only to PR #28. Future security-sensitive work should
receive independent review when an eligible reviewer becomes available.
