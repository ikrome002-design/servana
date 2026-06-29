# Solo-Maintainer Review Exception - PR #25

**Date:** 2026-06-29
**Product:** Servana by Citrus
**Pull request:** #25 - Phase 15B: Implement personnel availability
**Branch:** phase-15b-personnel-availability
**Original implementation commit:** 93f2e728c2db6aa6e386ae1a0ebb1abd1cf68979
**Verified remediated PR head:** 4b75eb4de9d26d3ea21993da5f132c6695fc25e4
**Successful pre-governance CI run:** 28358888303
**Product owner and sole eligible repository maintainer:** Project owner

## Reason

The repository currently has one eligible maintainer. An independent reviewer
is unavailable. No GitHub approval has been fabricated, and reviewDecision
remains intentionally blank.

## Scope

This exception applies only to PR #25 and Phase 15B.

Phase 15B implements HR-controlled personnel availability, recurring and
date-specific schedules, split shifts, recurring breaks, ordinary days off,
emergency unavailability, Branch Manager read-only visibility, and the reusable
eligibility-and-availability validator required by Phase 16A.

The verified PR head also contains the CI remediation required after the first
PR run:

- Laravel Pint formatting corrections in the Phase 15B scheduling tests.
- Dark-mode contrast corrections in the HR personnel availability screen.
- No unrelated product capability was added.

## Compensating controls

- Work was completed on a dedicated Phase 15B branch.
- PR #25 targets main.
- Backend CI passed.
- Frontend CI passed.
- Docker CI passed.
- Security CI passed.
- Linux E2E - Playwright CI passed.
- PostgreSQL constraint and migration evidence is recorded.
- Tenant and branch isolation evidence is recorded.
- HR-only mutation authority is tested.
- Branch Manager mutation denial is tested.
- Cross-tenant 404 and cross-branch 403 behavior is tested.
- Availability resolution and scheduling validation are tested.
- Atomic replacement and rollback behavior are tested.
- Typed audit events and redaction are tested.
- Responsive, dark-mode, keyboard and accessibility behavior are covered.
- Complete evidence is recorded in docs/proof/phase-15b.md.
- No independent approval is claimed.

## Decision

As product owner and sole eligible maintainer, I authorize PR #25 to merge only
after all required checks pass on the governance-evidence commit.

This is a governance exception, not reviewer approval.

## Limitation

This exception applies only to PR #25. Future security-sensitive work should
receive independent review when an eligible reviewer becomes available.
