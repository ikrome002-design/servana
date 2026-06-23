# Solo-Maintainer Governance Exception — Pre-Feature Remediation Gate Closure (§5.4)

**Date:** 2026-06-23
**Product:** Servana by Citrus
**Operator:** Citrus Labs Limited
**Product owner and sole repository maintainer:** Project owner
**Scope of this exception:** the Plan §5.4 **pre-feature remediation
gate-closure decision** only (the documentation PR on branch
`docs/pre-feature-remediation-gate-closure`).

## Reason

The repository currently has **one eligible maintainer**. An independent second
reviewer is unavailable. **No independent approval is claimed**, and no GitHub
approval or reviewer identity has been fabricated; the pull request
`reviewDecision` is expected to remain **blank**.

## Scope and limits

- This exception applies **only** to the §5.4 gate-closure decision recorded in
  `docs/remediation/pre-feature-completion-report.md`. It does **not** approve any
  application code, and it does **not** substitute for independent review.
- **Feature delivery remains governed by each owning Section 80 phase.** This
  exception grants nothing beyond declaring the pre-feature gate closed once this
  documentation PR merges. Every feature phase (10, 10F, 11, 15A … 25) carries its
  own acceptance criteria, FEATURE_DELIVERY_OBLIGATION items, and review/governance
  requirements.

## What was reviewed

All Phase V and R1–R7 compensating controls and CI evidence were reviewed against
the §5.4 criteria:

- **Merged PRs + green CI:** PR #12 (`c58b64a`, Phase V), #13 (`8fe575f`, R1),
  #14 (`1df759e`, R2), #15 (`c0402b2`, R3), #16 (`1288f48`, R4), #17 (`66aaead`,
  R5), #18 (`57ae8db`, R6), #19 (`4f0d4f3`, R7) — Backend/Frontend/Docker/Security
  conclusions SUCCESS (R5 Docker via a rerun past an external Buildx/Docker Hub
  timeout, with no product-code or Dockerfile change).
- **Proof artifacts:** `docs/proof/phase-v.md` and `phase-r1.md … phase-r7.md`.
- **Required ADRs:** ADR-001, ADR-002, ADR-003, ADR-008, ADR-009.
- **Migration evidence:** audit_logs (R2), mfa_* (R3), idempotency_keys (R4),
  +merchant_id/UNIQUE (R5).
- **Per-PR governance exceptions:**
  `docs/governance/solo-maintainer-review-exception-pr-13.md … -pr-19.md`.
- **Register:** all nine PRE_FEATURE_REMEDIATION items `verified_complete`.

## Decision

The §5.4 pre-feature remediation gate is declared **CLOSED**, effective when this
gate-closure documentation PR merges into `main`. The next eligible phase is
**Phase 10**, which is not started and must not begin before that merge.

## Compensating controls

- All work was completed on dedicated, reviewed branches targeting protected
  `main`; this closure PR likewise targets `main` via CI.
- The closure changes **only documentation/evidence files** — no product code,
  migrations, routes, configuration, tests, or frontend.
- The decision is auditable: every status maps to a merged commit, CI conclusion,
  proof, and (where applicable) ADR/migration evidence, recorded in the register
  and the completion report.
