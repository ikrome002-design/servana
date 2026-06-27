# Changelog

All notable changes to Servana by Citrus. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); phases now map to the active v3
roadmap (Plan §§79–80), which supersedes the old §27 roadmap.

## [Unreleased]

### Phase 10F — File & Media Foundation (`phase-10f-file-media-foundation`)

Establishes the secure, private, reusable file domain before any feature stores,
generates, exports or downloads business files (Plan §65, §73; REM-FILE-001). Built
on merged Phase 10 (`4f761ff`, PR #21). `local_complete` — pending PR/CI/merge;
REM-FILE-001 stays `local_complete` until its PR merges.

- **Schema:** `uploaded_files` + `file_scan_events` (exact §13.13 fields, 11-purpose
  CHECK, scan/lifecycle CHECKs, required indexes, `available⇒clean+final_path` CHECK).
  No `download_count`; no global SHA-256 uniqueness (avoids a cross-tenant existence
  side channel). Data dictionary authored before migrations; manifest + TenantOwnership
  updated.
- **Purpose policy:** `FilePurpose`/`FilePurposeRegistry`/`config/files.php` — only
  `merchant_logo` and `profile_photo` uploadable; every export/PDF/report/statement
  purpose generated-only. Existing permission keys only.
- **Pipeline:** authorize→reject dangerous/spoofed pre-storage→stream to private
  quarantine→streaming SHA-256→server magic-byte MIME→pending row→202.
- **ClamAV:** `FileScanner` contract + INSTREAM `ClamAvScanner` (bounded timeouts,
  safe result mapping, version capture). Real EICAR integration test (runtime payload,
  no malware file committed).
- **Finalization:** clean files re-encoded (GD, metadata stripped) and promoted to a
  private final prefix only after verified storage; quarantine deleted post-promotion;
  infected/failed/rejected never downloadable.
- **Downloads:** `POST /files/{id}/download-link` issues a short-lived signed link;
  `GET /files/{id}/download` requires auth + a valid signature, streams from private
  storage (`nosniff`, safe Content-Disposition, no public cache). Authorization
  (tenant/branch/own-scope/permission/available/clean) is re-checked at issuance AND
  download by `FileAccessService`.
- **Jobs/scheduling:** `ScanUploadedFile`, `FinalizeCleanFile`, `ExpireSignedExport`,
  `DeleteExpiredQuarantineFile`, `VerifyOrphanedFileRecords` (report-only) on a
  dedicated `file-scanning` queue + worker (dev + prod compose); hourly/daily schedules.
- **Audit/redaction/boundary:** file `AuditEvent` cases; `Redactor` extended for
  signatures/paths/hash/filename/scanner payloads; `FileStorageBoundaryTest`
  statically forbids private-file writes/signing outside the file domain.
- **Billing seam:** `FileGenerationPolicy` denies new billing-gated generation when
  billing is read-only while keeping existing authorized files downloadable (no
  billing/finance_exports tables created).
- **Frontend:** accessible `SvFileUpload.vue` (selecting/uploading/scanning/available/
  rejected/error states, aria-live, 44px, light/dark, typed transport, no localStorage)
  + `useFileDownload`.
- **Contracts:** OpenAPI + generated TypeScript regenerated deterministically (47
  production routes incl. 4 file routes; `api:contract:check` OK).
- **Tests:** 52 backend file tests + 3 real-ClamAV EICAR + 6 frontend.
- **Deferrals:** finance_exports/audit dashboard/billing/M-Pesa/reports/UI nav → owning
  phases (11, 15A/15B, 16A–C, 17–18, 18B/23, 19, 20A–H, 20D, 20H/21N, 25).

### Phase 10 — API Foundation (`phase-10-api-foundation`)

Establishes the secure, generated, test-enforced API contract substrate every later
feature phase inherits (Plan §23, §24, §80; REM-ROUTE-001, REM-MIG-001; ADR-004).
Built on the merged gate-closure commit `7ac20a5` (PR #20). **Merged as PR #21**
(merge commit `4f761ff`, 2026-06-24; CI Backend/Frontend/Docker/Security/E2E—
Playwright all SUCCESS; reviewDecision blank under the solo-maintainer governance
exception — not independent approval). `verified_complete`; REM-ROUTE-001 and
REM-MIG-001 are `verified_complete`. (Backend CI initially failed on
nondeterministic OpenAPI generation — dedoc/scramble introspecting an un-migrated
parallel-worker schema — fixed in `a6b3e4c` without weakening stale-contract
enforcement; the subsequent complete five-job run passed.)

- **Route classification:** extended the R4 `RouteClass`/`RouteClassification` seam
  (not replaced) with the 8th class `liveness_readiness`, per-class required/forbidden
  middleware, and the `VALIDATION_EXEMPT` allowlist. Every production non-GET route
  declares exactly one class; health probes are `liveness_readiness`.
- **Security contract:** `RouteSecurityContractTest` + `ForbiddenRouteAbsenceTest`
  (SA merchant-creation and personnel contact-export proven absent);
  `FinancialRouteIdempotencyCoverageTest` preserved — financial routes cannot exist
  without idempotency.
- **Pagination/filter/sort:** reusable `App\Http\Api\ApiPagination` (default 25, max
  100, over-limit 422, allowlisted sorts + stable tiebreaker, validated filters);
  retrofitted the `branches`, `staff` and `staff-invitations` listings.
- **Resource `can` maps:** `HasCapabilities` concern (policy-derived, booleans,
  ULID ids only) on the branch/staff/invitation/audit resources.
- **OpenAPI generation:** the maintained **dedoc/scramble** generator (v0.13.28,
  declared in `composer.json` `require`) is authoritative for schema generation; a
  thin `App\Support\OpenApi\OpenApiGenerator` wrapper invokes it and applies
  determinism, full `/api/v1` paths, testing exclusion, operationId=route name,
  health probes, the §11.5 error envelope, the session security scheme and the
  financial `Idempotency-Key` header (`composer api:openapi` →
  `docs/api/openapi.json`, 43 operations, no test/future operations).
  `Scramble::ignoreDefaultRoutes()` keeps the docs UI out of the app.
- **TypeScript contract:** `npm run api:types` →
  `resources/spa/src/types/generated/api.ts` (openapi-typescript@7.4.4);
  `npm run api:contract:check` fails on stale/leaked/duplicate/missing contract and
  runs in CI (frontend job).
- **Migration governance:** ADR-004 (expand-and-contract, forward-repair, PITR) +
  `docs/architecture/migrations/{README.md,manifest.yaml}` (all 33 migrations) +
  `MigrationManifestTest` lint. No shipped migration edited.
- **Tooling deps:** `dedoc/scramble` (require, authoritative OpenAPI generator) +
  `spatie/laravel-package-tools`; `symfony/yaml` (dev, manifest lint);
  `openapi-typescript` (dev, pinned 7.4.4).
- **Parallel-suite fix (`1d25224`):** moved `committedSpec()`/`specOperationIds()`
  into `tests/Pest.php` so the OpenAPI tests are parallel-safe (an undefined-function
  fatal occurred only under `--parallel`). Full parallel suite: 485 passed / 4
  skipped / 2102 assertions / 4 processes.
- **Linux CI Playwright gate (`ci: enforce Phase 10 Playwright gate`):** added an
  explicit, separate `E2E — Playwright` job to `.github/workflows/ci.yml`
  (ubuntu-latest · Node 20 · `npm ci` · `npx playwright install --with-deps chromium`
  · `npm run build` · `npm run e2e` · `timeout-minutes: 20` so a stall fails the
  workflow · failure-only upload of `playwright-report/` + `test-results/`). The four
  existing jobs (Backend, Frontend, Docker, Security) are preserved unchanged; this is
  a fifth, independent job. The local **Windows** Playwright run **stalled without a
  completed run** — **no passing local E2E result is claimed**; the Linux
  `E2E — Playwright` job is the **authoritative Phase 10 browser gate**, and **PR #21
  must not merge unless it passes**. Existing Playwright tests are run unmodified — no
  skip, no extra retry, no suppression; `playwright.config.ts` is untouched.
- **OpenAPI contract determinism fix (`fix: make OpenAPI contract deterministic in CI`):**
  PR #21's first CI run (GitHub Actions run `28093861353`) failed only in
  `Backend — Pint, Larastan, Pest` → `Tests — Pest on PostgreSQL (parallel)` at
  `OpenApiContractTest:26` ("docs/api/openapi.json is stale") — 1 failed / 488 passed / 4
  skipped. The other four jobs (`E2E — Playwright`, Frontend, Docker, Security) had already
  passed on that run. **Root cause:** dedoc/scramble infers types by introspecting the live
  PostgreSQL schema, and `OpenApiContractTest` regenerated the document without
  `RefreshDatabase`, so a parallel worker whose database was not yet migrated read an empty
  schema and emitted fallback types (ULID ids → integer, booleans/counters → string,
  nullability lost) that diverged from the correctly-typed committed artifact — a real
  contract-determinism defect, not an external CI flake. **Fix:** `OpenApiContractTest` now
  `uses(RefreshDatabase::class)` (guaranteed migrated schema in serial Pest, parallel Pest
  and fresh CI), and `GenerateOpenApiCommand` (`composer api:openapi`) fails fast with a
  non-zero exit and no write if any core type-driving table (`merchant_branches`,
  `staff_profiles`, `staff_invitations`, `branch_operating_hours`, `audit_logs`) is absent.
  dedoc/scramble remains authoritative; the stale-contract assertion is not removed,
  skipped, weakened, mocked or bypassed; semantically-correct types are preserved (ULID/
  permission keys → string, weekday → integer, booleans → boolean, counters → integer,
  nullable → `string|null`). Regeneration is byte-deterministic (`composer api:openapi`
  twice → no diff); `docs/api/openapi.json` and `resources/spa/src/types/generated/api.ts`
  were already byte-current, so no artifact changed. Re-verified locally: full backend
  parallel suite 489 passed / 4 skipped / 2110 assertions; Pint, Larastan L8, composer
  validate, vue-tsc typecheck and `npm run api:contract:check` all clean.
- **Deferrals:** files/media → 10F; role nav → 11; business domains → owning §80
  phases; full per-table dict entries for audit/permissions/roles → 19.

### Pre-feature remediation gate closure (§5.4) (`docs/pre-feature-remediation-gate-closure`)

Documentation/evidence only — **no product code, migrations, routes, dependencies,
Dockerfiles, configuration, tests or frontend changed**.

- **Gate §5.4: CLOSED and effective** — the gate-closure PR #20 merged into
  `main` (merge commit `7ac20a5`, 2026-06-23; CI Backend/Frontend/Docker/Security
  all SUCCESS; reviewDecision blank under the solo-maintainer governance
  exception). All nine `PRE_FEATURE_REMEDIATION` items (Phase V + R1–R7) are
  `verified_complete`.
- **R7 finalized:** REM-OPS-001 → `verified_complete` — PR #19, merge commit
  `4f0d4f3`, CI Backend/Frontend/Docker/Security all SUCCESS, reviewDecision
  blank, governance exception `docs/governance/solo-maintainer-review-exception-pr-19.md`.
- **Register normalized:** REM-V-001 `merged` → `verified_complete`; register
  `meta.pre_feature_gate_closed: true`.
- **Completion report finalized** with the full §5.4 criteria matrix
  (`docs/remediation/pre-feature-completion-report.md`); gate-closure governance
  evidence recorded (`docs/governance/solo-maintainer-pre-feature-gate-closure-exception.md`,
  one eligible maintainer, no independent approval claimed).
- **Gate-closure proof:** `docs/proof/pre-feature-remediation-gate-closure.md`.
- **Phase 10 (API Foundation) has started** on branch `phase-10-api-foundation`
  now that the gate-closure PR #20 is merged. Stale gate-blocked and R7-pending
  status wording was replaced with the closed/effective state across PROGRESS.md,
  CHANGELOG.md, register.yaml and the completion report.

### Phase R7 — Production probes, CI isolation, environment parity (`phase-r7-production-probes-ci-parity`)

Closes REM-OPS-001 (`verified_complete`) — merged as PR #19 (squash `4f0d4f3`) —
makes production readiness truthful, isolates parallel/CI test infrastructure,
aligns runtime tooling, and records the accessible brand-token decision (Plan
§22, §24, §26, §76–77, §79 R7; ADR-009; Correction 7). Built on merged R6 `main`
(`57ae8db`, PR #18). CI Backend/Frontend/Docker/Security all SUCCESS;
solo-maintainer governance exception (reviewDecision intentionally blank — not
independent approval).

#### Probes
- **Liveness/readiness split.** `GET /health` is dependency-free liveness (200
  even when every dependency is down; no versions/hosts/secrets). `GET
  /health/deep` is strict readiness — **503** when any REQUIRED production
  dependency (`database`, `redis`, `cache`, `s3`) fails; optional dependencies
  (Meilisearch — Phase 22) only degrade (200). Mailpit is never a dependency.
- **Config-driven & redacted.** Required/optional sets and `require_configured`
  (production cannot silently treat a managed dependency as optional) live in
  `config/servana.php`. Probe bodies expose only safe names+statuses — no DSN,
  host, bucket, credential, SQL or exception detail.
- **Bounded timeouts.** `probe_timeout` (HTTP), Redis connection `timeout`, S3
  `http.connect_timeout`, and PG `PGCONNECT_TIMEOUT`. The prod **nginx**
  healthcheck now uses `/health/deep` (traffic eligibility); the app container
  keeps `php -v` liveness.

#### Test isolation & CI
- **Per-run + per-process namespace.** `tests/bootstrap.php` assigns a unique
  Redis + cache prefix (`servana_test_{runId}_{token}_`) from the CI run id and
  parallel-test token. Cache/session/queue already use array/sync (in-memory, per
  process — no shared store, **no FLUSHDB**); identical logical keys are isolated
  across namespaces. Proven by `RedisPrefixIsolation`/`Cache`/`RateLimit`/
  `ParallelTestIsolation` tests; three consecutive parallel backend runs are stable.

#### Runtime parity
- **PHP 8.3 / Node 20 / Composer 2** pinned across the app image, SPA/nginx build
  image, dev tooling, CI and machine-readable metadata (`package.json` `engines`
  + new `.nvmrc`). `RuntimeParityTest` fails on drift. Docker remains the
  canonical local runtime.

#### ADR-009
- Brand contrast decision recorded with **measured** ratios: dark Brand Deep on
  the Savannah-Orange CTA (≈ 4.92:1, AA) — white-on-orange (≈ 2.80:1) fails AA.
  `BrandContrastTokenTest` guards the committed tokens.

#### Tests & docs
- New: `tests/Feature/Api/{LivenessProbe,ReadinessProbe,ReadinessDependencyFailure,
  ProductionReadinessConfiguration}Test`, `tests/Feature/Security/HealthResponseRedactionTest`,
  `tests/Feature/Infrastructure/{RedisPrefixIsolation,CacheIsolation,RateLimitIsolation,
  ParallelTestIsolation,RuntimeParity}Test`, `tests/Unit/BrandContrastTokenTest`.
  Removed `tests/Feature/Api/DeepHealthTest` (migrated). ADR-009 + draft
  pre-feature completion report.
- **R6 corrected:** REM-SESS-001 = `verified_complete` (PR #18 merged, `57ae8db`,
  CI all SUCCESS, governance exception). REM-DOC-001 corrected to
  `verified_complete` (PR #12, `c58b64a`).

#### Known deferrals
- Full OpenAPI/route contract → Phase 10; file/media → Phase 10F; release-wide
  responsive/dark/a11y redesign + axe sweep → Phase 23; deployment/backups/
  alerting → Phase 25; Horizon/queue observability → Phase 21N/25. REM-OPS-001 =
  `verified_complete` (PR #19 merged, CI green).

### Phase R6 — Session & authorization revocation (`phase-r6-session-authorization-revocation`)

Closes REM-SESS-001 (`verified_complete`) — merged as PR #18 (squash
`57ae8db`) — completes credential revocation and per-request authorization
freshness so a suspension, deactivation or authority change takes effect no later
than the next authenticated request, with no stale session, token, membership,
role, branch or permission (Plan §79 R6; §9, §17–§19, §24; Correction 7). Built
on merged R5 `main` (`66aaead`, PR #17). CI Backend/Frontend/Docker/Security all
SUCCESS; solo-maintainer governance exception (reviewDecision intentionally
blank — not independent approval).

#### Added
- **Central revocation service** `app/Domain/Auth/Services/AccessRevocationService.php`
  — one idempotent, transactional domain service with `revokeForUser` /
  `revokeForMembership` / `revokeForMerchant`. Each revokes all database
  sessions, all Sanctum personal-access tokens (defence in depth — no token
  issuance surface exists; proven), all unconsumed Magic Links, and all
  applicable pending invitations. Returns a secret-free `RevocationSummary`
  (counts only) — `app/Domain/Auth/Support/RevocationSummary.php`.
- **Per-request active-principal gate** `app/Http/Middleware/EnsureActivePrincipal.php`
  — pinned after authentication and BEFORE `EnsurePrivilegedMfa` /
  `ResolveTenantContext` (bootstrap priority + route groups). A
  suspended/deactivated user (merchant or platform) is rejected 401 and its
  session torn down, even if a session survived revocation.
- **Tests** `tests/Feature/Auth/{SessionRevocation,AuthorizationFreshness,
  MagicLinkRevocation,SanctumTokenRevocation,InvitationRevocation,
  PermissionFreshness,BranchAssignmentFreshness,RevocationMiddlewareOrder}Test`,
  `tests/Feature/Security/MidSessionSuspensionTest` (real DB-backed session via
  the Magic Link login flow), `tests/Feature/Isolation/RevocationIsolationPostureTest`.

#### Changed
- **`StaffLifecycleService`** suspend/deactivate now delegate revocation to
  `AccessRevocationService` (adds Sanctum-token revocation) and attach the
  secret-free revocation counts to the existing membership lifecycle audit event
  (no new event, no duplication).
- **Logout** (`MagicLinkController`) invalidates the signed-in identity's
  unconsumed Magic Links; **Magic Link request** (`RequestMagicLink`) invalidates
  any previous unconsumed link so only the latest link is ever usable.
- **SPA** `apiClient` gains a loop-safe central 401 handler (registered in
  `main.ts`) that clears auth state and returns to login on a mid-session
  revocation — UX only; the backend remains the security boundary.

#### Security / freshness
- Membership, role, branch ids and the permission set are re-resolved from the
  database on every authenticated request (TenantContextResolver +
  PermissionResolver issue fresh queries; TenantContext caches per-request only).
  There is no cross-request authorization cache to invalidate; a second request
  re-queries authoritative state. Redis/cache/rate-limit prefix isolation is R7.
- The 404 cross-tenant / 403 same-tenant cross-branch contract is unchanged.
- No session id, token hash, Magic-Link value or invitation token is logged or
  audited; only aggregate counts. `audit:verify-chain` passes.

#### Known deferrals
- Redis/cache/rate-limit prefix isolation, liveness/readiness split, environment
  parity, ADR-009 → R7. Full route contract/OpenAPI → Phase 10. Future-domain
  revocation hooks → each owning feature phase. Release-wide browser/security
  hardening → Phase 23. REM-SESS-001 = `verified_complete` (PR #18 merged, CI
  green).

### Phase R5 — Tenant & branch schema hardening (`phase-r5-tenant-branch-schema-hardening`)

Closes REM-TEN-001 (`verified_complete`) — merged as PR #17 (squash
`66aaead`) — makes tenant/branch ownership structurally complete so every
existing branch-owned record is protected by `merchant_id` + `branch_id`, a
database consistency constraint, global scopes, scoped binding, and automated
coverage (Plan §2.1, §13.1, §79 R5; ADR-002). Built on merged R4 `main`
(`1288f48`, PR #16). CI Backend/Frontend/Security passed; the initial CI/Docker
run failed on an external Buildx/Docker Hub timeout and a rerun passed with no
product-code or Dockerfile change; solo-maintainer governance exception recorded
(`docs/governance/solo-maintainer-review-exception-pr-17.md`, reviewDecision
intentionally blank).

#### Added
- **Central registry** `app/Domain/Tenancy/TenantOwnership.php` — classifies every
  existing table (branch_owned / tenant_owned / exempt-with-reason); the single
  source of truth for the coverage tests.
- **Migrations (forward-only, expand→backfill→constrain):**
  `2026_06_23_000001` adds `UNIQUE (id, merchant_id)` to `merchant_branches`,
  `staff_profiles`, `merchant_users`; `…000002` adds `merchant_id` to the five
  branch-owned tables (`branch_user_assignments`, `branch_operating_hours`,
  `branch_calendar_exceptions`, `branch_day_records`, `branch_cash_ups`);
  `…000003` adds `merchant_id` to `staff_history` and
  `merchant_user_permission_overrides`. Each: backfill from the parent
  (parameterized, fail-safe), NOT NULL, index, `merchant_id → merchants` FK
  (RESTRICT), and a **composite consistency FK** `(fk, merchant_id) → parent(id,
  merchant_id)`.
- **Data dictionary** `docs/architecture/data-dictionary/branches-and-staff.md`
  (authored before the migrations, Plan §13.2).
- **ADR-002** (`docs/architecture/adr/0002-tenancy-enforcement-model.md`).
- **Tests** `tests/Feature/Tenancy/{TenantColumnCoverage,TenantBackfillMigration,
  TenantBranchConsistencyConstraint,ModelTenancyTraitCoverage}Test` +
  `tests/Feature/Isolation/{CrossTenantBranchOwnedModel,RouteBindingTenantSafety}Test`.

#### Changed
- **Models** gain `BelongsToMerchant` (+ `BelongsToBranch` on the four branch
  models): `BranchOperatingHour`, `BranchCalendarException`, `BranchDayRecord`,
  `BranchCashUp`, `BranchUserAssignment` (BelongsToMerchant only — BranchScope
  would be circular for the branch-assignment authority), `StaffHistory`,
  `MerchantUserPermissionOverride`. Factories derive `merchant_id` from the branch.
- **Creation sites** set `merchant_id` from the branch/parent:
  `AcceptStaffInvitation` (no tenant context — explicit), `StaffLifecycleService`,
  `OpenBranchDay`, `CloseBranchDay`, `BranchOperatingHoursController`,
  `PermissionOverrideService`.

#### Security
- Every branch-owned record now carries `merchant_id` + `branch_id` with a DB
  composite FK that **rejects** a merchant/branch mismatch (proven by inserting
  via `DB::table`, bypassing the model). Route-bound owned models resolve only
  within merchant scope (foreign ULID → 404 + `unauthorized_access` audit;
  same-tenant out-of-branch → 403 `no_branch_scope`, unchanged).
  `idempotency_keys` and `audit_logs` remain cross-cutting (nullable merchant by
  design).

#### Tests / proof
- `pint` clean, `stan` L8 0 errors, `composer validate` valid, backend **370
  passed / 4 skipped** (serial + parallel), vitest **77**, e2e **30** (after one
  documented flake + one webServer-timeout rerun), `composer audit` / `npm audit`
  / `gitleaks` clean, both Docker images build. Proof: `docs/proof/phase-r5.md`.

#### Known deferrals
- Session/token/link/invitation/cache revocation + per-request membership/role
  freshness → R6; readiness/parity → R7; migration manifest + full route contract
  → Phase 10; future tenant/branch tables → each owning feature phase; invoice/
  payment/queue/personnel isolation rows → Phases 16–19. REM-TEN-001 =
  `verified_complete` (PR #17 merged).

### Phase R4 — Idempotency & replay protection (`phase-r4-idempotency-replay-protection`)
*(Merged via PR #16, commit `1288f48`; CI Backend/Frontend/Security/Docker passed;
REM-IDEMP-001 `verified_complete` under a documented solo-maintainer governance
exception — `reviewDecision` blank, not an independent approval.)*

Closes REM-IDEMP-001 — the corrected `idempotency_keys` store + a
reusable middleware so duplicate, concurrent and recoverable-retry requests
produce exactly one durable effect, and a completed request replays its stored
(encrypted, sanitised) response (Plan §13.5, §24.4, §79 R4; ADR-003). Built on
merged R3 `main` (`c0402b2`, PR #15).

#### Added
- **Schema (forward-only, §13.5 corrected):** migration
  `2026_06_22_000003_create_idempotency_keys_table` —
  `UNIQUE(idempotency_scope, key_hash)`, indexes `(state, lock_expires_at)` +
  `(expires_at)`, `state` CHECK; `key_hash` = SHA-256(raw key); encrypted
  `response_body_encrypted`; FKs actor `SET NULL`, merchant/branch `RESTRICT`.
  Data-dictionary entry authored first (Plan §13.2).
- **Domain** `app/Domain/Idempotency/*`: `IdempotencyKey` model + `IdempotencyState`
  enum; `IdempotencyStore` (claim/complete/fail; `INSERT ON CONFLICT DO NOTHING`
  first-claim + `SELECT … FOR UPDATE` resolution); `CanonicalRequestHasher`;
  `IdempotencyScopeResolver`; `ReplayResponseSanitizer`; `ClaimResult`/`ClaimOutcome`;
  `ProviderReplayGuard` + `ProviderClaimResult`; idempotency exceptions.
- **Middleware** `EnsureIdempotentRequest` (§24.4 algorithm; key 16–255;
  replay / 409 conflict / 409 in-progress+Retry-After / reclaim; stable-4xx replay;
  redacted server failure) pinned last in `bootstrap/app.php` priority.
- **Classification seam** `RouteClass` + `RouteClassification` (`route_class`
  default) — extendable by Phase 10 without replacement.
- **Command** `idempotency:prune` (bounded; never deletes an active lock) scheduled
  daily; config in `config/servana.php` + `.env.example`.
- **ADR-003** (`docs/architecture/adr/0003-idempotency-and-replay-protection.md`).
- **Tests** `tests/Feature/Idempotency/*` (9 suites) +
  `tests/Feature/Security/FinancialRouteIdempotencyCoverageTest` (41 tests); a
  `testing`-only route harness (no production financial route ships).

#### Changed
- `bootstrap/app.php` — `EnsureIdempotentRequest` added to middleware priority
  (after authorization, immediately before the controller; §9.4 step 16).
- `routes/api.php` — `testing/idempotency/*` harness (financial-classified) +
  `RouteClassification` import. `routes/console.php` — prune schedule.

#### Security
- Only `SHA-256(raw key)` is stored; the response body is encrypted at rest; only a
  `content-type` header allowlist is persisted/replayed (never cookies, auth,
  XSRF, session, CSP, signed-URL, server, or debug headers); server failures store
  only a redacted code. PostgreSQL (unique constraint + row locks) is the
  concurrency boundary, not process memory.

#### Tests / proof
- `pint` clean, `stan` L8 0 errors, `composer validate` valid, backend **351
  passed / 4 skipped** (serial + parallel), vitest **77**, e2e **30** (after one
  documented `auth-magic-link` flake rerun), `composer audit` / `npm audit` /
  `gitleaks` clean, both Docker images build. Proof: `docs/proof/phase-r4.md`.

#### Known deferrals
- Full route-classification/OpenAPI contract → Phase 10; real invoice/payment/
  refund attachment → Phases 17–18; M-Pesa callback/inbox/receipt dedupe → Phase
  20D; billing/payout/compensation → owning Phase 20 subphases; tenant schema → R5;
  session revocation → R6; readiness → R7. REM-IDEMP-001 = `verified_complete`
  (PR #16, `1288f48`).

### Phase R3 — Privileged MFA & step-up (`phase-r3-privileged-mfa-step-up`)
*(Merged via PR #15, commit `c0402b2`; CI Backend/Frontend/Security/Docker passed;
REM-MFA-001 `verified_complete` under a documented solo-maintainer governance
exception — `reviewDecision` blank, not an independent approval.)*

Closes REM-MFA-001 — real privileged TOTP MFA, one-time recovery
codes, mandatory enforcement for Super Administrator / Merchant Administrator /
Finance, and a reusable fresh step-up control (Plan §17, §18, §79 R3). Built on
merged R2 `main` (`1df759e`, PR #14).

#### Added
- **Schema (forward-only):** `mfa_credentials` (encrypted TOTP secret;
  `UNIQUE(user_id,type)`; type `CHECK('totp')`; `last_used_timestep` replay
  guard; `user_id` FK RESTRICT) and `mfa_recovery_codes` (SHA-256 `code_hash`;
  single-use `used_at`; `UNIQUE(code_hash)`; index `(user_id, used_at)`).
  Migrations `2026_06_22_000001/000002`. Data-dictionary entry authored first
  (`docs/architecture/data-dictionary/core-identity-and-tenancy.md`, Plan §13.2).
- **TOTP** via `pragmarx/google2fa` v8.0.3 (RFC 6238, constant-time). Domain
  services `app/Domain/Auth/Mfa/*`: `TotpProvider`, `RecoveryCodeManager`,
  `MfaRequirementResolver`, `MfaSession`, `MfaManager`, `MfaStatus`,
  `MfaAuditLogger`, `StepUpAction`.
- **Models/factories** `MfaCredential`, `MfaRecoveryCode` (secret/hash hidden).
- **Middleware** `EnsurePrivilegedMfa` (mandatory-role gate, pinned after auth
  and before tenant context) and `RequireFreshMfa` (reusable step-up).
- **API** `GET /api/v1/auth/mfa` + `POST /auth/mfa/{enroll,confirm,challenge,
  recovery-challenge,recovery-codes}`; `MfaCodeRequest`; `mfa-confirm` /
  `mfa-challenge` rate limiters; MFA exceptions rendering the §11.5 envelope
  (`mfa_enrollment_required`, `mfa_challenge_required`, `mfa_invalid_code`,
  `step_up_required`, `mfa_invalid_state`).
- **8 MFA `AuditEvent` cases** (enrollment started/confirmed, challenge
  succeeded/failed, recovery code used, recovery codes regenerated, step-up
  succeeded/denied).
- **Frontend** `MfaSetup.vue`, `MfaChallenge.vue`, `authStore` MFA state +
  actions, a UX-only router guard, and the `mfa` block in the bootstrap payload.
- **Tests** 8 backend suites (`tests/Feature/Auth/Mfa*`, `tests/Feature/Security/
  MfaSecretRedactionTest`), authStore vitest cases, and `tests/e2e/mfa.spec.ts`.
  A test-only step-up harness exercises every `StepUpAction` (no fake business
  routes ship).

#### Changed
- `MfaController` — real flow replaces the `mfa_not_enabled` placeholder.
- `bootstrap/app.php` — `EnsurePrivilegedMfa` pinned between auth and
  `ResolveTenantContext` (Plan §9.4 step 2).
- `routes/api.php` — `auth/mfa` group, `EnsurePrivilegedMfa` on the authenticated
  group, and a `testing`-only step-up/privileged-probe harness.
- `AuthenticatedUserResource` — safe `mfa` state block.
- `AppServiceProvider` — MFA rate limiters. `config/servana.php` — `mfa` config
  (issuer, totp window, recovery-code count, step-up window).

#### Security
- TOTP secret encrypted at rest (`encrypted` cast); recovery codes stored only as
  SHA-256 hashes and shown once. Replay blocked via `last_used_timestep`. The MFA
  assertion lives only in the server session (`mfa_verified_at`), regenerated on
  challenge and cleared on logout — the Magic Link is never the MFA assertion. No
  secret / code / session id is logged or written to the audit trail;
  `audit:verify-chain` passes after MFA events.

#### Tests / proof
- `pint` clean, `stan` L8 0 errors, `composer validate` valid, backend **311
  passed / 4 skipped**, `audit:verify-chain` exit 0, vitest **77 passed**,
  `npm run build`, e2e **30 passed**, `composer audit` / `npm audit` / `gitleaks`
  clean, both Docker images build (dev + prod). Proof: `docs/proof/phase-r3.md`.

#### Known deferrals
- Step-up attachment to real business routes → owning phases (billing 20A; refund
  finalization & period reopen 18B; payout approval/mark-paid 20H; M-Pesa
  reconciliation 20D; backdated compensation 20F/20G). WebAuthn/passkeys &
  SMS/email OTP → later security enhancement. Administrator MFA reset/recovery →
  future account-recovery phase. Complete per-request revocation → R6.
  REM-MFA-001 = `verified_complete` (PR #15, `c0402b2`).

### Phase R2 — Core audit completeness (`phase-r2-core-audit-completeness`)
*(Merged via PR #14, commit `1df759e`; CI Backend/Frontend/Security/Docker passed;
REM-AUD-001 `verified_complete` under a documented solo-maintainer governance
exception — `reviewDecision` blank, not an independent approval.)*

Closes (locally) REM-AUD-001 — completes CORE audit-event coverage, hash-chain
verification, and secure masked audit reads for the already-implemented domains
(Plan §70, §79 R2; ADR-008). Financial/billing/M-Pesa/compensation/SMS/file/
export coverage and the flagged-event workflow remain Phase 19.

#### Added
- **Canonical typed event catalogue** `app/Domain/Audit/Enums/AuditEvent.php` —
  one snake_case action per transition with central `severity()`; existing Phase
  8/9 strings preserved. `AuditRecorder::record()` now takes an `AuditEvent`.
- **`AuditChainHasher`** — single canonical hash shared by recorder + verifier.
- **`AuditValueMasker`** — recursive server-side masking (email/phone/reference/
  token/secret/restricted) for `context`/`actor_label`.
- **`AuthAuditLogger`** — writes auth events to `audit_logs` (masked email, null
  actor; no token/session stored).
- **`audit:verify-chain`** Artisan command — verifies per-merchant + platform
  chains; detects altered/forged/reordered rows; no mutation; exit non-zero on
  failure; `--merchant`/`--platform` filters.
- **Masked read API** — `GET /api/v1/audit-logs(+/{auditLog})` (merchant;
  `audit.view_full`) and `GET /api/v1/platform/audit-logs(+/{auditLog})`
  (platform; `platform.audit.view`); paginated, allowlisted filters/sort;
  `AuditLogResource` (ULIDs only, masked). No write/delete routes.
- **`AuditLogPolicy`** — read-only; merchant + branch scope; platform separation;
  foreign-tenant 404. Registered in `AppServiceProvider`.
- **ADR-008** (`docs/architecture/adr/0008-audit-immutability-and-chain.md`).
- Migration `2026_06_21_000001_add_branch_id_to_audit_logs` (forward-only expand;
  nullable FK, indexed; part of the hash).
- 7 Audit feature tests (`tests/Feature/Audit/*`, 30 tests).

#### Changed
- `DatabaseAuditRecorder` — per-merchant + platform chains, advisory-lock
  serialization, `branch_id`, shared hasher.
- Core coverage wired into actions/services (auth, invitations, membership/staff
  lifecycle, branch lifecycle + day, branch assignment, permission overrides,
  unauthorized access) — in-transaction, with old/new values where sensitive.
- `AuditLog` model gains `branch_id` + `branch()` relation.
- Existing tests updated for the new recorder API and the audit-to-DB move (not
  weakened): `Auth/AuditReadOnlyTest`, `Security/MagicLinkTokenSecurityTest`.

#### Removed
- `AuthEventLogger` and `AuthEvent` (replaced by `AuditRecorder`/`AuditEvent`;
  no parallel audit system).

#### Security
- No raw Magic Link token, session id, full email (where masked), or request
  body is stored. `audit_logs` remains append-only (DB trigger; re-asserted).
  Chains are tamper-evident and independently verifiable per tenant.

#### Tests / proof
- `pint` (271), `stan` L8 (192, 0), backend **268 passed / 4 skipped**
  (serial + parallel), `audit:verify-chain` exit 0, vitest 72, build, `composer
  audit`/`npm audit`/`gitleaks` clean, both Docker images build. e2e: known
  `auth-magic-link` flake (26/1 then 27/0). Proof: `docs/proof/phase-r2.md`.

#### Known deferrals
- Full audit coverage + flagged-event workflow + exceptional unmasking → Phase 19;
  chain-failure alerting/scheduling → Phase 25; audit dashboard → Phase 11/19;
  audit export/signed delivery → Phase 19/23. REM-AUD-001 = `verified_complete`
  (PR #14, `1df759e`).

### Phase R1 — Dependency & runtime security (`phase-r1-dependency-runtime-security`)
*(Merged via PR #13, commit `8fe575f`; CI Backend/Frontend/Security/Docker passed;
REM-DEP-001 `verified_complete` under a documented solo-maintainer governance
exception — `reviewDecision` blank, not an independent approval.)*

Closes REM-DEP-001 — formalizes and re-verifies the Laravel 12 upgrade
delivered by PR #11 (`cbcf50c`). **No application/source/`composer.*`/Docker/CI
code changed in R1**; it adds the missing governance/evidence and re-runs the
gates.

#### Added
- `docs/architecture/adr/0001-framework-upgrade.md` (**ADR-001**): Laravel 12.60+
  on PHP 8.3 canonical (Docker), advisory-removal + dependency rationale,
  runtime parity, schema compatibility, rollout, rollback limitation,
  forward-repair, consequences. (Laravel 12 is **not** LTS.)
- `docs/operations/laravel-12-upgrade.md`: before/after versions, PR #11 + merge
  commit, packages changed, the `LogUnauthorizedAttempt` compat change, security
  tests, rebuild procedure incl. the **servana-vendor named-volume** warning,
  DB/cache results, deploy sequence, rollback, residual risks.
- `docs/proof/phase-r1.md`: full R1 verification evidence.

#### Verified (no code changes)
- Laravel **12.62.0** (≥12.60); PHP **8.3.31** across app/worker/scheduler
  (same image), CI, and prod compose; composer platform 8.3.31.
- `composer validate --strict` valid; `composer audit --locked` **0 advisories,
  0 suppressions**; guzzle 7.12.1 + psr7 2.12.1 retained.
- Security regressions: `EmailHeaderInjectionTest` (4) — embedded CR/LF rejected;
  `SignedUrlIntegrityTest` (4) — valid accepted, query-tamper/path-confusion/
  expiry rejected.
- DB/cache: clean disposable PG16 `migrate:fresh --seed` (26 + PermissionSeeder);
  Redis ping/round-trip; `cache:clear`; worker/scheduler boot on 8.3 image. No
  schema change from the upgrade.
- Full gates green: pint (254), Larastan L8 (0), backend **238 passed / 4
  skipped** (serial + parallel), vitest **72**, build, lint/typecheck (0),
  `npm audit` 0, gitleaks clean, both Docker images build.

#### Known deferrals
- e2e: first run 26/1 (intermittent `auth-magic-link` flake), reruns 27/0 —
  recorded, not erased; stabilization → Phase 23.
- REM-DEP-001 = `local_complete`; `verified_complete` only after PR merge, green
  CI, and the Plan-required **second reviewer**. Readiness/env-parity (R7), audit
  (R2), MFA (R3), idempotency (R4), tenant-schema (R5), revocation (R6) remain.

### Phase V — As-built verification (`phase-v-as-built-verification`)
*(Merged via PR #12, commit `c58b64a`; CI Backend/Frontend/Security/Docker passed.)*

#### Added
- Verification evidence (clean-environment, container-derived):
  `docs/verification/evidence/{versions.txt,migrations.txt,schema.sql,routes.json,test-results.md,security-results.md}`.
- `docs/verification/as-built-discrepancies.md` — evidence-based status for every
  Plan §4 claim (confirmed/partially_confirmed/contradicted/not_verifiable).
- `docs/remediation/register.yaml` — seeded all Plan §5.3 items + Phase V
  discoveries (REM-DOC-001) with evidence-based statuses and gating categories.
- `docs/traceability/servana-requirements.csv` — Plan §85 traceability matrix
  (foundation rows for implemented domains).
- `docs/proof/phase-v.md`.

#### Verified (no code changes)
- Runtime/deps from lock files **and running containers**: Laravel **12.62.0**,
  PHP **8.3.31**, Sanctum 4.3.2, PostgreSQL 16.14, Redis 7.4.9, Meilisearch
  1.10.3; PHP 8.3 pinned across Dockerfile/CI/composer; `composer audit` clean
  (advisory ignore removed).
- Clean `migrate:fresh` (26 migrations) on a disposable DB; audit_logs
  immutability trigger runtime-proven (UPDATE/DELETE blocked); 18 CHECK / 40 FK /
  34 UNIQUE / 0 exclusion constraints.
- Forbidden routes proven absent (Super-Admin merchant creation; personnel
  contact export). Full suite re-run: backend **238 passed / 4 skipped**,
  frontend **72**, e2e **27** (axe AA); Pint/Larastan/validate/audit; `npm audit`
  0; gitleaks clean; both Docker images build.

#### Findings / deferrals
- C0 pre-feature items open: REM-DEP-001 (**partial** — L12 upgrade landed via PR
  #11 but ADR-001/proof/notes missing, R1 still required), REM-AUD-001,
  REM-MFA-001, REM-IDEMP-001 (no idempotency_keys table), REM-TEN-001 (branch
  tables lack `merchant_id`), REM-SESS-001. C1: REM-OPS-001, REM-DOC-001 (closed).
- The pre-feature remediation gate (§5.4) is **not** closed; no Section 80
  feature phase may begin.

#### Documentation
- Plan §4 refreshed with §4.1 verified outcomes; `CLAUDE.md` stack (Laravel
  11→12.62) and roadmap (§27 → §§79–80) references corrected; `PROGRESS.md`
  regenerated onto the v3 roadmap (Phase 9 → merged #9; #11 L12 upgrade; #10 v3
  docs); this changelog.

### Phase 9 — Tenant-scoped data access hardening (`phase-9-tenant-scoped-data-access-hardening`)

#### Added
- Tenancy traits + global scopes (Plan §8.2): `BelongsToMerchant` (MerchantScope +
  `merchant_id` auto-fill on create, throwing `MissingTenantContext` when unscoped),
  `BelongsToBranch` (BranchScope; merchant-wide roles restricted to own-merchant
  branches via subquery). `withoutTenancy()` is the only sanctioned escape.
- Scoped route-model binding: `resolveRouteBinding()` resolves within merchant scope
  → foreign-tenant ULID 404s (no existence leak) and writes an `unauthorized_access`
  audit row. `bootstrap/app.php` pins `ResolveTenantContext` before
  `SubstituteBindings`; `ResolveTenantContext::terminate()` resets context per request.
- `LogUnauthorizedAttempt` (UnauthorizedAccessRecorder): unscoped existence check →
  high-severity `unauthorized_access` audit (actor, merchant, model, attempted ULID,
  route, correlation id); never leaks the foreign row or request body. `EnsureBranchScope`
  audits its foreign-branch 404 path too.
- `TenantAwareJob` + `MissingTenantContext` (Plan §8.3): captures merchant/branch ids,
  rehydrates + re-validates context in `handle()`, fails safely when absent or the
  merchant is not active. `TenantContext::bindForJob()`.
- PHPStan tenancy rules activated: `NoWithoutTenancyOutsidePlatformRule`,
  `NoRawSqlConcatRule` (no-ops since Phase 1). Plus `TenancyStaticAnalysisTest` source
  scan (escape hatches outside Tenancy/Platform; `::find()` in controllers; raw-SQL concat).
- Tests: `TenantAwareJobTest`, `Isolation/{RouteBinding,CrossTenantAccess,
  CrossBranchAccess,UnauthorizedAccessAudit,PermissionDeniedStillWorks,
  FutureResourceIsolation}Test`, `Security/{SuspendedMerchant,TenancyStaticAnalysis}Test`.
  Future-resource §8.4 rows (invoices/payments/exports/personnel) are permanent skipped
  tests naming Phases 16–19.

#### Changed
- Tenant-owned models (`MerchantProfile`, `MerchantUser`, `MerchantStatusHistory`,
  `MerchantBranch`, `StaffInvitation`, `StaffProfile`) and branch-owned models
  (`BranchOperatingHour`, `BranchCalendarException`, `BranchDayRecord`, `BranchCashUp`)
  now apply the tenancy traits. Excluded (documented in proof): `Merchant`,
  `BranchUserAssignment`, `StaffHistory`, `MagicLoginToken`, permission registry models,
  `MerchantUserPermissionOverride`, `AuditLog`, `User`.

### Phase 8 — Roles & permissions (`phase-8-roles-permissions`)

#### Added
- Permission schema (Plan §10.3): `permissions`, `roles`,
  `role_permission_assignments`, `merchant_user_permission_overrides`, and the
  real append-only, hash-chained `audit_logs` (DB trigger blocks UPDATE/DELETE).
  Forward-only; `merchant_users` untouched (role still lives there).
- `PermissionRegistry` — the canonical §10.3 matrix (54 keys × 8 roles);
  `PermissionSeeder` materialises it (82 default grants); `PermissionResolver`
  resolves role defaults ± per-user overrides (deny beats grant; suspended/
  deactivated → no permissions; read-only `audit` can never gain a mutating key).
- `EnsurePermission` middleware (missing key → 403 `permission_denied`) on the
  mutating Branch routes; policies for Merchant/MerchantBranch/MerchantUser/
  StaffInvitation/StaffProfile/BranchOperatingHour/BranchDayRecord (Plan §10.4).
- Audit foundation (Plan §22.2): `AuditRecorder` contract + `DatabaseAuditRecorder`
  (hash-chained). Permission override created/updated/revoked → high severity;
  denied self-escalation + denied audit/write attempts → warning.
- Per-membership overrides API (admin/HR, audited, anti-self-escalation):
  `POST`/`DELETE /api/v1/staff/{staff}/permissions`. HR permission preview:
  `GET /api/v1/hr/permission-preview` and `GET /api/v1/staff/{staff}/permissions`.
- `/api/v1/me` now returns the resolved `permissions[]` (request-cached in
  `TenantContext`).
- SPA: real `permissionStore` (sourced from `/me`), `useCan` composable,
  `PermissionGate` component, HR `PermissionPreview` page; the branch "Add branch"
  action is gated on `branches.create`.
- Tests: 8 backend Auth suites (PermissionMatrix [zero mismatches], Authority
  Boundaries, HrSelfEscalation, AuditReadOnly, PermissionOverrideAudit,
  PermissionMiddleware, PermissionPreview, MePermissionsBootstrap); Vitest +1
  (gate visibility). Matrix proof: `docs/proof/phase8-matrix.txt`.

#### Changed
- Branch/Staff controllers: coarse inline `assert*` role checks replaced by
  `EnsurePermission` (branch create/archive → `branches.create`; profile/hours →
  `branch.profile.manage`; day → `day.open_close`) and policies (staff lifecycle
  + invitations). Branch profile/hours/day editing is now a Branch Manager
  capability (matrix §10.3), not Merchant Admin — affected branch tests updated.

### Phase 7 — Branches, memberships, invitations (`phase-7-branches-memberships-invitations`)

#### Added
- Branch lifecycle (Scope §3.3): admin-only branch CRUD, weekly operating hours,
  day open/close records, and `BranchClosureGuard` enforcing the §3.3 blockers
  (unclosed day + cash-up discrepancy now; queue/session/invoice/payment/receipt/
  appointment as explicit named stubs for Phases 16–18) + `BranchDebtGate` stub.
- Branch scope (Plan §8.2): `branch_user_assignments` (partial-unique active per
  member+branch), `EnsureBranchScope` middleware (foreign ULID → 404, missing
  assignment → 403 `no_branch_scope`), `/api/v1/me` `branch_ids`.
- Staff invitations (Scope §3.4): `staff_invitations` (SHA-256-hashed 72h token),
  create/resend/revoke + atomic public accept (`AcceptStaffInvitation` →
  user + active membership + `staff_profiles` + active branch assignment +
  append-only `staff_history`). `StaffInvitationNotification` (raw token only in
  the emailed link). Duplicate-pending invite blocked; duplicate active staff
  phone/email blocked (partial unique index).
- `StaffLifecycleService`: transactional activate/suspend/deactivate/assignBranch/
  revoke; suspend/deactivate revoke DB sessions + unused Magic Links + pending
  invites; sole-active-admin orphan guard; branch-assignment-required-to-activate.
- Schema: `staff_profiles`, `staff_history`, `branch_operating_hours`,
  `branch_calendar_exceptions`, `branch_day_records`, `branch_cash_ups` (seam);
  `merchant_branches` expanded forward-only. Enum-backed statuses + DB CHECKs.
- HTTP: branch + staff-invitation + staff controllers, requests, resources;
  routes under EnsureMerchantActive (+ EnsureBranchScope on `{branch}` routes);
  public `POST /staff-invitations/accept` (`invitation-accept` limiter).
- SPA: branch list/create/detail/operating-hours, staff list (status badges)/
  invitations/public accept/profile; `branchStore` + `staffStore`; routes wired.
- Tests: 51 backend (branches/hr/isolation/security/auth check-6), Vitest +20
  (branch/staff stores + pages), Playwright +7 (`branches-staff-invitations`).

#### Changed
- `LoginEligibilityService` check 6 now enforced — a branch-scoped role requires
  an active branch assignment to receive a Magic Link (admin/platform exempt).
- `TenantContext` carries branch scope and `reset()`s on each resolution so a
  reused (scoped) instance never leaks a stale merchant.
- `MagicLinkTokenService::invalidateUnconsumedForEmail()` added for lifecycle
  revocation.

#### Fixed
- DB-default `status` columns are now mirrored via model `$attributes` so a
  freshly created branch/invitation has a status in memory before refresh.

### Phase 6 — Account & tenant model (`phase-6-account-tenant-model`)

#### Added
- Merchant Administrator self-registration (Scope §3.1/§3.2): public
  `POST /api/v1/merchant-registration/self-register` (`registration` limiter,
  uniform 202 — no enumeration) → `RegisterMerchant` action creates user +
  merchant (`pending_setup`) + shell profile + `merchant_admin`/`active`
  membership + status-history row in one transaction, then emails the owner a
  Magic Link. No Super Admin approval, no KYC — neither route nor UI exists.
- First-time setup (Scope §3.2 steps 1–7): `GET`/`POST`
  `/api/v1/merchant-registration/first-time-setup` (gated by
  `EnsureFirstTimeSetupAccess`: pending_setup + merchant_admin). Transactional
  `CompleteFirstTimeSetup` action — tier, profile, ≥1 branch, initial
  Branch+HR invited memberships (auto-selected to the single branch), welcome
  emails, then flips merchant → `active` + records status history.
- Tenant context (Plan §8.1): `TenantContext` (request-scoped),
  `TenantContextResolver`, `ResolveTenantContext` middleware, `EnsureMerchantActive`
  + `EnsureFirstTimeSetupAccess` gates, `TenantAccessException` (envelope codes
  `no_tenant_context` / `merchant_suspended` / `pending_setup_only` /
  `setup_already_completed`). Merchant dashboard shell `GET /api/v1/merchant/dashboard`.
- Schema (forward-only): `merchants`, `merchant_profiles`, `merchant_users`,
  `merchant_status_histories`, minimal `merchant_branches` (Phase 6 seam — full
  branch lifecycle is Phase 7); `is_platform_staff` added to `users`. Enum-backed
  statuses + DB CHECK constraints (MerchantStatus, MerchantUserStatus,
  MerchantUserRole, ServiceFeeTier, BranchStatus).
- `/api/v1/me` bootstrap now returns `{ user, merchant, membership, memberships,
  permissions, setup }` from the resolved tenant context (Plan §6.2).
- SPA: `RegisterMerchant.vue`, 4-step `FirstTimeSetup.vue` wizard, merchant
  `Dashboard.vue` shell; `onboardingStore`; `authStore`/`merchantStore` rewired
  to the new bootstrap; global `router.beforeEach` awaits session bootstrap
  before guards; `requiresPendingSetup` guard + pending→wizard routing.
- `StaffWelcomeNotification` (safe, tokenless welcome for invited Branch/HR;
  full invitation-accept flow is the Phase 7 seam).
- Tests: 40 backend onboarding/tenancy/security tests; Vitest +13 (RegisterMerchant,
  FirstTimeSetup, merchantStore, authStore tenant bootstrap); Playwright +4
  (`merchant-onboarding` incl. axe on registration + wizard).

#### Changed
- `LoginEligibilityService`: Scope §2.3 checks 2 & 4 now enforced
  (`User::hasTenantAccess` — active membership or platform staff);
  `AUTH_ENFORCE_TENANCY_ELIGIBILITY` now defaults `true`. Check 6 (branch
  assignment) stays deferred to Phase 7 (always passes for now).
- `AuthenticatedUserResource` reshaped to the tenant-aware bootstrap; Magic Link
  verify populates tenant context before responding.

### Phase 5 — Authentication (Magic Link + sessions) (`phase-5-authentication`)

#### Added
- Magic Link authentication (Plan §9.1): `POST /api/v1/auth/magic-link` (uniform
  202, no enumeration), `POST /api/v1/auth/magic-link/verify` (atomic single-use
  consume → Sanctum session login with session-id regeneration; uniform 422
  `invalid_or_expired_token`), `POST /api/v1/auth/logout` (204), `GET /api/v1/me`.
- `Domain/Auth/*`: `MagicLoginToken` model; `MagicLinkTokenService` (64 random
  bytes → base64url, SHA-256 at rest, 15-min expiry, atomic conditional-UPDATE
  single-use); `LoginEligibilityService` (seven-check contract, Scope §2.3);
  `RequestMagicLink`/`ConsumeMagicLink` actions; `MagicLoginLinkNotification`
  (branded, `mail` queue); `AuthEventLogger` (interim redacted audit until Phase 8);
  `InvalidMagicLinkException`; `AuthEvent` enum; `EligibilityResult` VO.
- `magic_login_tokens` table; auth-owned expand of `users` (`ulid`, `status`,
  `last_login_at`; `password` made nullable — Plan A3 "no password column").
- Laravel Sanctum `^4.3` installed; SPA stateful mode (`statefulApi()` + `sanctum`
  guard); `EnforceIdleTimeout` middleware (60-min sliding, Plan §9.2).
- SPA auth pages `Login.vue`/`CheckEmail.vue`/`Verify.vue` (replace Phase 4 stubs);
  `authStore` real `bootstrap/requestMagicLink/verifyMagicLink/logout`; bootstrap
  on app mount; typed `apiError` on `AxiosError`.
- `config/servana.php` (frontend URL, idle timeout, tenancy-eligibility flag);
  `sanctum` guard in `config/auth.php`.
- Tests: 8 backend auth/security suites (28 tests), Vitest authStore/Login/Verify
  (11 tests), Playwright `auth-magic-link` (5 tests incl. axe on all auth pages).

#### Fixed
- Test environment now overrides docker-injected `$_SERVER` vars via
  `tests/bootstrap.php` (previously the suite ran on the shared redis cache,
  causing rate-limiter bleed and non-deterministic sessions).
- Dev `worker` now consumes the `mail` queue (`queue:work --queue=mail,default`)
  so Magic Link emails are actually delivered.

#### Security
- Raw tokens never stored (only SHA-256), never logged (masked-email audit only),
  never returned in API responses. Single-use, 15-min expiry, uniform 202/422 to
  prevent account enumeration; named Magic Link rate limiters → structured 429.

#### Deferred
- Eligibility checks 2/4 (membership/role) → Phase 6; check 6 (branch) → Phase 7
  (seam methods + `AUTH_ENFORCE_TENANCY_ELIGIBILITY` flag in place). Instant
  suspension revocation of sessions → Phase 7. Real MFA/TOTP → later phase
  (safe `MfaController` placeholder only). No Phase 6 tenant schema created.

---

### Phase 4 — Frontend foundation (`phase-4-frontend-foundation`)

#### Added
- 8 role-based layout shells: `AuthLayout`, `PlatformAdminLayout`, `MerchantLayout`,
  `BranchLayout`, `FrontOfficeLayout`, `PersonnelLayout`, `FinanceLayout`, `AuditLayout`.
  Each includes skip link, accessible landmarks (`header`, `nav`, `main`), `.dark`-compatible
  tokens (Plan §6.1, §15.9).
- Router foundation: `router/index.ts` integrating 9 route modules, `router/guards.ts`
  with UX-only stubs for `requiresAuth`, `requiresRole`, `requiresPermission`,
  `requiresActiveMerchant` (Plan §6.2).
- 6 typed Pinia stores: `authStore`, `merchantStore`, `branchStore`, `permissionStore`,
  `themeStore` (persists to `localStorage`), `notificationStore`.
- `services/apiClient.ts`: single axios instance (`baseURL=/api/v1`, `withCredentials`,
  CSRF priming helper), response interceptor mapping Phase 3 error envelope to typed
  `ApiError { code, message, fields, meta }` (Plan §6.3, §11.5).
- `composables/useForm<T>`: typed values, dirty, touched, errors, `submitting`,
  `reset()`, `setFieldError()`, `mergeServerErrors(ApiError)`, `handleSubmit()` with
  duplicate-submit prevention (Plan §16).
- Types: `types/api.ts` (`ApiError`, `Paginated<T>`), `types/models.ts`, `types/enums.ts`.
- Utils: `utils/money.ts` (minor-unit formatting, Africa/Nairobi), `utils/dates.ts`.
- 9 core UI components under `components/ui/` (all light+dark, all states, axe-verified):
  `SvButton` (4 variants, loading, disabled, 44px touch, `text-brand-deep` on orange for
  WCAG AA 4.78:1), `SvInput`, `SvSelect`, `SvTextarea` (labels, `aria-invalid`,
  `aria-describedby`, `aria-required`), `SvCard`, `SvModal` (focus trap, Esc, `aria-modal`),
  `SvToast` (`role="status"`, 5s auto-dismiss, pause on hover), `SvStateBoundary`
  (loading/empty/error/success), `SvEmptyState`.
- `pages/dev/DesignSystemDemo.vue` routed at `/dev/design-system` — renders all Phase 4
  components in both themes with all required states.
- `playwright.config.ts`; Playwright smoke suite (11 tests) covering 3 breakpoints,
  no horizontal scroll, component rendering, theme toggle, modal keyboard, axe WCAG AA scan.
- Vitest tests for `apiClient` error mapping (10), `useForm` (8), `SvStateBoundary` (8).
- `npm` packages added: `axios`, `@playwright/test`, `@axe-core/playwright`.

#### Fixed
- Primary button and CTA button contrast: `text-white` on `#f97316` (2.8:1) replaced with
  `text-brand-deep` (`#4A2208`) on `#f97316` (4.78:1) to meet WCAG AA (Plan §15.3).
- Loading skeleton: added `role="status"` to permit `aria-label` on the loading div
  (axe `aria-prohibited-attr` violation resolved).

---

### Phase 3 — Laravel backend foundation (`phase-3-laravel-backend-foundation`)

#### Added
- Domain-oriented skeleton: 20 `app/Domain/*` folders (Plan §5.1).
- `app/Support/Money.php` — immutable integer-minor-unit money value object with
  currency-checked arithmetic, comparisons and integer-only formatting;
  `CurrencyMismatchException`.
- Enums: `Currency` (KES + USD forward-compat), `Severity`, `ErrorCode`.
- Structured API error envelope (Plan §11.5) via `app/Exceptions/ApiErrorRenderer`
  wired in `bootstrap/app.php`; 5xx responses carry a generic message + correlation
  id only.
- `CorrelationIdMiddleware` (+ `App\Support\CorrelationId`) — safe/length-bounded
  inbound `X-Correlation-ID` or generated ULID, echoed on the response and 5xx meta.
- Structured logging: `Support\Redaction\Redactor` and Monolog
  `RedactionProcessor` / `CorrelationIdProcessor` / `StructuredLogTap`, tapped onto
  the `single` and `stderr` channels (JSON + redaction + correlation id).
- Seven named rate limiters (Plan §9.3) registered in `AppServiceProvider`.
- `HealthController` with `/health` (liveness) and `/health/deep` (readiness:
  db/redis/cache required, meilisearch/s3 optional).
- `sentry/sentry-laravel ^4.10` wired (`Integration::handles`); env placeholders only.
- `routes/api.php` registering the `/api/v1` group (no business routes yet).
- Tests: `Unit/MoneyTest`, `Feature/Api/{ErrorEnvelope,CorrelationId,DeepHealth}Test`,
  `Feature/Security/LogRedactionTest`, `Feature/RateLimitersRegisteredTest`,
  `Feature/SentryConfigTest`.

#### Changed
- `bootstrap/app.php` — api routing + `apiPrefix=api/v1`, correlation middleware,
  `/health` + `/health/deep`, exception→envelope renderer, Sentry integration.
- `config/logging.php` (structured tap), `config/services.php` (meilisearch host),
  `app/Providers/AppServiceProvider.php`, `.env.example` (Sentry PII flag).
- `composer.json`/lock — added `sentry/sentry-laravel`.

#### Notes
- Framework tables (sessions/cache/jobs/job_batches/failed_jobs) already exist in
  the default migrations — confirmed, no new migration added.

### Phase 2 — Docker & environment setup (`phase-2-docker-environment`)

#### Added
- `docker/php.Dockerfile` — PHP-FPM 8.3 (alpine), extensions `pdo_pgsql`,
  `redis`, `intl`, `gd`, `bcmath`, `pcntl`, `zip`, `opcache`; Composer;
  non-root `servana` user; `dev` and `prod` build stages (Plan §26.1).
- `docker/nginx.Dockerfile` — non-root (nginx-unprivileged) edge image with a
  Node 20 SPA-build stage; `docker/nginx/default.conf`.
- `docker/php/php.ini`, `docker/php/opcache.ini`, `docker/php/entrypoint.sh`.
- `docker-compose.yml` — dev stack: app, nginx, postgres:16, redis:7,
  meilisearch, minio (+ bucket init), mailpit, clamav (opt-in `clamav`
  profile), worker + scheduler placeholders, spa-builder (`tools` profile);
  healthchecks on app/nginx/postgres/redis/meilisearch/minio/clamav.
- `docker-compose.prod.yml` — app/nginx/worker/scheduler against managed
  PG/Redis/S3 (Phase 25 completes deployment).
- `.dockerignore`.
- CI `docker` job building the app + nginx images (no push/deploy).
- `docs/proof/phase-2.md`.

#### Changed
- `.env.example` — full documented variable set with Docker service hostnames
  (postgres/redis/mailpit/minio/meilisearch); placeholders only.
- `Makefile` — real targets: `env up down restart logs ps shell composer npm
  fresh test lint stan build clamav-up`, run against the containers.
- `composer.json` — added `brianium/paratest` so `php artisan test --parallel`
  works; `pint` script made a passthrough (so `composer pint -- --test`).
- `.github/workflows/ci.yml` — `pcntl` extension, parallel tests, Pint check via
  `-- --test`.

#### Notes
- Horizon (Phase 21), ClamAV upload scanning (Phase 23), `/health/deep`
  (Phase 3), opcache preload + prod deploy (Phase 24/25) intentionally deferred
  — see `docs/PROGRESS.md`.

### Phase 1 — Project initialization (`phase-1-initialization`)

#### Added
- Laravel 11.54 application skeleton (PHP `^8.3`), domain-oriented per Plan §5.1
  (folders mature in later phases).
- Standalone Vue 3 + TypeScript + Vite 5 SPA under `resources/spa` (Pinia,
  Vue Router, self-hosted Inter/Manrope fonts), building to `public/spa`.
- Tailwind CSS configured with brand design tokens (Plan §12.1) and the exact
  responsive breakpoints `md: 768px`, `lg: 1025px` (Plan §13); dark-mode class
  strategy with pre-paint flash-prevention script (Plan §14).
- Quality tooling: Pest, Larastan level 8 with custom-rule placeholders
  (`NoWithoutTenancyOutsidePlatformRule`, `NoRawSqlConcatRule`), Pint, ESLint
  flat config, vue-tsc.
- Secret scanning: `.gitleaks.toml` + `.githooks/pre-commit` (activate with
  `git config core.hooksPath .githooks`).
- `.github/workflows/ci.yml`: PR-stage pipeline (Pint → Larastan → ESLint →
  vue-tsc → Pest on PostgreSQL 16 + Redis 7 → Vitest → build → dependency
  audits → gitleaks) per Plan §26.2.
- `GET /health` liveness endpoint and `tests/Feature/SmokeTest`.
- `.env.example` (Phase 1 minimal), `.editorconfig`, `pint.json`,
  `phpstan.neon`, `tsconfig.json`, `eslint.config.js`, `vite.config.ts`,
  `tailwind.config.ts`, `postcss.config.js`.
- Docs: `docs/PROGRESS.md`, `docs/proof/phase-1.md`, this changelog.

#### Changed
- `composer.json` rebranded to `citrus-labs/servana`; `audit.ignore` entry for
  CVE-2026-48019 (no Laravel 11 fix; documented, mitigated).
- `.gitignore` extended to ignore the `public/spa` build output.
- README "Local Development Setup" / "Common Commands" / "Repository Structure"
  updated to match the real scaffold.

#### Security
- Confirmed `.env` never entered git history; gitleaks gate clean on staged
  content. Pre-existing malformed local `.env` preserved as
  `.env.local-notes.bak` (gitignored).
