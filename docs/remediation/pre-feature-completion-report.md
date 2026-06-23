# Pre-Feature Remediation Completion Report

- **Plan refs:** §5.3 (remediation register), §5.4 (pre-feature gate), §79
  (Phase V + R1–R7), §80 (feature roadmap), §81–82 (execution/acceptance), §85
  (traceability).
- **Date:** 2026-06-23 (finalized on branch `docs/pre-feature-remediation-gate-closure`).

## Gate status

```
Gate status: CLOSED
Effective condition: this gate-closure PR is merged into main
R7 PR: #19
R7 merge commit: 4f0d4f3d497ff3bdb42e7d8a50a92949aebb25e2
R7 CI: Backend/Frontend/Docker/Security = SUCCESS
R7 reviewDecision: blank
R7 governance exception recorded: docs/governance/solo-maintainer-review-exception-pr-19.md
Next eligible phase after this PR merges: Phase 10
```

All nine `PRE_FEATURE_REMEDIATION` items (Phase V + R1–R7) are
`verified_complete`: each is merged to `main` with green CI, a proof artifact,
its required ADR/migration evidence where applicable, and a recorded
solo-maintainer governance exception. R7 (REM-OPS-001), the last open item, is now
merged as **PR #19** (`4f0d4f3`). The §5.4 gate is therefore **CLOSED**; the
closure becomes effective when *this* documentation gate-closure PR merges into
`main`. No Section 80 feature phase (Phase 10 onward) may begin before that.

> Review/approval note: every PR below has `reviewDecision` intentionally **blank**
> and a recorded **solo-maintainer governance exception** (the repository has one
> eligible maintainer). A governance exception is **not** an independent reviewer
> approval and is never represented as one. The gate-closure decision itself is
> covered by `docs/governance/solo-maintainer-pre-feature-gate-closure-exception.md`.

## Inventory of every PRE_FEATURE_REMEDIATION item

| Item | C | Owner | Status | PR | Merge commit | CI (Backend/Frontend/Docker/Security) | Proof / ADR | Migration |
|---|---|---|---|---|---|---|---|---|
| REM-V-001 — as-built verification baseline | C0 | Phase V | ✅ verified_complete | #12 | `c58b64a` | all SUCCESS | `docs/proof/phase-v.md` | none |
| REM-DOC-001 — docs onto v3 roadmap | C1 | Phase V | ✅ verified_complete | #12 | `c58b64a` | all SUCCESS | `docs/proof/phase-v.md` | none |
| REM-DEP-001 — L12.62/PHP 8.3, advisory, CR/LF | C0 | R1 | ✅ verified_complete | #13 | `8fe575f` | all SUCCESS | `docs/proof/phase-r1.md`; ADR-001 | none |
| REM-AUD-001 — core audit + chain verifier | C0 | R2 | ✅ verified_complete | #14 | `1df759e` | all SUCCESS | `docs/proof/phase-r2.md`; ADR-008 | audit_logs |
| REM-MFA-001 — privileged MFA + step-up | C0 | R3 | ✅ verified_complete | #15 | `c0402b2` | all SUCCESS | `docs/proof/phase-r3.md` | mfa_credentials, mfa_recovery_codes |
| REM-IDEMP-001 — idempotency + replay | C0 | R4 | ✅ verified_complete | #16 | `1288f48` | all SUCCESS | `docs/proof/phase-r4.md`; ADR-003 | idempotency_keys |
| REM-TEN-001 — tenant/branch schema | C0 | R5 | ✅ verified_complete | #17 | `66aaead` | Backend/Frontend/Security SUCCESS; Docker reran past an external Buildx/Docker Hub timeout (no code change) | `docs/proof/phase-r5.md`; ADR-002 | +merchant_id on 7 tables; +UNIQUE(id,merchant_id) on 3 parents |
| REM-SESS-001 — session/authz revocation | C0 | R6 | ✅ verified_complete | #18 | `57ae8db` | all SUCCESS | `docs/proof/phase-r6.md` | none |
| REM-OPS-001 — probes, CI isolation, parity, ADR-009 | C1 | R7 | ✅ verified_complete | #19 | `4f0d4f3` | all SUCCESS | `docs/proof/phase-r7.md`; ADR-009 | none |

