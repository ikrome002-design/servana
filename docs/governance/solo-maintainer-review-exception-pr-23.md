# Solo-Maintainer Review Exception — PR #23

**Date:** 2026-06-28
**Product:** Servana by Citrus
**Pull request:** #23 — Phase 11: Finalize role layouts and navigation
**Branch:** phase-11-ui-layout-role-navigation
**Head commit:** bb04d87898e99b77b77cba1404339dbef6d2d8dc
**Successful CI run:** 28314016145
**Product owner and sole eligible repository maintainer:** Project owner

## Reason

The repository currently has one eligible maintainer. An independent reviewer
is unavailable. No GitHub approval has been fabricated, and reviewDecision
remains blank.

## Scope

This exception applies only to PR #23 and Phase 11.

Phase 11 finalizes the UI layout foundation and role navigation: the canonical
role mapping, the typed role-navigation registry with its snapshot-enforced
fixture, the eight role layouts (Super-Administrator header navigation exception
and merchant-role sidebar/rail + mobile drawer), eight live role landing pages,
eight guided get-started pages with versioned non-sensitive persistence, the
mandatory legal acknowledgement, rendered verbatim legal routes, state
boundaries, role-aware post-login routing, the screen inventory with its §27.1
specifications and coverage guard, and supporting tests and evidence
(REM-SCR-001 Phase 11 substrate).

The CI remediation in commit bb04d87 is in scope: removing the `docs` exclusion
from `.dockerignore` so the SPA build can resolve the `@docs` documentation
imports inside the Docker build context, and aligning the three pre-existing
end-to-end specs with the changed Phase 11 role-entry routes/selectors.

## Compensating controls

- Dedicated phase branch and pull request
- Backend CI passed (Pint, Larastan, Pest)
- Frontend CI passed (ESLint, vue-tsc, Vitest, build)
- Docker CI passed (build images)
- Security CI passed (gitleaks)
- Linux E2E — Playwright CI passed
- Vitest unit/component/store/route suites passed
- Role-navigation parity against the version-controlled fixture passed
- Prohibited-capability absence (Super-Admin merchant-create, Personnel
  contact-export, Audit mutation) proven
- Get-started persistence stores only non-sensitive identifiers/flags
- Responsive (360/768/1280) and axe (light + dark) per-feature gates passed
- Frontend authorization is UX only; the backend remains the security boundary
- No independent approval is claimed

## Decision

As product owner and sole eligible maintainer, I authorize PR #23 to merge
after all required checks pass.

This is a governance exception, not reviewer approval. It does not waive tests,
branch protection, CI, or evidence.

## Limitation

This exception applies only to PR #23 and is not reusable for any other pull
request. Future work should receive independent review when an eligible reviewer
becomes available.
