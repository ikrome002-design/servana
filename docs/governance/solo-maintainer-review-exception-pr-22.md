# Solo-Maintainer Review Exception — PR #22

**Date:** 2026-06-27
**Product:** Servana by Citrus
**Pull request:** #22 — Phase 10F: Establish secure file and media foundation
**Branch:** phase-10f-file-media-foundation
**Product owner and sole eligible repository maintainer:** Project owner

## Reason

The repository currently has one eligible maintainer. An independent reviewer
is unavailable. No GitHub approval has been fabricated, and reviewDecision
remains blank.

## Scope

This exception applies only to PR #22 and Phase 10F.

Phase 10F introduces the secure file and media foundation: private quarantine
and final storage, MIME and dangerous-file validation, ClamAV scanning, EICAR
proof, image sanitization, signed authorized downloads, tenant/branch/own-scope
enforcement, file jobs, scheduling, audit redaction, storage-boundary controls,
frontend upload states, API contracts, migrations and supporting evidence.

## Compensating controls

- Dedicated phase branch and pull request
- Backend CI passed
- Frontend CI passed
- Docker CI passed
- Security CI passed
- Linux E2E — Playwright CI passed
- Backend CI started a genuine ClamAV service
- ClamAvEicarIntegrationTest ran with --fail-on-skipped
- Serial and parallel PostgreSQL suites passed
- Tenant, branch and personnel-own-scope denials passed
- Signed-download expiry and authorization rechecks passed
- OpenAPI and TypeScript parity passed
- Dependency audits and gitleaks passed
- No independent approval is claimed

## Decision

As product owner and sole eligible maintainer, I authorize PR #22 to merge
after all required checks pass.

This is a governance exception, not reviewer approval.

## Limitation

This exception applies only to PR #22. Future security-sensitive work should
receive independent review when an eligible reviewer becomes available.