**Remaining PRE_FEATURE blockers: none.** Every governance exception
(`docs/governance/solo-maintainer-review-exception-pr-13.md` … `-pr-19.md`) and
every proof (`docs/proof/phase-v.md`, `phase-r1.md` … `phase-r7.md`) is present.

## §5.4 closure criteria matrix

| # | §5.4 criterion | Result | Evidence |
|---|---|---|---|
| 1 | All PRE_FEATURE_REMEDIATION rows `verified_complete` | ✅ PASS | `docs/remediation/register.yaml` (9/9 verified_complete; FEATURE_DELIVERY_OBLIGATION rows remain open by design) |
| 2 | Required migrations applied and tested | ✅ PASS | audit_logs (R2), mfa_* (R3), idempotency_keys (R4), +merchant_id/UNIQUE (R5) — proofs `phase-r2…r5.md`; R6/R7 are no-migration |
| 3 | Backend/frontend/browser/isolation/security/dependency checks passed | ✅ PASS | R7 proof `phase-r7.md` §12 (serial 443/4-skip; 3× parallel stable; pint/stan/validate/audit/gitleaks; vitest 79; e2e 30; both Docker images) + PR #19 CI all SUCCESS |
| 4 | Required ADRs merged | ✅ PASS | ADR-001 (DEP), ADR-002 (TEN), ADR-003 (IDEMP), ADR-008 (AUD), ADR-009 (OPS) under `docs/architecture/adr/` |
| 5 | CI evidence attached | ✅ PASS | PR #13–#19 statusCheckRollup Backend/Frontend/Docker/Security = SUCCESS (per PR; R5 Docker via rerun, no code change) |
| 6 | Completion-report review or truthful governance evidence recorded | ✅ PASS | `docs/governance/solo-maintainer-pre-feature-gate-closure-exception.md` (no independent approval claimed) |
| 7 | PROGRESS.md and CHANGELOG.md regenerated with actual commits | ✅ PASS | `docs/PROGRESS.md` + `docs/CHANGELOG.md` updated to V+R1–R7 verified_complete with real commits/PRs |

## Required-ADR inventory

```
ADR-001 framework upgrade .............. REM-DEP-001 (R1)
ADR-002 tenancy enforcement model ...... REM-TEN-001 (R5)
ADR-003 idempotency & replay protection  REM-IDEMP-001 (R4)
ADR-008 audit immutability & chain ..... REM-AUD-001 (R2)
ADR-009 brand contrast tokens .......... REM-OPS-001 (R7)
(REM-MFA-001 and REM-SESS-001 required no new ADR.)
```

## Migration-proof references

```
audit_logs (append-only, hash-chained) ........ docs/proof/phase-r2.md
mfa_credentials / mfa_recovery_codes .......... docs/proof/phase-r3.md
idempotency_keys .............................. docs/proof/phase-r4.md
+merchant_id (7 tables) + UNIQUE(id,merchant_id) docs/proof/phase-r5.md
(R6, R7 introduced no schema change.)
```

## Governance evidence

- Per-PR exceptions: `docs/governance/solo-maintainer-review-exception-pr-13.md`
  through `-pr-19.md` (each: one eligible maintainer, no fabricated approval,
  reviewDecision blank, CI green, compensating controls listed).
- Gate-closure decision exception:
  `docs/governance/solo-maintainer-pre-feature-gate-closure-exception.md`.

## Stale-documentation corrections in this closure

- REM-OPS-001: `local_complete` → `verified_complete` (PR #19, `4f0d4f3`).
- REM-V-001: `merged` → `verified_complete`.
- Register `meta.pre_feature_gate_closed`: `false` → `true` (effective on merge).
- Replaced the prior gate-blocked-pending-merge marker and all R7 pending status
  wording with the closed state across PROGRESS.md, CHANGELOG.md, register.yaml
  and this report.

## Final gate decision

**CLOSED**, effective when this gate-closure PR merges into `main`. The next
eligible phase is **Phase 10 (API Foundation)**, which is **not started** and must
not begin until this PR is merged.
