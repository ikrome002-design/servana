# Phase R2 — Core Audit Completeness (Proof)

**Objective (Plan §79 R2):** complete core audit-event coverage, hash-chain
verification, and secure masked audit reads for the domains already implemented;
close the local requirements for REM-AUD-001.

**Branch:** `phase-r2-core-audit-completeness` · **Base:** merged `main` `8fe575f`
(PR #13, R1) · **Date:** 2026-06-21 · **Runtime:** all commands inside
`servana-app` (PHP 8.3.31 / Laravel 12.62.0), PostgreSQL 16.14 + Redis 7.4.9.

## 1. Prove the problem
Phase V/R1 left REM-AUD-001 open: `audit_logs` had hash columns + an append-only
trigger, but (a) auth events went to a log-only `AuthEventLogger`, (b) most
implemented transitions emitted no audit row, (c) there was no chain verifier,
(d) no masked read API/policies, and (e) the chain was a single global chain, not
the Plan's per-merchant + platform chains.

## 2. Before / after event-coverage matrix

| Domain action | Before | After (typed event) | Severity | Scope (merchant/branch) | Old/new | Test |
|---|---|---|---|---|---|---|
| Magic Link requested | AuthEventLogger (log only) | `login_link_requested` | info | null / null (pre-auth) | — | AuthAuditIntegrationTest |
| Magic Link denied | log only | `login_link_denied` | warning | null / null | — | AuthAuditIntegrationTest |
| Magic Link failed (bad/expired/reused) | log only | `login_link_failed` | warning | null / null | — | AuthAuditIntegrationTest |
| Login success | log only | `login_success` | info | null / null | — | AuthAuditIntegrationTest |
| Logout | log only | `logout` | info | null / null | — | AuthAuditIntegrationTest |
| Merchant self-registration (founding membership) | none | `membership.created` | info | merchant / null | — | AuditEventCoverageTest |
| Invitation created | none | `invitation.created` | info | merchant / branch | — | AuditEventCoverageTest |
| Invitation resent | none | `invitation.resent` | info | merchant / branch | — | AuditEventCoverageTest |
| Invitation revoked | none | `invitation.revoked` | warning | merchant / branch | — | AuditEventCoverageTest |
| Invitation accepted | none | `invitation.accepted` (+`membership.created`) | info | merchant / branch | — | AuditEventCoverageTest |
| Membership activated | none | `membership.activated` | info | merchant / branch | status | AuditEventCoverageTest |
| Membership suspended | none | `membership.suspended` | high | merchant / branch | status | AuditEventCoverageTest |
| Membership deactivated | none | `membership.deactivated` | high | merchant / branch | status | AuditEventCoverageTest |
| Branch assignment granted | none | `branch_assignment.granted` | info | merchant / branch | — | AuditEventCoverageTest |
| Branch assignment revoked | none | `branch_assignment.revoked` | warning | merchant / branch | — | AuditEventCoverageTest |
| Branch created | none | `branch.created` | info | merchant / branch | — | AuditEventCoverageTest |
| Branch profile updated | none | `branch.profile_updated` | info | merchant / branch | yes | AuditEventCoverageTest |
| Branch archived | none | `branch.archived` | high | merchant / branch | status | AuditEventCoverageTest |
| Operating hours updated | none | `branch.operating_hours_updated` | info | merchant / branch | — | (BranchOperatingHours flow) |
| Branch day opened | none | `branch.day_opened` | notice | merchant / branch | — | AuditEventCoverageTest |
| Branch day closed | none | `branch.day_closed` | notice | merchant / branch | — | AuditEventCoverageTest |
| Branch day reopened | none | `branch.day_reopened` | warning | merchant / branch | — | AuditEventCoverageTest |
| Permission override created/updated/revoked | AuditRecorder (Phase 8) | `permission.override.created/updated/revoked` | high | merchant / null | — | PermissionOverrideAuditTest |
| Permission write/self-escalation denied | AuditRecorder (Phase 8) | `permission.override.denied_self_escalation` / `permission.write_denied` | warning | merchant / null | — | (Auth boundary tests) |
| Unauthorized tenant/branch access | AuditRecorder (Phase 9) | `unauthorized_access` | high | merchant / null | — | UnauthorizedAccessAuditTest |

**Legitimately deferred (not R2):**
- Standalone **role change** has no endpoint yet — role is captured in
  `membership.created`/invitation context; a dedicated role-change event arrives
  with the HR phase (15B+) / Phase 19.
- Calendar-exception changes have no endpoint yet → owning branch/scheduling phase.
- Financial/billing/M-Pesa/compensation/SMS/file/export events → Phases 18/19/20/21S/10F.
- Flagged-event workflow (`audit_flagged_events`), exceptional unmasking → Phase 19.

## 3. Canonical typed catalogue + AuthEventLogger removal
- `app/Domain/Audit/Enums/AuditEvent.php` — one enum, snake_cased values,
  central `severity()`. Existing strings preserved verbatim
  (`permission.override.*`, `permission.write_denied`, `unauthorized_access`,
  auth names). `AuditRecorder::record()` now takes an `AuditEvent` (no free-form
  strings in transitions).
- `AuthEventLogger` and `AuthEvent` **deleted**; auth call sites use
  `AuthAuditLogger` (writes via the single `AuditRecorder`). No runtime reference
  to `AuthEventLogger` remains (grep clean).

## 4. Chain integrity + verifier
- Per-merchant + platform chains; shared `AuditChainHasher`; advisory-lock
  serialization. `branch_id` added (forward-only expand) and included in the hash.
- `audit:verify-chain` verifies all chains; `--merchant`/`--platform` scope it.
- Tests (`AuditChainVerificationTest`): valid chains pass; corrupting merchant A
  leaves B valid; forged inserted row detected; verifier mutates nothing.
  Corruption simulated only in the test DB (trigger re-enabled immediately); the
  production trigger is never permanently disabled.

## 5. Masked read API + policies
- `GET /api/v1/audit-logs`, `/audit-logs/{auditLog}` (merchant; `audit.view_full`);
  `GET /api/v1/platform/audit-logs`, `/{auditLog}` (platform; `platform.audit.view`).
  No write/delete routes. Paginated, allowlisted filters
  (action/severity/actor/branch/subject_type/date range), allowlisted sort.
- `AuditValueMasker` masks context + actor email server-side; `AuditLogResource`
  exposes ULIDs only (no internal ids, ip, or hash columns).
- `AuditLogPolicy`: read-only; merchant + branch scope; platform separation;
  foreign-tenant 404. Reused existing permission keys (`audit.view_full`,
  `platform.audit.view`) — no registry change.

## 6. Test results (in `servana-app`, PostgreSQL 16.14 / Redis 7.4.9)

New `tests/Feature/Audit/` suite (all green):

| Test file | Result |
|---|---|
| `AuditEventCoverageTest` | 5 passed |
| `AuthAuditIntegrationTest` | 5 passed |
| `AuditChainVerificationTest` | 4 passed |
| `AuditMaskedReadTest` | 5 passed |
| `AuditBranchScopeTest` | 4 passed |
| `PlatformAuditPolicyTest` | 4 passed |
| `AuditImmutabilityTest` | 3 passed |

Updated existing tests for the new recorder API / audit-to-DB move (not weakened):
- `Auth/AuditReadOnlyTest` — immutability test migrated to `AuditEvent` + savepoint isolation (4 passed).
- `Security/MagicLinkTokenSecurityTest` — asserts the request is audited to `audit_logs` (not the app log) and the raw token leaks into neither (3 passed).

`php artisan audit:verify-chain` → exit 0 ("No audit chains to verify" on an empty
DB; full chain logic proven by `AuditChainVerificationTest`).

Initial failures during development (recorded, then fixed — not erased):
- `AuditImmutabilityTest` + `AuditReadOnlyTest` first run: the trigger's exception
  poisoned the wrapping `RefreshDatabase` transaction (`SQLSTATE 25P02`). Fixed by
  running each blocked mutation in its own savepoint (`DB::transaction`).
- `AuditReadOnlyTest` first run: `TypeError` — old `record(string, …)` call.
  Migrated to `record(AuditEvent, …)`.
- `AuditEventCoverageTest` override case first run: 422 — the override endpoint
  validates the key against the seeded catalogue; added `PermissionSeeder` to that test.
- `MagicLinkTokenSecurityTest` first run: asserted the app log was written, but
  auth now audits to the DB; updated to assert the DB row + token-leak checks.

## 7. Full gates (clean containers)

| Command | Result |
|---|---|
| `composer pint -- --test` | PASS (271 files) |
| `composer stan` (Larastan L8) | No errors (192) |
| `php artisan test` (serial) | **268 passed / 4 skipped** (1217 assertions), 99.3s |
| `php artisan test --parallel` | **268 passed / 4 skipped** (1217 assertions) |
| `php artisan migrate:fresh --seed` (disposable `servana_r2`) | 27 migrations + PermissionSeeder clean |
| `composer audit --locked` | 0 advisories |
| `npm run lint` | 0 errors, 28 pre-existing warnings |
| `npm run typecheck` | 0 errors |
| `npm run test` (vitest) | 72 passed |
| `npm run build` | built |
| `npm run e2e` | first run 26/1 (known `auth-magic-link` flake), rerun **27 passed** |
| `npm audit --audit-level=high` | 0 vulnerabilities |
| `gitleaks detect --no-git --redact` | no leaks |
| `docker build php.Dockerfile --target dev` | built |
| `docker build nginx.Dockerfile --target prod` | built |

The 4 skipped backend tests remain the permanent phase-gated isolation
placeholders (Phases 16/17/18/19). The e2e flake is pre-existing (documented in
R1); R2 changes no frontend code. A passing rerun does not erase the initial
failure — both are recorded here.

## 8. Files created / changed
- **Created:** `app/Domain/Audit/Enums/AuditEvent.php`,
  `app/Domain/Audit/Services/AuditChainHasher.php`,
  `app/Domain/Audit/Support/AuditValueMasker.php`,
  `app/Domain/Auth/Support/AuthAuditLogger.php`,
  `app/Console/Commands/VerifyAuditChain.php`,
  `app/Http/Controllers/Api/V1/Audit/{AuditLogController,FiltersAuditLogs}.php`,
  `app/Http/Controllers/Api/V1/Platform/PlatformAuditLogController.php`,
  `app/Http/Requests/Audit/AuditLogIndexRequest.php`,
  `app/Http/Resources/AuditLogResource.php`, `app/Policies/AuditLogPolicy.php`,
  `database/migrations/2026_06_21_000001_add_branch_id_to_audit_logs.php`,
  `docs/architecture/adr/0008-audit-immutability-and-chain.md`, 7 Audit tests.
- **Changed:** `AuditRecorder` (interface), `DatabaseAuditRecorder`, `AuditLog`
  (branch_id + relation), auth actions + `MagicLinkController`,
  `PermissionOverrideService`, `LogUnauthorizedAttempt`, branch actions,
  `BranchOperatingHoursController`, `StaffLifecycleService`, invitation actions
  (+`RegisterMerchant`), `StaffInvitationController`, `AppServiceProvider`,
  `routes/api.php`.
- **Deleted:** `AuthEventLogger`, `AuthEvent`.

## 9. Work skipped → owning phase
Full event coverage (financial/billing/compensation/M-Pesa/SMS/file/export) +
flagged-event workflow → Phase 19; chain-failure alerting/scheduling → Phase 25;
audit dashboard/frontend → Phase 11/19; audit export/signed delivery → Phase
19/23; exceptional reason-gated unmasking → Phase 19. R3–R7 unchanged.

## 10. Remaining risks
- Adding `branch_id` to the hash means R2's chain definition differs from Phase
  8's global chain; safe because no production rows existed (no deployment).
- Operating-hours audit is emitted from the controller (no domain action exists
  for the weekly upsert) — inside the same transaction; a future action should
  absorb it.

## 11. REM-AUD-001 status
`local_complete` — core coverage, per-merchant/platform chains + verifier, masked
read API + policies, ADR-008, and tests are in place and green locally. Not
`verified_complete` until the PR is merged, CI is green, and the required review
(or a truthful PR-specific governance exception) exists.

## 12. Lifecycle status
`local_complete` — pending push, CI, and review.
