# Solo-Maintainer Review Exception - PR #26

**Date:** 2026-06-29
**Product:** Servana by Citrus
**Pull request:** #26 - Phase 16A: Implement appointments
**Branch:** phase-16a-appointments
**Original implementation commit:** e62da205de0e452b82dcd91d21b6cf88ba60afdd
**CI remediation commit:** ce04c73445e61dd590e80e91771f0ddce9394335
**CI remediation commit message:** fix: resolve Phase 16A Playwright CI failure
**Failed initial CI run:** 28372954922
**Verified pre-governance head:** ce04c73445e61dd590e80e91771f0ddce9394335
**Successful replacement initial CI run:** 28374669729 28372954922
**Product owner and sole eligible repository maintainer:** Project owner

## Reason

The repository currently has one eligible maintainer. An independent reviewer
is unavailable. No GitHub approval has been fabricated, and reviewDecision
remains intentionally blank.

## Scope

This exception applies only to PR #26 and Phase 16A.

Phase 16A implements the appointment table, appointment state machine,
assignment, transfer, rescheduling, cancellation, check-in, no-show,
PostgreSQL double-booking prevention, branch-calendar validation, personnel
eligibility and availability validation, branch closure protection,
Front Office appointment operations, Branch Manager read-only visibility,
and Personnel own-scope visibility.

## Compensating controls

- Work was completed on a dedicated Phase 16A branch.
- PR #26 targets main.
- Backend CI passed.
- Frontend CI passed.
- Docker CI passed.
- Security CI passed.
- Linux E2E - Playwright CI passed.
- PostgreSQL appointment schema and exclusion-constraint evidence is recorded.
- Tenant and branch consistency is database-enforced.
- Appointment state transitions are directly tested.
- Invalid transitions are directly tested.
- Personnel scheduling validation is reused from Phase 15B.
- Appointment overlap and concurrency handling are tested.
- Front Office mutation authority is tested.
- Branch Manager mutation denial is tested.
- Personnel own-scope isolation is tested.
- Cross-tenant 404 and cross-branch 403 behavior is tested.
- Branch closure and archival blockers are tested.
- Typed audit events and redaction are tested.
- OpenAPI and generated TypeScript parity are tested.
- Responsive, dark-mode, keyboard and accessibility behavior are covered.
- Complete evidence is recorded in docs/proof/phase-16a.md.
- No independent approval is claimed.

## Decision

As product owner and sole eligible maintainer, I authorize PR #26 to merge only
after every required check passes on the governance-evidence commit.

This is a governance exception, not reviewer approval.

## Limitation

This exception applies only to PR #26. Future security-sensitive work should
receive independent review when an eligible reviewer becomes available.
