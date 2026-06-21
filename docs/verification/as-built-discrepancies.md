# Servana — As-Built Discrepancy Register (Phase V)

**Branch:** `phase-v-as-built-verification` · **HEAD:** `e8681f6` (= `origin/main`,
0/0) · **Captured:** 2026-06-21 · **Verifier:** senior engineer (QA/DevOps).

This regenerates Plan **§4** from repository evidence. It supersedes the
"Status before Phase V" column with evidence-based statuses. Sources used:
`composer.lock`/`package.json`, the running `servana-app-1` container (PHP 8.3.31
/ Laravel 12.62.0), a clean `migrate:fresh` on a disposable PostgreSQL 16.14 DB,
`route:list --json`, full test/scan suites, and direct DB queries. Detailed
evidence: `evidence/versions.txt`, `evidence/migrations.txt`, `evidence/schema.sql`,
`evidence/routes.json`, `evidence/test-results.md`, `evidence/security-results.md`.

Status legend: `confirmed` · `partially_confirmed` · `contradicted` ·
`not_verifiable`. Classification: C0 / C1 / C2 / N. Owning phase per Plan §§5.3,
79–80.

---

## Repository baseline
| Item | Evidence |
|---|---|
| Branch / sync | `phase-v-as-built-verification` @ `e8681f6`; `origin/main...HEAD` = `0 0`; clean tree |
| Base main | `e8681f6` (PR #10 v3 docs) ← `cbcf50c` (PR #11 L12 upgrade) ← `6ed26ec` (PR #9 phase-9) |
| Merged PRs | #1–#11 all present in `git log` with merge SHAs |
| Authoritative docs | v3 Plan active (§§79–80 supersede §27); Scope updated (PR #10) |

---

## Claim-by-claim (Plan §4 rows 1–13)

### Claim 1 — "Laravel 11.54 / PHP 8.3; advisory ignored since Phase 1"
- **Reported source:** PROGRESS/CHANGELOG (pre-PR#11).
- **Actual evidence:** Laravel **12.62.0** (container `php artisan --version` +
  `composer.lock`); PHP **8.3.31** (container); advisory ignore **removed** (no
  `audit` key in `composer.json`; no CVE-2026-48019/GHSA-5vg9 in source);
  `composer audit --locked` clean; CR/LF email + signed-URL regression tests
  added (PR #11). PHP 8.3 pinned in Dockerfile + CI.
- **Status:** `contradicted` (the L11/ignored claim is stale/superseded). The
  *remediation target* (L12.60+, advisory removed) is **substantially met** but
  **not formally closed** — see REM-DEP-001 below.
- **Impact:** none outstanding on the framework; the open item is R1 evidence
  completeness (no ADR-001, no R1 proof, no upgrade notes).
- **Classification / owning phase:** C0 → **R1** (REM-DEP-001, *partially_complete*).

### Claim 2 — "Magic Link: 64-byte, SHA-256, 15-min, atomic single-use; 7-check eligibility; 60-min idle timeout"
- **Actual evidence:** `MagicLinkTokenService` (SHA-256 `token_hash`, raw never
  stored; `EXPIRY_MINUTES=15`; atomic conditional UPDATE requiring affected==1;
  `invalidateUnconsumedForEmail`); `EnforceIdleTimeout` on all auth routes;
  eligibility + idle + token tests passing.
- **Status:** `confirmed`.
- **Classification / owning phase:** N (verified) — token hardening complete;
  any future change tracked under R6 revocation.

### Claim 3 — "Real MFA is a safe placeholder only"
- **Actual evidence:** no `mfa_credentials`/`totp` table in clean schema (grep=0);
  no MFA route in `route:list`; no MFA middleware on privileged routes.
- **Status:** `confirmed` (placeholder only; no privileged MFA enforcement).
- **Impact:** privileged actions (Super Admin/Merchant Admin/Finance) lack MFA +
  step-up. Security gap.
- **Classification / owning phase:** C0 → **R3** (REM-MFA-001, *open*).

### Claim 4 — "Tenant model + self-registration only; no Super-Admin/KYC route"
- **Actual evidence:** schema has `merchants`, `merchant_profiles`,
  `merchant_users`, `merchant_status_histories`, `merchant_branches`,
  `+is_platform_staff` on users; only `merchant-registration/self-register`
  route; `NoPlatformMerchantCreationTest` passing; pending_setup lifecycle via
  `EnsureFirstTimeSetupAccess`/`EnsureMerchantActive`.
- **Status:** `confirmed`.
- **Classification / owning phase:** N (verified). Re-asserted as a release gate
  at Phase 23.

### Claim 5 — "Branch/staff schema; hashed 72h invite; lifecycle revokes sessions/links"
- **Actual evidence:** all eight tables present; `staff_invitations` hashed token;
  `StaffLifecycleRevocationTest`/`StaffSuspensionTest`/`StaffInvitation*Test`
  passing. Branch-closure blockers for future operational/finance state are
  explicit named stubs (queue/session/invoice/payment/receipt/appointment/debt).
- **Status:** `partially_confirmed` (implemented work confirmed; closure stubs
  are deferred *feature* obligations, not defects).
- **Classification / owning phase:** N for built work; closure stubs flip on in
  Phases 16–18/20 (FEATURE_DELIVERY).

### Claim 6 — "Roles & permissions: 54 keys × 8 roles, 82 grants, deny-beats-grant"
- **Actual evidence:** registry resolver verified (`PermissionMatrixTest` DB==registry;
  deny-beats-grant; suspended→none; audit-read-only). Registry = baseline; Plan
  §19 canonical matrix is larger.
- **Status:** `partially_confirmed` (semantics correct; matrix incomplete vs §19).
- **Classification / owning phase:** C1 → **Phase 19** (REM-PERM-001, FEATURE_DELIVERY).

### Claim 7 — "audit_logs append-only, hash-chained; trigger blocks UPDATE/DELETE"
- **Actual evidence:** `previous_hash`/`hash char(64)` columns; trigger
  `audit_logs_block_mutation()` on BEFORE UPDATE+DELETE; **runtime-proven**
  (UPDATE/DELETE both raise "append-only ... blocked"). Event coverage + chain
  *verifier* + masked read incomplete.
- **Status:** `confirmed` for immutability/columns; `partially_confirmed` for the
  full audit subsystem.
- **Classification / owning phase:** C0 → **R2** (REM-AUD-001, *open*); full
  coverage Phase 19.

### Claim 8 — "Tenant isolation: global scopes, scoped binding, 404/403 posture (Phase 9)"
- **Actual evidence:** **Phase 9 IS merged** (PR #9 / `6ed26ec`) — the §4 note
  "Phase 9 not merged" is itself stale. Global scopes, scoped binding,
  `LogUnauthorizedAttempt`, `TenantAwareJob`, PHPStan rules all present and
  tested (`Isolation/*`, `TenancyStaticAnalysisTest` passing). **Gap:**
  branch-owned tables lack `merchant_id`.
- **Status:** `confirmed` for implemented isolation; `partially_confirmed`
  structurally (missing `merchant_id` on 5 branch-owned tables).
- **Classification / owning phase:** C0 → **R5** (REM-TEN-001, *open*).

### Claim 9 — "idempotency_keys defined without key_hash but unique on (merchant_id,key_hash)"
- **Actual evidence:** **no `idempotency_keys` table exists** in the clean schema
  (grep=0). The invalid prior definition is not present in the repo.
- **Status:** `contradicted` (the prior schema does not exist to be wrong; the
  corrected schema is not yet built).
- **Classification / owning phase:** C0 → **R4** (REM-IDEMP-001, *open*).

### Claim 10 — "Money VO; redaction; correlation IDs; rate limiters; /health + /health/deep"
- **Actual evidence:** `MoneyTest`, `LogRedactionTest`, `CorrelationIdTest`,
  `RateLimitersRegisteredTest`, `DeepHealthTest`, `ErrorEnvelopeTest`,
  `SentryConfigTest` passing. `/health/deep` currently treats Meilisearch + S3 as
  optional (documented).
- **Status:** `confirmed`. Liveness/readiness split + "prod deps required in
  readiness" owned by R7.
- **Classification / owning phase:** N for built work; C1 → **R7** (REM-OPS-001)
  for readiness hardening + env parity + ADR-009.

### Claim 11 — "Frontend foundation: 8 layouts, guards, stores, apiClient, useForm, 9 UI comps, breakpoints, dark tokens"
- **Actual evidence:** `npm run typecheck` 0 errors; `vitest` 72 passed; `e2e`
  27 passed (3 breakpoints, no horizontal scroll, theme toggle, axe AA); `build`
  succeeds.
- **Status:** `confirmed`. Whole-product responsive/dark/a11y audit is Phase 23.
- **Classification / owning phase:** N (verified).

### Claim 12 — "Reported test counts (~230 backend / 72 fe / 27 e2e); 1 ignored advisory"
- **Actual evidence (re-run):** backend **238 passed, 4 skipped** (serial &
  parallel identical); frontend **72 passed**; e2e **27 passed**; `composer audit`
  **0 advisories** (the previously-ignored advisory is gone post-PR#11).
- **Status:** `confirmed` (counts re-derived, not trusted; the "1 ignored
  advisory" sub-claim is now `contradicted` — zero advisories, none ignored).
- **Classification / owning phase:** N (verified).

### Claim 13 — "PROGRESS.md Phase 20 / §27 roadmap (pre-correction)"
- **Actual evidence:** `docs/PROGRESS.md` + `docs/CHANGELOG.md` track the old
  §27 "Phases 1–25" roadmap (e.g. "Phase 20 — Citrus Billing Engine"); v3 Plan
  uses §§79–80 (Phase V + R1–R7 + decomposed feature phases). `CLAUDE.md`
  in-repo still pins "Laravel 11" and "Plan §27 roadmap (Phases 1–25)".
- **Status:** `contradicted / superseded`.
- **Required correction (done in Phase V):** regenerate PROGRESS around v3
  roadmap; add Phase V CHANGELOG; minimal CLAUDE.md stack/roadmap correction;
  refresh Plan §4 statuses. Filed as **REM-DOC-001** (C1, PRE_FEATURE).

---

## New Phase V discoveries (beyond §4 rows)
| ID | Finding | Class | Owning phase |
|---|---|---|---|
| REM-DOC-001 | Stale §27 roadmap / Laravel-11 references in PROGRESS, CHANGELOG, CLAUDE.md; Plan §4 statuses pre-verification | C1 PRE_FEATURE | Phase V (this) |
| REM-DEP-001 (partial) | L12 upgrade landed via PR #11 **without** the formal R1 artifacts: no `docs/architecture/adr/0001-*` (ADR-001), no `docs/proof/phase-r1.md`, no upgrade notes | C0 PRE_FEATURE | R1 |

`branch_*` `merchant_id` absence (Claim 8) and absent `idempotency_keys`/`mfa`
tables (Claims 9, 3) are already captured by REM-TEN-001 / REM-IDEMP-001 /
REM-MFA-001 and need no new IDs.

## Summary
- **confirmed:** 2, 4, 10, 11 (+ token/immutability/isolation sub-claims).
- **partially_confirmed:** 5, 6, 7 (subsystem), 8 (structure).
- **contradicted / superseded:** 1, 9, 12 (advisory sub-claim), 13.
- **not_verifiable:** none.
- **Open C0 pre-feature:** REM-DEP-001 (partial), REM-AUD-001, REM-MFA-001,
  REM-IDEMP-001, REM-TEN-001, REM-SESS-001.
- **Open C1 pre-feature:** REM-OPS-001, REM-DOC-001 (closed by this phase).
- **Phase V verdict:** baseline is trustworthy; the pre-feature remediation gate
  (Plan §5.4) is **NOT** closed. No feature phase (Section 80) may begin.
