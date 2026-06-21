# Servana — Build Progress

Tracks the **active v3 roadmap (Plan §§79–80)**: Phase V (as-built verification)
→ R1–R7 (pre-feature remediation) → feature phases (10…25). The old §27
"Phases 1–25" roadmap is superseded (see Plan §4 / `docs/verification/`). One
phase = one reviewed PR. A phase is not "Done" until its acceptance criteria are
demonstrably met and the owner approves. Lifecycle statuses: `local_complete` /
`ci_passed` / `merged` / `verified_complete` / `blocked`.

## Historical phases 1–9 (pre-v3 numbering; all merged into `main`)

These predate the v3 roadmap; they map onto the v3 phases noted. Evidence status
is the Phase V verification outcome (see `docs/verification/as-built-discrepancies.md`).

| Phase | Title | PR | Merge commit | Proof | Phase V evidence status |
|---|---|---|---|---|---|
| 1 | Project initialization | #1 | `4c2c49c` | [phase-1.md](proof/phase-1.md) | confirmed |
| 2 | Docker & environment setup | #2 | `bae929c` | [phase-2.md](proof/phase-2.md) | confirmed |
| 3 | Laravel backend foundation | #3 | `63176e4` | [phase-3.md](proof/phase-3.md) | confirmed |
| 4 | Frontend foundation | #4 | `89a8f7f` | [phase-4.md](proof/phase-4.md) | confirmed |
| 5 | Authentication (Magic Link + sessions) | #5 | `3d41af6` | [phase-5.md](proof/phase-5.md) | confirmed |
| 6 | Account & tenant model | #6 | `b1d21f4` | [phase-6.md](proof/phase-6.md) | confirmed |
| 7 | Branches, memberships, invitations | #7 | `ffed679` | [phase-7.md](proof/phase-7.md) | partially_confirmed (closure stubs deferred) |
| 8 | Roles & permissions | #8 | `1031a29` | [phase-8.md](proof/phase-8.md) | partially_confirmed (matrix < §19 → Ph19) |
| 9 | Tenant-scoped data access hardening | **#9 (merged)** | `6ed26ec` | [phase-9.md](proof/phase-9.md) | confirmed; structure partial (branch tables lack merchant_id → R5) |
| — | Laravel 11→12.62 security upgrade | **#11 (merged)** | `cbcf50c` | — | partial R1 (REM-DEP-001) — ADR/proof missing |
| — | v3 Plan/Scope documentation | **#10 (merged)** | `e8681f6` | — | confirmed |

## Active v3 roadmap

### Pre-feature remediation (Plan §79) — gate §5.4 must close before any feature phase
| Phase | Title | Status | Register item |
|---|---|---|---|
| V | As-built verification | ✅ `merged` — PR #12, commit `c58b64a` (CI Backend/Frontend/Security/Docker passed) | REM-V-001, REM-DOC-001 |
| R1 | Dependency & runtime security (Laravel 12.60+, PHP 8.3, advisory removal, CR/LF) | 🔄 `local_complete` (branch `phase-r1-dependency-runtime-security`); impl PR #11, R1 governance added; pending CI + 2nd review | REM-DEP-001 |
| R2 | Core audit completeness + chain verifier + masked read | ⬜ Not started | REM-AUD-001 |
| R3 | Privileged MFA + step-up | ⬜ Not started | REM-MFA-001 |
| R4 | Idempotency & replay protection | ⬜ Not started | REM-IDEMP-001 |
| R5 | Tenant/branch schema hardening (`merchant_id` on branch tables) | ⬜ Not started | REM-TEN-001 |
| R6 | Session & authorization revocation (per-request freshness) | ⬜ Not started | REM-SESS-001 |
| R7 | Production probes, CI isolation, env parity, ADR-009 | ⬜ Not started | REM-OPS-001 |

### Feature roadmap (Plan §80) — begins only after the §5.4 gate closes
| Phase | Title | Status |
|---|---|---|
| 10 | API foundation (Corrections 10–12) | ⬜ Not started |
| 10F | File & media foundation | ⬜ Not started |
| 11 | UI layout foundation & role navigation | ⬜ Not started |
| 15A / 15B | Services, catalogue, clients / personnel availability | ⬜ Not started |
| 16A / 16B / 16C | Appointments / walk-ins & queues / service sessions | ⬜ Not started |
| 17 | Invoicing | ⬜ Not started |
| 18A / 18B | Payment recording / validation, receipts, refunds, cash-up, period locks | ⬜ Not started |
| 19 | Audit logging completion & flagged events | ⬜ Not started |
| 20A–20H | Plans/prices, subscriptions, promotions, M-Pesa, %-fee engine, compensation, payouts | ⬜ Not started |
| 21N / 21S | Queues/notifications/reports / personnel bulk SMS | ⬜ Not started |
| 22 | Search | ⬜ Not started |
| 23 | Security hardening + responsive/dark/a11y release audit + threat-model | ⬜ Not started |
| 24 | Performance optimization | ⬜ Not started |
| 25 | Deployment pipeline & production readiness | ⬜ Not started |

## Phase R1 — Dependency & runtime security

