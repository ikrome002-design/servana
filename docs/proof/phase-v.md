# Phase V — As-Built Verification (Proof)

**Objective (Plan §79 Phase V):** establish a trustworthy, evidence-based
baseline of what is *actually* implemented. No feature or remediation code.

**Branch:** `phase-v-as-built-verification` · **HEAD:** `e8681f6` (=`origin/main`,
0 ahead / 0 behind) · **Date:** 2026-06-21 · **DB env:** clean `migrate:fresh` on
disposable `servana_asbuilt` (PostgreSQL 16.14), Redis 7.4.9 — dev volume
untouched. Backend suites ran in `servana-app-1` (PHP 8.3.31 / Laravel 12.62.0).

## 1. Prove the problem
Plan §4 reports Phases 1–9 as claims, not verified facts; §79 requires Phase V
to regenerate §4 from repository evidence and block all later phases until done.
PROGRESS/CHANGELOG/CLAUDE.md still referenced the pre-correction §27 roadmap and
Laravel 11.

## 2. Evidence captured
| Artifact | Path |
|---|---|
| Versions (lock + running containers) | `docs/verification/evidence/versions.txt` |
| Migration status (26, clean) | `docs/verification/evidence/migrations.txt` |
| Full schema dump (clean DB) | `docs/verification/evidence/schema.sql` |
| Route inventory (38 routes) | `docs/verification/evidence/routes.json` |
| Full quality-suite results | `docs/verification/evidence/test-results.md` |
| Security/authorization verification | `docs/verification/evidence/security-results.md` |
| Claim-by-claim discrepancy register | `docs/verification/as-built-discrepancies.md` |
| Remediation register | `docs/remediation/register.yaml` |
| Traceability matrix (foundation rows) | `docs/traceability/servana-requirements.csv` |

## 3. Runtime & dependencies (verified, not copied)
Laravel **12.62.0**, PHP **8.3.31** (container), Sanctum **4.3.2** (stateful),
guzzle 7.12.1, psr7 2.12.1, sentry 4.26.0; PostgreSQL **16.14**, Redis **7.4.9**,
Meilisearch **1.10.3**. PHP 8.3 pinned (Dockerfile + CI + composer platform);
Node 20 (CI/nginx build). Advisory ignore removed; `composer audit` clean.

## 4. Database
- 26 migrations apply clean (`migrations.txt`).
- Constraints: 18 CHECK, 40 FK, 34 UNIQUE, 29 PK, **0 exclusion** (appointment
  exclusion belongs to Phase 16A — correctly absent).
- `audit_logs`: `previous_hash`/`hash char(64)`, severity CHECK; immutability
  trigger `audit_logs_block_mutation()` on BEFORE UPDATE+DELETE — **runtime
  proven** (UPDATE/DELETE both raise "append-only ... blocked"; row intact).
- Tenant-column coverage: tenant tables carry `merchant_id`/`ulid`; **5
  branch-owned tables lack `merchant_id`** → R5/REM-TEN-001.
- `idempotency_keys` and `mfa`/`totp` tables **absent** → R4/REM-IDEMP-001,
  R3/REM-MFA-001.

## 5. Routes & authorization
- 38 routes (31 under `api/v1`). **No Super-Admin/platform merchant-creation
  route** (only public `self-register`); **no personnel contact-export route**;
  no signed app route beyond framework default. Public flows each carry a
  dedicated rate limiter (enumeration posture). Authenticated routes carry
  `Authenticate:sanctum`+`EnforceIdleTimeout`+`ResolveTenantContext`+
  `EnsureMerchantActive`; branch routes add `EnsureBranchScope`; mutating branch
  routes add `EnsurePermission`. Details in `security-results.md`.

## 6. Source/security inspection
`withoutTenancy()`/`withoutGlobalScope()` only in the sanctioned trait + the two
PHPStan rule files; no raw-SQL concat; no `$guarded=[]`; no static
`::find()/::where()` in controllers. Frontend "token" matches are test mocks,
not secrets. Enforced by `TenancyStaticAnalysisTest` + Larastan rules.

## 7. Implemented security claims — verified by passing tests + code/runtime
Magic Link (SHA-256/15-min/atomic/no-enumeration), idle timeout, suspension
revocation of sessions/links/invitations, tenant 404 / branch 403 isolation,
deny-beats-grant + authority boundaries, audit-log DB immutability, invitation
hashing/atomic accept, log redaction. Mapping in `security-results.md`.

## 8. Full quality suite (clean containers)
Backend `php artisan test` serial **238 passed / 4 skipped (1067 assertions)**;
`--parallel` identical. Pint PASS (254); Larastan L8 no errors (182);
`composer validate --strict` valid; `composer audit` clean. Frontend: typecheck
0; lint 0 errors (28 pre-existing warnings); vitest **72**; build OK; e2e **27**
(axe AA). `npm audit` 0; `gitleaks` clean; both Docker images build. The 4
skipped tests are permanent owner-tagged placeholders (Phases 16/17/18/19). Full
table in `test-results.md`.

## 9. Demonstrate resolution (discrepancy outcomes)
- **confirmed:** claims 2, 4, 10, 11.
- **partially_confirmed:** 5, 6, 7, 8.
- **contradicted/superseded:** 1, 9, 12 (advisory sub-claim), 13.
- **REM-DEP-001** is **partially_complete** — L12 upgrade + advisory removal +
  CR/LF + signed-URL regression tests landed via PR #11, but ADR-001,
  `docs/proof/phase-r1.md`, and upgrade notes are **missing**, so **R1 remains
  required** (not auto-closed on PR #11).
- New items filed: **REM-DOC-001** (stale roadmap docs — closed by this phase).

## 10. Documentation corrections made in Phase V
- `docs/PROGRESS.md` regenerated onto the v3 roadmap (Phase V + R1–R7 + §80
  features; historical Phases 1–9 retained with PR/commit/proof/evidence-status).
- `docs/CHANGELOG.md` Phase V entry added.
- `CLAUDE.md` (in-repo) stale stack (Laravel 11→12.62) and roadmap (§27 →
  §§79–80) references corrected — minimum necessary, no behavior change.
- Plan **§4** statuses refreshed to evidence-based outcomes pointing here.
- `docs/traceability/servana-requirements.csv` seeded (Plan §85).

## 11. Residual risk / what Phase V did NOT do
- The pre-feature remediation gate (Plan §5.4) is **NOT closed**: open C0 items
  REM-DEP-001 (partial), REM-AUD-001, REM-MFA-001, REM-IDEMP-001, REM-TEN-001,
  REM-SESS-001 and C1 REM-OPS-001 remain. **No Section 80 feature phase may
  begin.**
- No remediation code was written (no MFA, idempotency, tenant-schema, or
  session-revocation changes); R1 is **not** started here. Phase V stops before R1.

## 12. Lifecycle status
`local_complete` — pending push, CI, and reviewer sign-off. Not `merged`/
`verified_complete`.