- **Branch:** `phase-r1-dependency-runtime-security` (based on merged `main` @ `c58b64a`, PR #12 / Phase V).
- **Status:** 🔄 `local_complete` — pending push, CI, and **second-reviewer** sign-off (security-sensitive PR).
- **Proof:** [docs/proof/phase-r1.md](proof/phase-r1.md) · **ADR:** [ADR-001](architecture/adr/0001-framework-upgrade.md) · **Notes:** [laravel-12-upgrade.md](operations/laravel-12-upgrade.md).
- **Register:** REM-DEP-001.

### Work completed
- Re-verified PR #11's upgrade (no re-upgrade): Laravel **12.62.0** (≥12.60),
  PHP **8.3.31** across app+worker+scheduler (same `servana-app` image), CI
  `php-version '8.3'`, prod compose `target prod`, composer platform 8.3.31.
- Advisory state: `composer validate --strict` valid; `composer audit --locked`
  **0 advisories, 0 suppressions**; guzzle 7.12.1 + psr7 2.12.1 retained.
- Compatibility review: direct deps L12-compatible; only app change was PR #11's
  `LogUnauthorizedAttempt` `instanceof Route` removal (behavior unchanged); no
  schema change; `composer.json`/`composer.lock` unchanged in R1.
- Security regressions: `EmailHeaderInjectionTest` 4 pass; `SignedUrlIntegrityTest`
  4 pass (valid/query-tamper/path-confusion/expiry).
- DB/cache: clean disposable PG16 `migrate:fresh --seed` (26 + PermissionSeeder);
  Redis ping/round-trip OK; `cache:clear` OK; worker/scheduler boot on 8.3 image.
- Full gates: pint (254), stan L8 (0), BE test **238/4** (serial+parallel), FE
  typecheck 0/lint 0/vitest 72/build, e2e (see risks), npm audit 0, gitleaks
  clean, both Docker images build.
- Authored ADR-001, upgrade notes, R1 proof; updated register + traceability.

### Work skipped / deferred (with owning phase)
```
- Item: Readiness/liveness split, CI cache-prefix isolation, env parity, ADR-009.
  Reason: out of R1 scope. Owner: R7 (REM-OPS-001).
- Item: Audit completeness / MFA / idempotency / tenant-schema / session revocation.
  Owner: R2 / R3 / R4 / R5 / R6 respectively.
- Item: e2e flake stabilization. Owner: UI/e2e hardening (Phase 23).
- Item: composer.json/lock changes. Reason: no concrete R1 failure required one.
```

### Pending work
- Push branch; confirm CI green; obtain the Plan-required **second reviewer**
  (security-sensitive); merge; then flip REM-DEP-001 → `verified_complete`.

### Known risks
- Laravel 12 is not LTS — track point releases; re-run `composer audit`.
- Host vs container PHP divergence — always operate in the container.
- `servana-vendor` named volume hides `composer.lock` changes until in-container
  `composer install`.
- One intermittent e2e test: first R1 run 26/1, reruns 27/0 (retries=0 local,
  matches the known `auth-magic-link` check-email flake; not an R1 regression).

### Commands passed
- Container: `php -v` (8.3.31 app/worker/scheduler), `php artisan --version`
  (12.62.0), `composer validate --strict`, `composer audit --locked` (0),
  `migrate:fresh --seed` (disposable), `cache:clear`, `pint -- --test` (254),
  `stan` (L8 0), `php artisan test` 238/4 (serial+parallel), 2 security filters (4+4).
- Host: `redis-cli ping` (PONG), `npm run lint`/`typecheck`/`test` (72)/`build`,
  `npm audit` (0), `gitleaks` (clean), `docker build` php:dev + nginx:prod.

### Commands failed
- `npm run e2e` first run: 1 failed / 26 passed (flake); reruns 27/0. Recorded
  in proof §9; not erased by the passing rerun.

### Commands skipped
- `make up`/`make fresh`/`make test` — stack already healthy; underlying
  container commands run directly against a disposable DB to protect dev data.

### Context for R2 (Core audit completeness)
- Audit substrate exists and is verified: `audit_logs` hash columns +
  immutability trigger (Phase V runtime-proven). R2 replaces interim
  `AuthEventLogger` with full `AuditRecorder` coverage, adds the hash-chain
  verifier command and masked read API + branch/platform policies (REM-AUD-001).

## Phase V — As-built verification

- **Branch:** `phase-v-as-built-verification` → **PR #12 merged into `main`** (merge commit `c58b64a`).
- **Status:** ✅ `merged`. CI Backend/Frontend/Security/Docker passed.
- **Proof:** [docs/proof/phase-v.md](proof/phase-v.md).
- **Evidence:** `docs/verification/as-built-discrepancies.md`, `docs/verification/evidence/*`, `docs/remediation/register.yaml`, `docs/traceability/servana-requirements.csv`.

### Work completed
- Repository baseline confirmed (branch/SHA/sync, merged PRs #1–#11).
- Runtime/deps verified from lock files **and running containers**: Laravel
  12.62.0, PHP 8.3.31, Sanctum 4.3.2, PostgreSQL 16.14, Redis 7.4.9,
  Meilisearch 1.10.3. PHP 8.3 pinned across Dockerfile/CI/composer.
- Clean `migrate:fresh` (26 migrations) on a **disposable** `servana_asbuilt` DB
  (dev volume untouched); schema exported; constraints inventoried (18 CHECK, 40
  FK, 34 UNIQUE, 0 exclusion); audit_logs hash columns + immutability trigger
  **runtime-proven** (UPDATE/DELETE blocked).
- Route/authorization inventory (38 routes): forbidden Super-Admin
  merchant-creation route and personnel contact-export route **proven absent**;
  enumeration posture + middleware chain recorded.
- Source/security scan: no unsanctioned `withoutTenancy`/`withoutGlobalScope`,
  no raw-SQL concat, no `$guarded=[]`, no static `::find()` in controllers, no
  frontend secrets.
- Full quality suite re-run in clean containers (counts re-derived, not copied):
  backend **238 passed / 4 skipped** (serial & parallel); Pint, Larastan L8,
  `composer validate/audit`; frontend typecheck/lint, **vitest 72**, build,
  **e2e 27** (axe AA); `npm audit` 0; gitleaks clean; both Docker images build.
- Documentation regenerated (Plan §4 outcomes, CLAUDE.md stack/roadmap, this
  file, CHANGELOG, traceability CSV); remediation register seeded.

### Work skipped / deferred (with owning phase)
```
Skipped (correct for Phase V — verification only):
- Item: Any remediation code (MFA, idempotency, merchant_id backfill, per-request
  revocation, readiness split). Reason: Phase V is evidence-only; fixing here
  would violate scope. Owner: R1–R7 respectively.
- Item: ADR-001 + docs/proof/phase-r1.md + upgrade notes for the Laravel 12
  upgrade. Reason: belongs to the formal R1 phase; PR #11 did not produce them.
  Owner: R1 (REM-DEP-001 left partially_complete; R1 remains required).
- Item: 4 isolation tests (invoices/payments/exports/personnel-queue) remain
  permanently skipped placeholders. Owner: Phases 16/17/18/19 (feature).
- Item: Full §85 traceability CSV + CI enforcement. Reason: foundation rows
  seeded now; completeness + CI gate is Phase 23. Owner: continuous → Phase 23.
```

### Pending work
- None. PR #12 merged into `main` (`c58b64a`); CI passed. R1 now in progress.

### Known risks
- The pre-feature gate (§5.4) is **not** closed; six C0 + one C1 pre-feature
  items remain. No feature phase may start.
- REM-DEP-001 must **not** be auto-closed on PR #11 alone (missing ADR/proof).
- Branch-owned tables lack `merchant_id` (R5); no idempotency store (R4); no MFA (R3).

### Commands passed
- Container: `migrate:fresh` (26), `php artisan test` 238/4 (serial+parallel),
  `composer pint -- --test` (254), `composer stan` (L8, 0), `composer validate
  --strict`, `composer audit --locked` (clean).
- Host: `npm run typecheck` (0), `npm run lint` (0 err/28 warn), `npm run test`
  (72), `npm run build`, `npm run e2e` (27), `npm audit --audit-level=high` (0),
  `gitleaks detect --no-git --redact` (clean), `docker build` php:dev + nginx:prod.

### Commands failed
- None.

### Commands skipped
- `make up` (stack already healthy 14h — not re-run to avoid disrupting it);
  `make fresh`/`make test` substituted by their underlying container commands
  against the disposable DB to avoid wiping the dev volume.

### Context for R1 (Dependency & runtime security)
- The upgrade itself is done (12.62.0). R1's remaining work is **governance/
  evidence**: author `docs/architecture/adr/0001-framework-upgrade.md` (ADR-001),
  write `docs/proof/phase-r1.md` + upgrade notes, attach `composer audit`
  evidence, and confirm `EmailHeaderInjectionTest` + `SignedUrlIntegrityTest`
  in the R1 proof. Only then flip REM-DEP-001 to `verified_complete`.

## Phase 9 — Tenant-scoped data access hardening

- **Branch:** `phase-9-tenant-scoped-data-access-hardening` → **PR #9 merged into main** (merge commit `6ed26ec`).
- **Status:** ✅ `merged`. Phase V verification: `confirmed` for implemented isolation; structure partial — branch-owned tables lack `merchant_id` (→ R5 / REM-TEN-001).
- **Proof:** [docs/proof/phase-9.md](proof/phase-9.md).

### Completed
- Tenancy traits + global scopes (Plan §8.2): `BelongsToMerchant` (MerchantScope +
  `merchant_id` auto-fill on create, `MissingTenantContext` when unscoped, scoped
  `resolveRouteBinding`), `BelongsToBranch` (BranchScope; merchant-wide roles
  restricted to own-merchant branches via subquery; overridable `branchColumn()`).
  Applied to MerchantProfile/MerchantUser/MerchantStatusHistory/MerchantBranch and
  StaffInvitation/StaffProfile (+branch) and the four branch-owned models.
- Scoped route binding inside merchant scope; `ResolveTenantContext` pinned before
  `SubstituteBindings`; `terminate()` resets context per request.
- `LogUnauthorizedAttempt` writes a high-severity `unauthorized_access` audit row
  for a foreign-tenant ULID (no existence leak, no body/secret). `EnsureBranchScope`
  audits its foreign-branch 404 path.
- `TenantAwareJob` + `MissingTenantContext`; `TenantContext::bindForJob()`.
- PHPStan rules activated (`NoWithoutTenancyOutsidePlatformRule`, `NoRawSqlConcatRule`)
  + `TenancyStaticAnalysisTest` source scan. Deliberate violation shown failing then
  removed (proof §4) — not committed.

### Work skipped (deferred) — owning future phase
```
Skipped:
- Item: Invoice/payment/receipt/finance cross-tenant isolation rows (§8.4).
- Reason: those tables do not exist yet. Permanent skipped tests in
  Isolation/FutureResourceIsolationTest name the owner.
- Correct future phase: 17 (invoices) / 18 (payments, exports)

Skipped:
- Item: Queue/session/personnel own-scope isolation rows (§8.4 PersonnelOwnScope).
- Correct future phase: 16

Skipped:
- Item: Export-service scope assertion (ExportScopeTest).
- Correct future phase: 18/19/23

Skipped:
- Item: Full API conventions, pagination, OpenAPI → 10; role nav → 11;
  responsive/dark/a11y → 12–14; HR/catalogue/client workflows → 15; full audit
  event coverage + hash-chain verification → 19; billing/commissions → 20;
  Horizon/search/uploads/deploy → 21–25.
```

### Pending work
- None blocking. CI confirmation on push + owner approval to merge.

### Known risks
- Branch-owned models without `merchant_id` rely on the branch→merchant subquery for
  merchant isolation; a future directly-route-bound branch-owned table must add
  `BelongsToMerchant` (or a `merchant_id`) so its binding audits.
- Cross-branch staff/invitation access is a policy 403 (not 404) by design (proof §5).
- Only `unauthorized_access` is audited; full §5.18 coverage is Phase 19.

### Commands that passed
- `docker compose exec app php artisan migrate:fresh --seed` → 26 migrations OK (PostgreSQL 16).
- `php artisan test` → **230 passed, 4 skipped (1020 assertions)**; `--parallel` → 230 passed (4 procs).
- `composer pint --test` → PASS · `composer stan` → No errors (Larastan level 8).
- Deliberate stan violation → `servana.tenancy.withoutTenancy` error; reverted → No errors.
- `npm run typecheck` → 0 · `npm run test` → **72 passed** · `npm run build` → built · `npm run e2e` → **27 passed**.
- `gitleaks detect --no-git --redact` → no leaks · `npm audit --audit-level=high` → 0.
- `composer audit` → 1 documented-ignored advisory (GHSA-5vg9-5847-vvmq, since Phase 1).

### Commands that failed, if any
- None outstanding. During verification Docker Desktop had to be restarted (host
  daemon wedged) and PostgreSQL needed a few seconds to accept connections — no code
  change. No test regressions from the global scopes.

### Context for Phase 10 (API foundation)
- §11 conventions across the board: pagination/filter/sort traits, `Idempotency-Key`
  middleware, resources with `can` maps, `RouteCoverageTest`, OpenAPI generation.
- Tenant isolation is now structural (global scopes + scoped binding + audited
  foreign-ULID access), so Phase 10 resources inherit scoping automatically; new
  tenant models only need the `BelongsToMerchant`/`BelongsToBranch` traits.

## Phase 8 — Roles & permissions

- **Branch:** `phase-8-roles-permissions` → **PR #8 merged into main.**
- **Status:** ✅ Complete. **CI passed: Backend, Frontend, Security, Docker.**
  Docker build initially failed on the GitHub Actions cache export, then passed on
  rerun; no code change required.
- **Proof:** [docs/proof/phase-8.md](proof/phase-8.md) · matrix: [phase8-matrix.txt](proof/phase8-matrix.txt).

### Completed
- Permission schema (Plan §10.3, forward-only): `permissions`, `roles`,
  `role_permission_assignments`, `merchant_user_permission_overrides`, and the
  real `audit_logs` (append-only, hash-chained; DB trigger blocks UPDATE/DELETE).
  `merchant_users` untouched — role assignment still lives there.
- `PermissionRegistry` (canonical §10.3 matrix: 54 keys × 8 roles),
  `PermissionSeeder` (82 default grants), `PermissionResolver` (role defaults ±
  per-user overrides; deny beats grant; suspended/deactivated → none; read-only
  `audit` can never gain a mutating key). `TenantContext` caches the set per
  request; `/api/v1/me` returns `permissions[]`.
- `EnsurePermission` middleware (missing key → 403 `permission_denied`) on the
  mutating Branch routes; 7 policies (Plan §10.4). Branch/Staff controller
  `assert*` role checks replaced by middleware/policies.
- Audit foundation: `AuditRecorder` + table-backed `DatabaseAuditRecorder`.
  Override created/updated/revoked (high); denied self-escalation + denied
  audit/insufficient writes (warning).
- Per-membership override API + HR permission preview (admin/HR, audited,
  anti-self-escalation, branch- and merchant-scoped).
- SPA: real `permissionStore` (from `/me`), `useCan`, `PermissionGate`, HR
  `PermissionPreview` page; branch "Add branch" gated on `branches.create`.

### Work skipped (deferred) — owning future phase
```
Skipped:
- Item: BelongsToMerchant/BelongsToBranch traits across all models + PHPStan
  tenancy rule activation; LogUnauthorizedAttempt for all routes.
- Correct future phase: Phase 9
- Risk if forgotten: tenant scoping enforced per-controller, not globally; only
  override-endpoint denials are audited so far (general denial logging is §9).

Skipped:
- Item: Full /api/v1 conventions, pagination, filters, OpenAPI.
- Correct future phase: Phase 10
- Risk if forgotten: resource surface is still partial (Phase 7/8 endpoints only).

Skipped:
- Item: Final role navigation lists (verbatim Scope); responsive/dark/a11y sweeps.
- Correct future phase: Phase 11 / 12–14

Skipped:
- Item: Real HR/catalogue/client/service workflows.
- Correct future phase: Phase 15

Skipped:
- Item: Queue/session/appointment + invoice/payment/receipt operational blockers
  (the many permission keys seeded now — services.manage, payments.*, receipts.*,
  refunds.*, etc. — are not yet wired to routes; those routes arrive with their
  owning phases).
- Correct future phase: Phases 16–18

Skipped:
- Item: Full §5.18 audit event coverage + hash-chain verification/masking.
- Correct future phase: Phase 19
- Risk if forgotten: chain columns + immutability exist now; verifier is §19.

Skipped:
- Item: Billing/commission permission effects (branch-debt gate on delete, etc.).
- Correct future phase: Phase 20

Skipped:
- Item: Horizon / search / uploads / deployment.
- Correct future phase: Phases 21–25
```

### Pending work
- None. PR #8 merged into main; CI passed (Backend, Frontend, Security, Docker).

### Known risks
- Branch profile/hours/day editing moved from Merchant Admin (Phase 7 coarse
  check) to Branch Manager (`branch.profile.manage` / `day.open_close`) per the
  §10.3 matrix — affected Phase 7 branch tests were updated to act as a Branch
  Manager. Reviewers should confirm this matches the intended operating model.
- Most seeded permission keys are not yet attached to routes (their endpoints
  arrive in Phases 15–20); the registry/seed/resolver are complete now so those
  phases only add routes + policies, never re-seed.
- Override resolution reads role defaults from the canonical `PermissionRegistry`
  (not `role_permission_assignments`) so it works unseeded in feature tests;
  `PermissionMatrixTest` proves DB == registry, so the two never drift.

### Commands that passed
- `docker compose exec app php artisan migrate:fresh --seed` → 26 migrations OK
  (PostgreSQL 16; +5 for Phase 8); PermissionSeeder → 54 permissions, 8 roles, 82 assignments.
- `php artisan test` → **197 passed (959 assertions)**; `--parallel` → 197 (4 procs).
- `php artisan test tests/Feature/Auth/` → 72 passed (Phase 8 + auth).
- `composer pint -- --test` → PASS (236 files) · `composer stan` → No errors (L8).
- `npm run typecheck` → 0 errors · `npm run test` → **72 passed** · `npm run build` → built.
- `npm run lint` → 0 errors (28 pre-existing stub warnings) · `npm run e2e` → **27 passed** (axe clean).
- `gitleaks detect --no-git --redact` → no leaks · `npm audit --audit-level=high` → 0.
- `composer audit` → 1 documented-ignored advisory (GHSA-5vg9-5847-vvmq, carried since Phase 1).

### Commands that failed, if any
- During verification, 7 Phase 7 branch tests acted as Merchant Admin on
  profile/hours/day routes that the §10.3 matrix assigns to Branch Manager — they
  were updated to act as an assigned Branch Manager (+ added admin-denied cases).
  One e2e (`auth-magic-link` check-email) flaked once on the first full run and
  passed on re-run; the branches e2e `/me` mock gained the admin permission set.

### Context for Phase 9 (Tenant-scoped data access hardening)
- Apply `BelongsToMerchant`/`BelongsToBranch` traits to all tenant/branch-owned
  models, scoped route binding, `LogUnauthorizedAttempt`, `TenantAwareJob`, and
  activate the PHPStan tenancy rule (placeholders exist from Phase 1). Demonstrate
  every §8.4 denied case with recorded transcripts in `docs/proof/phase9.md`.
- Phase 8 leaves `EnsurePermission` + policies as the authorization boundary and
  the `audit_logs` immutable seam ready; Phase 9 generalises tenant isolation and
  should record denied attempts (`LogUnauthorizedAttempt`) via the AuditRecorder.

## Phase 7 — Branches, memberships, invitations

- **Branch:** `phase-7-branches-memberships-invitations` → **PR #7 merged into main.**
- **Status:** ✅ Complete. **CI passed: Backend, Frontend, Security, Docker.**
- **Proof:** [docs/proof/phase-7.md](proof/phase-7.md).

### Completed
- Expanded `merchant_branches` forward-only (`status_reason`, `suspended_at`,
  `archived_at`, `updated_by`); new tables `branch_user_assignments`,
  `staff_invitations`, `staff_profiles`, `staff_history`,
  `branch_operating_hours`, `branch_calendar_exceptions`, `branch_day_records`,
  `branch_cash_ups` (seam). Enum-backed statuses + DB CHECKs + partial unique
  indexes (one active assignment per member+branch; one pending invite per
  merchant+email+role+branch; active staff phone unique platform-wide).
- Branch CRUD (admin-only create/update/archive, merchant-scoped list/show),
  operating-hours upsert, day open/close, `BranchClosureGuard` (8 Scope §3.3
  blockers — unclosed-day + cash-up-discrepancy enforced now; queue/session/
  invoice/payment/receipt/appointment are explicit named stubs for Phases 16–18),
  `BranchDebtGate` stub (returns 0 until Phase 20).
- Staff invitations: `CreateStaffInvitation` (hashed 72h token, raw token only in
  email), `AcceptStaffInvitation` (atomic: user + active membership + staff_profile
  + active branch assignment + initial history), resend (rotates token, increments
  count), revoke. Authority: admin invites branch_manager/hr only; HR invites
  operational roles within its own branch (Scope §3.2/§3.4).
- `StaffLifecycleService`: activate/suspend/deactivate/assignBranch/revoke —
  transactional, records `staff_history`; suspend/deactivate revokes DB sessions +
  unused Magic Links + pending invitations; sole-active-admin orphan guard;
  branch-assignment-required-to-activate guard.
- `EnsureBranchScope` middleware (foreign branch ULID → 404 no leak; missing
  assignment → 403 `no_branch_scope`; admin sees all own-merchant branches).
- Magic Link eligibility **check 6** wired (`LoginEligibilityService`): a
  branch-scoped role needs an active branch assignment; admin/platform exempt.
- `/api/v1/me` bootstrap gains `branch_ids`; `TenantContext` carries branch scope
  and now `reset()`s per resolution (fixes a stale-context defect — see proof §7).
- SPA: branch list/create/detail/operating-hours, staff list (status badges) /
  invitations (create/resend/revoke) / public invitation-accept / staff profile;
  `branchStore` + `staffStore`; routes + `requiresPendingSetup` reuse.

### Work skipped (deferred) — owning future phase
```
Skipped:
- Item: Role & permission registry + policies + matrix enforcement. Phase 7 uses
  coarse role checks (merchant_admin / hr) inline in controllers.
- Reason: the §10.3 registry is Phase 8.
- Correct future phase: Phase 8
- Risk if forgotten: fine-grained permissions not enforced; mitigated — coarse
  authority + branch scope are enforced now.

Skipped:
- Item: Real branch-closure blockers for queue/session/invoice/payment/receipt/
  appointment, and real branch-fee debt.
- Reason: those operational/finance tables are Phases 16–18/20. Each is an
  explicit named guard method returning false now (never a silent skip).
- Correct future phase: Phase 16 (queue/sessions/appointments), 17/18 (invoices/
  payments/receipts), 20 (billing debt)
- Risk if forgotten: a branch could be archived with live records — mitigated by
  the named stubs that the owning phase flips on.

Skipped:
- Item: Full cash-up / reconciliation / payment-validation workflow.
- Reason: `branch_cash_ups` is a Phase 7 lifecycle seam only.
- Correct future phase: Phase 18
- Risk if forgotten: none now; table + model exist for the closure-guard check.

Skipped:
- Item: BelongsToMerchant/BelongsToBranch traits across all models + PHPStan
  tenancy rule activation.
- Correct future phase: Phase 9
- Risk if forgotten: tenant scoping is enforced per-controller now, not globally.

Skipped:
- Item: Profile photo upload (`profile_photo_path` is a nullable seam).
- Correct future phase: Phase 23
- Risk if forgotten: none; metadata column ready.

Skipped:
- Item: API pagination/filter traits → Phase 10; final role navigation → Phase 11;
  responsive/dark/a11y sweeps → 12/13/14; scheduling/queue → 16; audit chain
  completion → 19; Horizon → 21; search → 22; deploy → 25.
```

### Pending work
- None. PR #7 merged into main; CI passed (Backend, Frontend, Security, Docker).

### Known risks
- Branch-closure blockers for later-phase operational state are named stubs
  returning false; the owning phase (16–18/20) MUST flip each one on.
- Authority was coarse (role-based) until the Phase 8 permission registry replaced
  the inline `assert*` checks with `EnsurePermission`.
- Session revocation deletes DB-backed session rows; under a non-database session
  driver the membership-status re-check in ResolveTenantContext is the backstop.

### Commands that passed
- `docker compose exec app php artisan migrate:fresh` → 28 migrations OK (PostgreSQL 16).
- `docker compose exec app php artisan test` → **160 passed (817 assertions)**.
- `docker compose exec app php artisan test --parallel` → green (see proof).
- `docker compose exec app php artisan test --group=branches,hr,isolation` → **51 passed**.
- `composer pint -- --test` → PASS (199 files) · `composer stan` → No errors (level 8).
- `npm run typecheck` → 0 errors · `npm run test` → **71 passed** · `npm run build` → built.
- `npm run e2e` → **27 passed** (auth 5 + branches/staff 7 + foundation 11 + onboarding 4, axe clean).
- `gitleaks detect --no-git --redact` → no leaks · `npm audit --audit-level=high` → 0.
- `composer audit` → 1 documented-ignored advisory (CVE-2026-48019, carried since Phase 1).
- Live: created branch + `CreateStaffInvitation` → Mailpit delivered "You're invited
  to join … on Servana" to the invitee with a `staff/accept?token=` link; the DB row
  stored only a 64-char `token_hash` (no raw token).
- `php artisan route:list` → branch + staff routes present; no platform branch-creation route.

### Commands that failed, if any
- None outstanding. Three defects found + fixed during verification (DB-default
  status not hydrated on create; stale `TenantContext` across reused scoped
  instance; Phase 6 eligibility test contradicting newly-enforced check 6) —
  see proof §7.

### Context for Phase 8 (Roles & permissions)
- Build the §10.3 permission registry (`roles`, `permissions`,
  `role_permission_assignments`, `merchant_user_permission_overrides`),
  `PermissionSeeder`, TenantContext permission resolution (cached per request),
  `EnsurePermission` middleware, and policies — then replace the coarse inline
  `assert*` role checks in the Branch/Staff controllers with permission gates and
  populate `permissions` in `/api/v1/me`.

## Phase 6 — Account & tenant model

- **Branch:** `phase-6-account-tenant-model` → **PR #6 merged into main.**
- **Status:** ✅ Complete. **CI passed: Backend, Frontend, Security, Docker.**
- **Proof:** [docs/proof/phase-6.md](proof/phase-6.md).

### Completed
- Schema (forward-only): `merchants`, `merchant_profiles`, `merchant_users`,
  `merchant_status_histories`, minimal `merchant_branches` (Phase 6 seam),
  `is_platform_staff` on `users`. Enum-backed statuses + DB CHECK constraints.
- Merchant Administrator self-registration → `RegisterMerchant` (transactional:
  user + merchant `pending_setup` + profile + `merchant_admin`/`active`
  membership + status history; emails owner a Magic Link). Uniform 202, no
  enumeration, no duplicate state. No Super Admin/KYC route or UI exists.
- First-time setup → `CompleteFirstTimeSetup` (transactional: tier, profile,
  ≥1 branch, initial Branch+HR invited memberships auto-selected to the single
  branch, welcome emails, merchant → `active`, status history). `GET`/`POST`
  `/api/v1/merchant-registration/first-time-setup` gated to pending_setup +
  merchant_admin.
- Tenant context: `TenantContext` + `TenantContextResolver` +
  `ResolveTenantContext` middleware; `EnsureMerchantActive` /
  `EnsureFirstTimeSetupAccess` gates; `TenantAccessException` envelope codes.
- Phase 5 eligibility checks 2 & 4 now enforced (`User::hasTenantAccess`);
  `AUTH_ENFORCE_TENANCY_ELIGIBILITY` defaults true. Check 6 stays Phase 7.
- `/api/v1/me` returns `{ user, merchant, membership, memberships, permissions,
  setup }`; verify endpoint populates tenant context before responding.
- SPA: `RegisterMerchant.vue`, 4-step `FirstTimeSetup.vue`, merchant
  `Dashboard.vue` shell; `onboardingStore`; rewired `authStore`/`merchantStore`;
  global `router.beforeEach` awaits bootstrap before guards; pending→wizard routing.

### Work skipped (deferred) — owning future phase
```
Skipped:
- Item: Full branch CRUD + branch operational lifecycle (operating hours,
  calendar, day open/close, cash-ups, closure protection). Only a MINIMAL
  merchant_branches table/model was created as the Phase 6 setup seam.
- Reason: Plan assigns the full branch entity to Phase 7; Phase 6 needs only ≥1
  branch so initial staff have a branch to be assigned to (Scope §3.2 step 3/5).
- Correct future phase: Phase 7
- Risk if forgotten: branches cannot be managed/closed; mitigated — Phase 7 owns it.

Skipped:
- Item: Staff invitation accept/revoke/resend lifecycle + branch_user_assignments.
  Phase 6 creates invited merchant_users rows + safe welcome emails only.
- Reason: invitation tokens/accept flow + branch assignment belong to Phase 7.
- Correct future phase: Phase 7
- Risk if forgotten: invited Branch/HR users cannot yet sign in (status=invited,
  eligibility check 4 fails) — intended until Phase 7 activates them.

Skipped:
- Item: Branch assignment enforcement (Magic Link eligibility check 6).
- Reason: branch_user_assignments does not exist yet.
- Correct future phase: Phase 7
- Risk if forgotten: branch-scoped roles would be under-restricted at login;
  mitigated — membership status (check 4) still gates them.

Skipped:
- Item: Instant session/token revocation on staff lifecycle events.
- Reason: depends on the Phase 7 staff lifecycle service.
- Correct future phase: Phase 7
- Risk if forgotten: suspended staff session lingers until idle timeout.

Skipped:
- Item: Role & permission registry; `permissions` in /me stays []`.
- Correct future phase: Phase 8
- Risk if forgotten: no fine-grained authorization (guards are UX-only).

Skipped:
- Item: BelongsToMerchant/BelongsToBranch traits + scoped route binding across
  all models; PHPStan tenancy rule activation.
- Correct future phase: Phase 9
- Risk if forgotten: cross-tenant data access not yet structurally enforced on
  future resource models (none exist yet beyond Phase 6-owned endpoints).

Skipped:
- Item: Merchant logo upload pipeline (only `logo_path` metadata column exists).
- Correct future phase: Phase 23 (upload scanning)
- Risk if forgotten: no logo upload; metadata seam is ready.

Skipped:
- Item: Service-fee-tier pricing maths / Citrus platform fee invoicing.
- Correct future phase: Phase 17 (invoicing) / Phase 20 (billing)
- Risk if forgotten: tier is persisted but has no financial effect yet (correct).

Skipped:
- Item: Full /api/v1 conventions + pagination traits → Phase 10; final role
  navigation → Phase 11; responsive sweep → Phase 12; dark mode → Phase 13;
  a11y release gate → Phase 14; Horizon → Phase 21; search → Phase 22; deploy → Phase 25.
```

### Pending work
- None. PR #6 merged into main; CI passed (Backend, Frontend, Security, Docker).

### Known risks
- Minimal `merchant_branches` table is a Phase 6 seam; Phase 7 must EXPAND it
  forward-only (operating hours, assignments, day records, cash-ups) — never
  recreate it.
- Invited Branch/HR users are `status=invited` and cannot sign in until Phase 7's
  accept flow activates them (intended; welcome email explains Magic Link login).
- `/me` shape changed from Phase 5 flat to the nested tenant bootstrap — Phase 5
  frontend/back tests were updated to the new contract (documented in proof §7).
- Suspension/deactivation revocation remains user-level (Phase 7 adds session/link
  row invalidation on staff lifecycle).

### Commands that passed
- `docker compose exec app php artisan migrate:fresh` → 12 migrations OK (PostgreSQL 16).
- `docker compose exec app php artisan test` → **109 passed (521 assertions)**.
- `docker compose exec app php artisan test --parallel` → **109 passed (4 processes)**.
- `docker compose exec app php artisan test --group=onboarding,tenancy` → 40 passed.
- `composer pint -- --test` → PASS (126 files) · `composer stan` → No errors (level 8).
- `npm run typecheck` → 0 errors · `npm run test` → **51 passed** · `npm run build` → built.
- `npm run e2e` → **20 passed** (auth 5 + foundation 11 + onboarding 4, axe clean).
- `gitleaks detect --no-git --redact` → no leaks · `npm audit --audit-level=high` → 0.
- `composer audit` → 1 documented-ignored advisory (CVE-2026-48019, carried since Phase 1).
- Live: `POST /merchant-registration/self-register` → 202; Mailpit delivered the
  owner "Your Servana sign-in link"; completing setup delivered both Branch + HR
  "You've been added to … on Servana" welcome emails (Mailpit total 3).
- `php artisan route:list` → no platform/super-admin merchant-creation route exists.

### Commands that failed, if any
- None outstanding. During verification the onboarding E2E initially failed
  (router guards evaluated before the async `/me` bootstrap on hard navigation);
  fixed with a global `router.beforeEach` that awaits bootstrap — see proof §7.

### Context for Phase 7 (Branches, memberships, invitations)
- Expand `merchant_branches` forward-only; add `branch_user_assignments`,
  `staff_invitations`, `staff_profiles`, `staff_history`. Implement branch CRUD
  (admin-only create), `EnsureBranchScope`, the invitation accept flow
  (token → activate invited merchant_users → branch assignment → status active),
  `StaffLifecycleService` (suspend/deactivate revokes sessions+links). Then wire
  Magic Link eligibility check 6 (branch assignment) and flip its seam in
  `LoginEligibilityService::hasRequiredBranchAssignment`.

## Phase 5 — Authentication (Magic Link + sessions)

- **Branch:** `phase-5-authentication` → **PR #5 merged into main.**
- **Status:** ✅ Complete. **CI passed: Backend, Frontend, Security, Docker.**
- **Proof:** [docs/proof/phase-5.md](proof/phase-5.md).

### Completed
- `magic_login_tokens` table + auth-owned expand of `users` (`ulid`, `status`,
  `last_login_at`; `password` nullable per Plan A3).
- `Domain/Auth/*`: token service (random 64B, SHA-256 at rest, 15-min, atomic
  single-use), `LoginEligibilityService` (seven-check contract), request/consume
  actions, branded `MagicLoginLinkNotification`, interim `AuthEventLogger`.
- Endpoints: `POST /auth/magic-link` (uniform 202), `POST /auth/magic-link/verify`
  (atomic consume → session login + id regeneration; uniform 422
  `invalid_or_expired_token`), `POST /auth/logout` (204), `GET /me` (`auth:sanctum`).
- Laravel Sanctum installed + SPA stateful mode (`statefulApi()`, `sanctum` guard).
- `EnforceIdleTimeout` middleware (60 min, §9.2). All Magic Link limiters wired.
- SPA: real `Login.vue`/`CheckEmail.vue`/`Verify.vue` (stubs deleted); `authStore`
  bootstrap/request/verify/logout; `App.vue` bootstrap on mount.
- MFA: safe `MfaController` placeholder (`mfa_not_enabled`, unrouted) — real TOTP deferred.

### Commands that passed
- `docker compose exec app php artisan test --group=auth` → **28 passed (104 assertions)**.
- `docker compose exec app php artisan test` → **69 passed (230 assertions)**.
- `composer pint -- --test` → PASS · `composer stan` → No errors (level 8).
- `npm run typecheck` → 0 errors · `npm run test` → 38 passed · `npm run build` → built.
- `npm run e2e` → 16 passed (auth 5 + foundation 11).
- `gitleaks --no-git` → no leaks · `npm audit --audit-level=high` → 0 · `composer audit` → 1 documented-ignored.
- Live: `POST /auth/magic-link` → 202; Mailpit delivered branded mail (86-char token); reuse → 422; missing token → 422 validation.

### Commands that failed / limitations
- Live HTTP capture of the clean `200` verify, `429` throttle, and `/me`→logout
  cycle hit nginx 504/timeouts because the Windows Docker host was CPU-bound this
  session (a queued job took ~3 min). Behaviour is proven by the feature suite on
  real PostgreSQL (see proof §5). Two defects found & fixed during verification —
  test-env override (`tests/bootstrap.php`) and worker `mail` queue — see proof §7.

### Skipped (deferred)
```
- Merchant self-registration / tenant model → Phase 6
- Eligibility checks 2 & 4 (membership/role) enforcement → Phase 6 (seam + flag in place; MUST flip)
- Eligibility check 6 (branch assignment) enforcement → Phase 7
- Instant session/token revocation on suspension → Phase 7 (invalidated_at column ready)
- Real MFA (TOTP) → later account-model phase (placeholder only now)
- Roles/permissions → 8 · full API → 10 · role nav → 11 · responsive → 12 · dark → 13 · a11y gate → 14
- Horizon → 21 · uploads → 23 · opcache → 24 · deployment → 25
```

### Known risks
- `AUTH_ENFORCE_TENANCY_ELIGIBILITY=false` until Phase 6 — any *active* user passes
  checks 2/4/6 (correct now, no tenants exist; hard Phase 6 gate).
- Suspension revocation partial (user-level only; session-row deletion is Phase 7).
- Host performance only (not code) limited some live captures.

### Context for Phase 6
- Build merchants/merchant_profiles/merchant_users + onboarding; fill the eligibility
  seam methods and flip the flag; populate `/me` memberships/permissions (6/8).

## Phase 4 — Frontend foundation

- **Branch:** `phase-4-frontend-foundation` → **PR #4 merged into main.**
- **Status:** ✅ Complete. **CI passed: Backend, Frontend, Security, Docker.**
- **Proof:** [docs/proof/phase-4.md](proof/phase-4.md).

### Completed
- 8 layout shells (accessible landmarks, skip link, dark-mode tokens).
- Router: `index.ts` + 9 route modules + `guards.ts` (UX-only stubs).
- 6 Pinia stores: auth, merchant, branch, permission, theme (localStorage), notification.
- `services/apiClient.ts` — axios + CSRF helper + typed `ApiError` mapping Phase 3 envelope.
- `composables/useForm<T>` — dirty, touched, errors, server 422 merge, duplicate-submit guard.
- 9 UI components: SvButton, SvInput, SvSelect, SvTextarea, SvCard, SvModal, SvToast, SvStateBoundary, SvEmptyState.
- `pages/dev/DesignSystemDemo.vue` at `/dev/design-system`.
- Playwright suite: 11 tests (3 breakpoints, no horizontal scroll, theme toggle, axe WCAG AA).
- Vitest: 27 tests (apiClient, useForm, SvStateBoundary).
- Accessibility violations found and fixed: `aria-prohibited-attr` + `color-contrast`.

### Commands that passed
- `npm run typecheck` → 0 errors.
- `npm run test` → 27 passed.
- `npm run build` → built in 2.21s, no errors.
- `npm run e2e` → 11 passed (17s).
- `composer pint --test` → PASS.
- `composer stan` → PASS (Larastan level 8, 0 errors).
- `npm audit --audit-level=high` → 0 vulnerabilities.
- `gitleaks detect --no-git` → no leaks.

### Commands that require Docker
- `php artisan test --parallel` → 40 passed, 1 failed (`DeepHealthTest` needs PostgreSQL + Redis; same known constraint as Phase 3).
- `make up / make fresh / make test` → requires Docker Desktop.

### Skipped (deferred)
```
Skipped:
- Item: Full Magic Link authentication flow
- Reason: Phase 4 stubs auth routes only.
- Correct future phase: Phase 5 (Authentication)
- Risk if forgotten: no login.

Skipped:
- Item: Authenticated /me bootstrap and real auth store data
- Reason: Requires Phase 5 auth flow.
- Correct future phase: Phase 5
- Risk if forgotten: auth store empty; guards remain UX stubs.

Skipped:
- Item: Account and tenant model
- Correct future phase: Phase 6
- Risk if forgotten: no multi-tenancy.

Skipped:
- Item: Tenant middleware / tenant data hardening
- Correct future phase: Phase 6 / Phase 9
- Risk if forgotten: cross-tenant leakage not enforced.

Skipped:
- Item: Branches, memberships, invitations
- Correct future phase: Phase 7
- Risk if forgotten: no org structure.

Skipped:
- Item: Role and permission registry
- Correct future phase: Phase 8
- Risk if forgotten: guards stay as stubs.

Skipped:
- Item: Full /api/v1 route surface and pagination traits
- Correct future phase: Phase 10 (API foundation)
- Risk if forgotten: no API endpoints.

Skipped:
- Item: Final role navigation lists (verbatim from Scope)
- Correct future phase: Phase 11
- Risk if forgotten: nav stubs only.

Skipped:
- Item: Full responsive sweep across all product workflows
- Correct future phase: Phase 12

Skipped:
- Item: Full dark mode across all product workflows
- Correct future phase: Phase 13

Skipped:
- Item: Full accessibility release gate across all critical flows
- Correct future phase: Phase 14

Skipped:
- Item: Horizon, upload scanning, opcache, deployment
- Correct future phase: Phase 21 / Phase 23 / Phase 24 / Phase 25
```

### Known risks
- Button contrast fix deviates from brand assumption of "white on orange"; brand owner should review.
- Router guards are UX stubs only; no backend auth enforcement until Phase 5.
- `DeepHealthTest` requires Docker to pass.

### Context for Phase 5 (Authentication — Magic Link)
- Branch from merged main as `phase-5-authentication`.
- `authStore`, `apiClient`, `primeCsrfCookie()`, `useForm`, `AuthLayout`, and `auth.login`/`auth.verify` routes are ready.
- Phase 5 implements: Magic Link request + "check your email" page, `/auth/verify?token=…` consumption, Sanctum session, `/api/v1/me` bootstrap, all 7 Scope §2.3 checks, session revocation on suspension.

---

## Phase 3 — Laravel backend foundation

- **Branch:** `phase-3-laravel-backend-foundation` (based on merged main: PR #1 + PR #2).
- **Status:** ✅ Complete — merged PR #3.
- **Proof:** [docs/proof/phase-3.md](proof/phase-3.md).

### Completed
- 20 `app/Domain/*` folders (Plan §5.1) with `.gitkeep`.
- `app/Support/Money.php` (integer minor units, currency-checked, integer-only
  formatting) + `CurrencyMismatchException`; `Currency` (KES + USD forward-compat),
  `Severity`, `ErrorCode` enums.
- API error envelope `{ error: { code, message, fields, meta } }` (Plan §11.5)
  via `ApiErrorRenderer` wired in `bootstrap/app.php`; 5xx generic + correlation id.
- `CorrelationIdMiddleware` (global) + `CorrelationId` holder; safe inbound id or ULID.
- Structured logging: `Redaction\Redactor` + Monolog `RedactionProcessor`,
  `CorrelationIdProcessor`, `StructuredLogTap` (tapped on `single`/`stderr`).
- All 7 named rate limiters (Plan §9.3) registered in `AppServiceProvider`.
- `/health` (dependency-free) + `/health/deep` (db/redis/cache required;
  meilisearch/s3 optional; no leaks) via `HealthController`.
- `sentry/sentry-laravel ^4.10` wired (`Integration::handles`), env placeholders only.
- Framework tables (sessions/cache/jobs/job_batches/failed_jobs) confirmed in the
  3 default migrations — **no new migration needed**.
- `routes/api.php` registers `/api/v1` group (no business routes — Phase 10).

### Commands that passed (run in the Docker `app` container, PHP 8.3)
- `make up` → all services healthy; `make fresh` → migrated on PostgreSQL 16.
- `make test` → Pint PASS (49 files), Larastan level 8 OK,
  `php artisan test --parallel` **41 passed (124 assertions), 4 processes**.
- `npm run build` → built with Vite 8 → `public/spa`.
- `gitleaks detect --no-git` → no leaks; `composer audit` → 1 documented-ignored;
  `npm audit --audit-level=high` → 0 vulnerabilities.

### Failed checks
- None outstanding. Two defects found and fixed during verification (Sentry vendor
  sync; Larastan Monolog type narrowing) — see proof §4.

### Skipped (deferred)
```
Skipped:
- Item: Full Magic Link authentication flow
- Reason: Phase 3 only registers the rate-limiter names; the flow is auth scope.
- Correct future phase: Phase 5 (Authentication)
- Risk if forgotten: no login.

Skipped:
- Item: Tenant model + ResolveTenantContext/EnsureBranchScope middleware
- Reason: requires the merchant/branch schema.
- Correct future phase: Phase 6 (tenant model) / Phase 9 (isolation hardening)
- Risk if forgotten: no multi-tenancy enforcement.

Skipped:
- Item: Branches, memberships, invitations
- Correct future phase: Phase 7
- Risk if forgotten: no org structure.

Skipped:
- Item: Role + permission registry / policies
- Correct future phase: Phase 8
- Risk if forgotten: no authorization.

Skipped:
- Item: Full /api/v1 route surface + Idempotency-Key + pagination traits
- Reason: only the group is registered now.
- Correct future phase: Phase 10 (API foundation)
- Risk if forgotten: no API endpoints.

Skipped:
- Item: Frontend foundation (layouts, stores, design-system core)
- Correct future phase: Phase 4
- Risk if forgotten: no SPA app shell.

Skipped:
- Item: Horizon dashboard; upload scanning; opcache preload; deploy/secrets
- Correct future phase: Phase 21 / Phase 23 / Phase 24 / Phase 25 respectively
- Risk if forgotten: covered by their owning phases (carried from Phase 2).
```

### Known risks
- CVE-2026-48019 (Laravel 11 email-rule) still ignored-with-rationale; revisit at
  Laravel 12 / Phase 5.
- Local PHP 8.5 vs pinned 8.3 (CI/Docker enforce 8.3).
- `/health/deep` treats Meilisearch + S3 as optional so the probe stays green in
  CI where those services are absent (intentional, documented in code).

### Context for the next prompt (Phase 4 — Frontend foundation)
- Branch from merged main (after this PR merges) as `phase-4-frontend-foundation`.
- Stack: `make up && make fresh && make test`; SPA dev via `npm run dev` (Vite 8).
- Phase 4 builds: the 8 role layouts, router + stubbed guards, Pinia stores,
  `apiClient.ts`, `ui/` core components (SvButton, inputs, SvCard, SvModal,
  SvToast, SvStateBoundary, SvEmptyState), light+dark theme tokens + head theme
  script (Plan §6, §12). Tests: Vitest (apiClient error mapping, useForm,
  StateBoundary) + Playwright smoke at 3 breakpoints.
- Backend foundation now available to the SPA: `/health`, `/health/deep`, the
  error envelope shape, and `X-Correlation-ID` on every response.

## Phase 2 — Docker & environment setup

- **Branch:** `phase-2-docker-environment` → **PR #2 merged into main.**
- **Status:** Complete. **CI passed: Backend, Frontend, Security, Docker.**
- **Proof:** [docs/proof/phase-2.md](proof/phase-2.md).

### Completed
- `docker/php.Dockerfile` — PHP-FPM 8.3 alpine; ext `pdo_pgsql, redis, intl,
  gd, bcmath, pcntl, zip, opcache`; Composer; non-root `servana` (uid 1000);
  `dev`/`prod` stages; `git safe.directory` set.
- `docker/nginx.Dockerfile` (non-root nginx-unprivileged + Node 20 SPA build
  stage) and `docker/nginx/default.conf`; `docker/php/{php.ini,opcache.ini,
  entrypoint.sh}`.
- `docker-compose.yml` (app, nginx, postgres:16, redis:7, meilisearch, minio
  + bucket-init, mailpit, clamav [profile], worker, scheduler, spa-builder
  [profile]) with healthchecks; `docker-compose.prod.yml`; `.dockerignore`.
- `.env.example` rewritten with documented vars + Docker hostnames (placeholders
  only); `Makefile` with working targets; `brianium/paratest` +
  `league/flysystem-aws-s3-v3` added; CI `docker` build job + parallel tests.
- `/health` moved to a session-less route (bootstrap/app.php `then:`) so the
  liveness probe has no DB dependency.
- `Logo.svg` confirmed present at `public/assets/brand/Logo.svg` (owner-added) —
  **Phase 1 residual risk closed.**

### Commands that passed
- `make up` → all services healthy (app, nginx, postgres, redis, meilisearch,
  minio, mailpit) + worker/scheduler running + minio-init exited 0.
- `make fresh` → migrations on PostgreSQL 16.
- `make test` → Pint PASS, Larastan level 8 OK, `php artisan test --parallel`
  2 passed (4 processes).
- Reachability: Redis `PONG`; Meilisearch `{"status":"available"}`; MinIO bucket
  `servana` created + Laravel `s3` disk round-trip; Mailpit received a test mail;
  app container `id` → `uid=1000(servana)`.
- gitleaks staged scan → no leaks.

### Skipped (deferred)
```
Skipped:
- Item: Laravel Horizon dashboard/config
- Reason: Horizon not installed until the queue phase; a `worker` container
  running `php artisan queue:work` is the compatible placeholder.
- Correct future phase: Phase 21 (Queues, notifications, scheduled reports)
- Risk if forgotten: no queue dashboard/metrics in production.

Skipped:
- Item: ClamAV upload scanning integration
- Reason: no upload pipeline exists yet; ClamAV daemon is provided behind an
  opt-in `clamav` compose profile (memory-heavy, per Plan §27 risk note).
- Correct future phase: Phase 23 (Security hardening) / Phase 19 (uploads)
- Risk if forgotten: uploaded files unscanned.

Skipped:
- Item: /health/deep readiness probe (DB/cache/queue checks)
- Reason: those subsystems mature in Phase 3; Phase 2 ships a dependency-free
  liveness probe only.
- Correct future phase: Phase 3 (Laravel backend foundation)
- Risk if forgotten: orchestrators can't distinguish live-vs-ready.

Skipped:
- Item: opcache preload + production deploy/secrets/registry push
- Reason: preload script generation is a perf optimization; deployment is a
  later phase. Prod Dockerfile/compose exist but are not deployed.
- Correct future phase: Phase 24 (performance) / Phase 25 (deployment)
- Risk if forgotten: suboptimal prod opcache; no live deploy.
```

### Known risks
- Local PHP 8.5 vs pinned 8.3 (CI/Docker enforce 8.3). Unchanged from Phase 1.
- CVE-2026-48019 (Laravel 11 email-rule advisory) still ignored-with-rationale.
- `make` and `gitleaks` were installed on the dev machine via winget this phase.

### Context for the next prompt (Phase 3 — Laravel backend foundation)
- Work continues on branch `phase-2-docker-environment` until merged; Phase 3
  should branch from the latest Phase 2 (or merged main).
- Dev: `make up && make fresh && make test`. App at http://localhost:8080,
  Mailpit 8025, MinIO console 9101.
- Phase 3 implements: `app/Domain/*` skeleton, `Support/Money.php`, enums,
  error-envelope exception renderer (Plan §11.5), correlation-id middleware,
  structured logging + redaction, named rate limiters (§9.3), Sentry, and the
  `/health/deep` readiness probe. Tests: `Unit/MoneyTest`,
  `Feature/Api/ErrorEnvelopeTest`, `Security/LogRedactionTest`.

## Phase 1 — completed work

- Laravel 11.54 (PHP `^8.3`) scaffold; existing `docs/` and `public/assets/`
  preserved untouched.
- Vue 3 + TypeScript + Vite 5 SPA under `resources/spa` (standalone, builds to
  gitignored `public/spa`).
- Tailwind with brand tokens (Plan §12.1) and exact breakpoints `md:768`,
  `lg:1025` (Plan §13); dark-mode class strategy + flash-prevention script.
- Quality tooling: Pest, Larastan level 8 (+ `NoWithoutTenancyOutsidePlatform`,
  `NoRawSqlConcat` rule placeholders for Phase 9), Pint, ESLint flat + vue-tsc,
  gitleaks pre-commit hook + `.gitleaks.toml`.
- `.github/workflows/ci.yml` — PR-stage pipeline with Postgres 16 + Redis 7
  service containers (Plan §26.2).
- `tests/Feature/SmokeTest` — `/health` 200 + app boot; all gates green.

## Open items carried forward

- ~~`Logo.svg` missing~~ — **resolved in Phase 2**: `public/assets/brand/Logo.svg`
  is present (owner-added).
- CI to be confirmed green on the first PR push.
- CVE-2026-48019 (Laravel 11 email-rule advisory) ignored with documented
  rationale — revisit at Laravel 12 upgrade / Phase 5.
