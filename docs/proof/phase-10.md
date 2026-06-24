# Phase 10 — API Foundation Proof

## Checkpoint 0 — Before-State Baseline

**Date:** 2026-06-23  
**Branch:** `phase-10-api-foundation`  
**Base / HEAD:** `7ac20a56cb94e8b54a34f96db637b65e8af4eb91`  
**Scope executed:** Checkpoint 0 only. No Checkpoint 1 lifecycle reconciliation, route-classification implementation, pagination substrate, OpenAPI generation, TypeScript generation, ADR-004, or migration manifest was implemented.

### Branch and PR #20 verification

```
git branch --show-current        phase-10-api-foundation
git rev-parse HEAD               7ac20a56cb94e8b54a34f96db637b65e8af4eb91
git rev-parse origin/main        7ac20a56cb94e8b54a34f96db637b65e8af4eb91
git rev-list --left-right --count origin/main...HEAD
                                  0 0
initial git status --short        ?? AGENTS.md
```

PR #20 verification via `gh pr view 20`:

| Field | Result |
|---|---|
| State | `MERGED` |
| Merged at | `2026-06-23 04:44:35` |
| Merge commit | `7ac20a56cb94e8b54a34f96db637b65e8af4eb91` |
| reviewDecision | blank by solo-maintainer governance posture; not an independent approval |
| Backend CI | SUCCESS |
| Frontend CI | SUCCESS |
| Docker CI | SUCCESS |
| Security CI | SUCCESS |

Starting-state gate passed. The only pre-existing working-tree item was the authorized untracked `AGENTS.md`.

### Plan sections read

Read before product-code edits:

- Plan §0, §2 and §2.1.
- Plan §5.1, §5.4, §5.4a, §6.
- Plan §7 and §8 for ADR context, including ADR-004 ownership by Phase 10.
- Plan §9, §10.2, §10.3 error/request lifecycle context, §11 and §11.5.
- Plan §13.1, §13.2, §13.3.
- Plan §23 and §24.
- Plan §75 and §76.
- Plan §79 R4-R7 carryover.
- Complete Plan §80 Phase 10.
- Plan §§81-82 and §85.

Project Scope sections consulted: none. Checkpoint 0 found no material business-rule ambiguity requiring Scope lookup.

### Checkpoint 0 changes

`AGENTS.md` and `CLAUDE.md` were narrowly corrected so both state:

- `Servana Software Development Plan.md` is the primary engineering source of truth.
- The complete active phase must be read before implementation.
- `Servana Project Scope.md` is secondary business context only when the Plan requires it or a material business-rule ambiguity remains.
- The repository proves the current implementation.
- `PROGRESS.md`, `CHANGELOG.md`, and proof files are historical/evidence records.
- The IDE instruction file is an operating guide only and does not override, replace, or compete with the Development Plan.

No product source, route, migration, dependency, generated artifact, legal copy, permission matrix, or security behavior was changed.

### Repository surfaces inspected

Inspected:

- `routes/api.php`, `routes/web.php`, `routes/console.php`, `bootstrap/app.php`.
- Controllers under `app/Http/Controllers`.
- Form Requests under `app/Http/Requests`.
- API Resources under `app/Http/Resources`.
- Policies under `app/Policies`.
- Middleware under `app/Http/Middleware`.
- Route binding evidence: `getRouteKeyName()` and `BelongsToMerchant::resolveRouteBinding()`.
- Rate limiters in `app/Providers/AppServiceProvider.php`.
- Idempotency domain under `app/Domain/Idempotency/`, `EnsureIdempotentRequest`, `RouteClass`, `RouteClassification`, and `FinancialRouteIdempotencyCoverageTest`.
- Pagination/filter/sort/error-envelope/correlation/tenancy/branch/permission/resource-serialization surfaces.
- `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `.github/workflows/ci.yml`.
- All migrations under `database/migrations`.
- Data dictionaries under `docs/architecture/data-dictionary`.
- Existing ADRs under `docs/architecture/adr`.
- Remediation/proof/governance/traceability/progress/changelog docs required by the checkpoint.

### Route baseline

Commands:

```
docker compose exec app php artisan route:list --json
APP_ENV=testing route collection boot via docker compose exec -T -e APP_ENV=testing app php
```

Counts:

| Surface | Count | Notes |
|---|---:|---|
| Production contract routes | 43 | 41 `/api/v1/*` routes plus `/health` and `/health/deep` |
| Production API routes | 41 | Current non-testing `/api/v1/*` surface |
| Health routes | 2 | `/health`, `/health/deep` |
| Testing-only routes | 12 | Present only when `APP_ENV=testing` |
| Non-API/framework/web routes in current route list | 5 | `/`, `sanctum/csrf-cookie`, local storage GET/PUT, `/up` |
| Production mutation routes | 25 | All currently lack route-class metadata |

Global middleware relevant to every request: `CorrelationIdMiddleware` is prepended globally in `bootstrap/app.php`.

### Current route matrix

OpenAPI presence for every current production route: absent. `docs/api/openapi.json` does not exist and no `composer api:openapi` script exists.

| Method | URI | Name | Class | Auth | Tenant | Branch | Permission | Validation / policy / resource | Pagination / filter / sort |
|---|---|---|---|---|---|---|---|---|---|
| POST | `api/v1/auth/magic-link` | `auth.magic-link.request` | missing | none | no | no | none | `RequestMagicLinkRequest`; public flow | n/a |
| POST | `api/v1/auth/magic-link/verify` | `auth.magic-link.verify` | missing | none | no | no | none | `VerifyMagicLinkRequest`; public flow | n/a |
| POST | `api/v1/auth/logout` | `auth.logout` | missing | sanctum | no | no | none | raw `Request`; auth audit | n/a |
| GET | `api/v1/auth/mfa` | `auth.mfa.status` | missing | sanctum | no | no | none | raw `Request`; `AuthenticatedUserResource` bootstrap | n/a |
| POST | `api/v1/auth/mfa/enroll` | `auth.mfa.enroll` | missing | sanctum | no | no | none | raw `Request`; MFA service | n/a |
| POST | `api/v1/auth/mfa/confirm` | `auth.mfa.confirm` | missing | sanctum | no | no | none | `MfaCodeRequest` | n/a |
| POST | `api/v1/auth/mfa/challenge` | `auth.mfa.challenge` | missing | sanctum | no | no | none | `MfaCodeRequest` | n/a |
| POST | `api/v1/auth/mfa/recovery-challenge` | `auth.mfa.recovery-challenge` | missing | sanctum | no | no | none | `MfaCodeRequest` | n/a |
| POST | `api/v1/auth/mfa/recovery-codes` | `auth.mfa.recovery-codes.regenerate` | missing | sanctum | no | no | `RequireFreshMfa` | raw `Request`; MFA service | n/a |
| POST | `api/v1/merchant-registration/self-register` | `merchant-registration.self-register` | missing | none | no | no | none | `RegisterMerchantRequest` | n/a |
| POST | `api/v1/staff-invitations/accept` | `staff-invitations.accept` | missing | none | no | no | none | `AcceptStaffInvitationRequest` | n/a |
| GET | `api/v1/me` | `me` | missing | sanctum | yes | no | none | `AuthenticatedUserResource` | bounded bootstrap, not paginated |
| GET | `api/v1/merchant-registration/first-time-setup` | `merchant-registration.first-time-setup.show` | missing | sanctum | yes | no | `EnsureFirstTimeSetupAccess` | raw `Request`; JSON resources | bounded setup payload |
| POST | `api/v1/merchant-registration/first-time-setup` | `merchant-registration.first-time-setup.store` | missing | sanctum | yes | no | `EnsureFirstTimeSetupAccess` | `CompleteFirstTimeSetupRequest`; resources | n/a |
| GET | `api/v1/merchant/dashboard` | `merchant.dashboard` | missing | sanctum | yes | no | none | JSON resources | bounded dashboard payload |
| GET | `api/v1/branches` | `branches.index` | missing | sanctum | yes | no | none | `BranchResource::collection` | unbounded `get()`, ordered by name; no filters |
| POST | `api/v1/branches` | `branches.store` | missing | sanctum | yes | no | `branches.create` | `CreateBranchRequest`; `BranchResource`; action/policy via permission | n/a |
| GET | `api/v1/branches/{branch}` | `branches.show` | missing | sanctum | yes | yes | none | `BranchResource` | single resource |
| PATCH | `api/v1/branches/{branch}` | `branches.update` | missing | sanctum | yes | yes | `branch.profile.manage` | `UpdateBranchRequest`; `BranchResource` | n/a |
| POST | `api/v1/branches/{branch}/archive` | `branches.archive` | missing | sanctum | yes | yes | `branches.create` | raw `Request`; `BranchResource` | n/a |
| GET | `api/v1/branches/{branch}/operating-hours` | `branches.operating-hours.show` | missing | sanctum | yes | yes | none | JSON payload | bounded 7-day list |
| PUT | `api/v1/branches/{branch}/operating-hours` | `branches.operating-hours.update` | missing | sanctum | yes | yes | `branch.profile.manage` | `UpdateOperatingHoursRequest`; JSON payload | n/a |
| POST | `api/v1/branches/{branch}/day/open` | `branches.day.open` | missing | sanctum | yes | yes | `day.open_close` | raw `Request`; action | n/a |
| POST | `api/v1/branches/{branch}/day/close` | `branches.day.close` | missing | sanctum | yes | yes | `day.open_close` | raw `Request`; action | n/a |
| GET | `api/v1/staff-invitations` | `staff-invitations.index` | missing | sanctum | yes | no | none | `StaffInvitationResource::collection` | unbounded `latest()->get()` |
| POST | `api/v1/staff-invitations` | `staff-invitations.store` | missing | sanctum | yes | no | policy in controller | `CreateStaffInvitationRequest`; `StaffInvitationPolicy` | n/a |
| POST | `api/v1/staff-invitations/{invitation}/resend` | `staff-invitations.resend` | missing | sanctum | yes | no | policy in controller | route model + `StaffInvitationPolicy` | n/a |
| POST | `api/v1/staff-invitations/{invitation}/revoke` | `staff-invitations.revoke` | missing | sanctum | yes | no | policy in controller | raw `Request`; `StaffInvitationPolicy` | n/a |
| GET | `api/v1/staff` | `staff.index` | missing | sanctum | yes | no | none | `StaffProfileResource::collection` | unbounded `get()`, ordered by display_name; no filters |
| GET | `api/v1/staff/{staff}` | `staff.show` | missing | sanctum | yes | no | policy in controller | `StaffProfilePolicy`; `StaffProfileResource` | single resource |
| POST | `api/v1/staff/{staff}/suspend` | `staff.suspend` | missing | sanctum | yes | no | policy in controller | raw `Request`; `StaffProfilePolicy` | n/a |
| POST | `api/v1/staff/{staff}/activate` | `staff.activate` | missing | sanctum | yes | no | policy in controller | raw `Request`; `StaffProfilePolicy` | n/a |
| POST | `api/v1/staff/{staff}/deactivate` | `staff.deactivate` | missing | sanctum | yes | no | policy in controller | raw `Request`; `StaffProfilePolicy` | n/a |
| GET | `api/v1/staff/{staff}/permissions` | `staff.permissions.show` | missing | sanctum | yes | no | policy in controller | `MerchantUserPolicy::viewPermissions`; JSON payload | bounded permission payload |
| POST | `api/v1/staff/{staff}/permissions` | `staff.permissions.store` | missing | sanctum | yes | no | policy/service in controller | `StorePermissionOverrideRequest`; `PermissionOverrideService` | n/a |
| DELETE | `api/v1/staff/{staff}/permissions/{permission}` | `staff.permissions.destroy` | missing | sanctum | yes | no | policy/service in controller | no Form Request; `PermissionOverrideService` | n/a |
| GET | `api/v1/hr/permission-preview` | `hr.permission-preview` | missing | sanctum | yes | no | controller assertion | raw `Request`; validated manually with `validate()` | bounded permission payload |
| GET | `api/v1/audit-logs` | `audit-logs.index` | missing | sanctum | yes | no | `audit.view_full` + policy | `AuditLogIndexRequest`; `AuditLogPolicy`; `AuditLogResource` | paginated default 25 max 100; allowlisted filters/sorts |
| GET | `api/v1/audit-logs/{auditLog}` | `audit-logs.show` | missing | sanctum | yes | no | `audit.view_full` + policy | `AuditLogPolicy`; `AuditLogResource` | single resource |
| GET | `api/v1/platform/audit-logs` | `platform.audit-logs.index` | missing | sanctum | yes | no | `platform.audit.view` + policy | `AuditLogIndexRequest`; `AuditLogPolicy`; `AuditLogResource` | paginated default 25 max 100; allowlisted filters/sorts |
| GET | `api/v1/platform/audit-logs/{auditLog}` | `platform.audit-logs.show` | missing | sanctum | yes | no | `platform.audit.view` + policy | `AuditLogPolicy`; `AuditLogResource` | single resource |
| GET | `health` | `health` | missing | none | no | no | none | `HealthController@live` | liveness |
| GET | `health/deep` | `health.deep` | missing | none | no | no | none | `HealthController@deep` | readiness |

Testing-only route matrix under `APP_ENV=testing`:

| Method | URI | Name | Class | Auth | Tenant | Idempotency |
|---|---|---|---|---|---|---|
| GET | `api/v1/testing/privileged-probe` | `testing.privileged-probe` | missing | sanctum | yes | no |
| POST | `api/v1/testing/step-up/billing_configuration` | `testing.step-up.billing_configuration` | missing | sanctum | no | no |
| POST | `api/v1/testing/step-up/refund_finalization` | `testing.step-up.refund_finalization` | missing | sanctum | no | no |
| POST | `api/v1/testing/step-up/period_reopen` | `testing.step-up.period_reopen` | missing | sanctum | no | no |
| POST | `api/v1/testing/step-up/payout_approval` | `testing.step-up.payout_approval` | missing | sanctum | no | no |
| POST | `api/v1/testing/step-up/payout_mark_paid` | `testing.step-up.payout_mark_paid` | missing | sanctum | no | no |
| POST | `api/v1/testing/step-up/reconciliation_resolution` | `testing.step-up.reconciliation_resolution` | missing | sanctum | no | no |
| POST | `api/v1/testing/step-up/compensation_backdated_change` | `testing.step-up.compensation_backdated_change` | missing | sanctum | no | no |
| POST | `api/v1/testing/idempotency/financial` | `testing.idempotency.financial` | `financial_mutation` | sanctum | yes | yes |
| POST | `api/v1/testing/idempotency/stable-failure` | `testing.idempotency.stable-failure` | `financial_mutation` | sanctum | yes | yes |
| POST | `api/v1/testing/idempotency/boom` | `testing.idempotency.boom` | `financial_mutation` | sanctum | yes | yes |
| POST | `api/v1/testing/idempotency/unsafe-headers` | `testing.idempotency.unsafe-headers` | `financial_mutation` | sanctum | yes | yes |

### Proven gaps

#### Route classification and naming

- Plan §24 requires every non-GET route to declare exactly one classification. Current production mutation routes: 25. Current production mutation routes with classification: 0. Gap: 25.
- `RouteClass` currently has seven values and lacks the required `liveness_readiness` class named by Phase 10.
- Only testing idempotency harness routes declare `financial_mutation`.
- No full route-classification registry exists; current `RouteClassification` only supports the R4 idempotency seam.
- No `RouteSecurityContractTest` exists.
- Naming is inconsistent with Plan §23.1 `domain.resource.action`; examples include `me`, `branches.index`, `staff-invitations.index`, `audit-logs.index`, and mixed hyphenated route names.

#### Security contract

- No test currently fails when a production non-GET route lacks classification, has multiple classifications, misses class controls, or leaks testing-only routes.
- Mutating routes without Form Request evidence include `auth.logout`, `auth.mfa.enroll`, `auth.mfa.recovery-codes.regenerate`, `branches.archive`, `branches.day.open`, `branches.day.close`, `staff-invitations.revoke`, `staff.suspend`, `staff.activate`, `staff.deactivate`, and `staff.permissions.destroy`.
- Several HR/staff mutation routes rely on controller policy/service authorization but do not expose an explicit `EnsurePermission:*` route middleware in the route matrix.
- Platform audit reads currently carry `ResolveTenantContext`; Phase 10 must evaluate this against the platform-route rule that platform routes must not receive merchant tenant context.
- The forbidden Super-Admin merchant-creation route and Merchant Personnel contact-export route were not found in the current route inventory.

#### Pagination, filters, and sorting

- Audit-log index routes have bounded pagination (`per_page` default 25, max 100), allowlisted filters, and allowlisted sort.
- `GET /api/v1/branches`, `GET /api/v1/staff-invitations`, and `GET /api/v1/staff` return unbounded `get()` collections.
- No reusable pagination/filter/sort substrate exists.
- No focused `PaginationContractTest` or `FilterSortContractTest` exists.

#### Resource capability maps

- API resources do not expose server-derived `can` maps.
- Frontend has UX-only `useCan` / `PermissionGate`, but no backend resource capability-map convention.
- No `ResourceCapabilityMapTest` exists.

#### OpenAPI and TypeScript contract

- `docs/api/openapi.json` does not exist.
- No maintained Laravel OpenAPI generator is installed.
- `composer.json` has no `api:openapi` script.
- `resources/spa/src/types/generated/api.ts` does not exist.
- `package.json` has no `api:types` or `api:contract:check` scripts.
- CI has no generated-contract stale-artifact or route/OpenAPI/TypeScript parity check.
- No `OpenApiContractTest` or `OpenApiTypeParityTest` exists.

#### Migration governance

- ADR-004 (`docs/architecture/adr/0004-migration-strategy.md`) does not exist.
- `docs/architecture/migrations/README.md` and `docs/architecture/migrations/manifest.yaml` do not exist.
- No `MigrationManifestTest` exists.
- Existing migrations count: 32. Existing ADRs: 0001, 0002, 0003, 0008, 0009.
- Existing data-dictionary files cover current core/identity/tenancy and branches/staff, but there is no migration manifest inventory distinguishing framework vs. Servana business migrations.

#### Stale gate-closure lifecycle wording

PR #20 is merged and `origin/main` equals the PR #20 merge commit. However, these documents still contain wording from before PR #20 merged:

- `docs/remediation/pre-feature-completion-report.md` says the gate is effective when the gate-closure PR merges.
- `docs/PROGRESS.md` says the gate is effective when the gate-closure PR merges and Phase 10 is not started.
- `docs/CHANGELOG.md` says Phase 10 becomes eligible only after that PR merges and remains not started.
- `docs/traceability/servana-requirements.csv` says the gate is closed effective on merge of the gate-closure PR.

Checkpoint 0 records this as a proven gap. Checkpoint 1 owns lifecycle reconciliation.

### Commands run

```
git fetch origin --prune
git branch --show-current
git status --short
git diff --stat
git diff --cached --stat
git rev-list --left-right --count origin/main...HEAD
git log --oneline --decorate -n 10
gh pr view 20 --json number,state,mergedAt,mergeCommit,reviewDecision,statusCheckRollup,url
docker compose exec app php artisan route:list --json
docker compose exec -T app php <route-matrix script>
docker compose exec -T -e APP_ENV=testing app php <testing-route-matrix script>
rg / Get-Content inspections for required code and documentation surfaces
```

Initial failures / notable command findings:

- A malformed first `rg` regex for capability-map search failed; rerun with a simpler search succeeded.
- Searching missing future directories (`docs/architecture/migrations`, `docs/api`, `resources/spa/src/types/generated`) returned missing-path errors, proving those Phase 10 artifacts do not exist yet.

Tests run: none. Checkpoint 0 is a before-state/documentation checkpoint, and no product-code behavior was changed. Existing test execution is deferred to the focused implementation checkpoints and final Phase 10 gates.

### Remaining risks

- The route matrix records static before-state evidence only. It does not assert the current API is compliant with Phase 10; most Phase 10 controls are not yet implemented.
- Route policy/Form Request mapping is based on controller/request inspection; Checkpoint 2-3 must replace this with enforceable tests.
- Stale gate-closure lifecycle wording remains intentionally uncorrected until Checkpoint 1.
- No generated OpenAPI or TypeScript contract exists, so contract parity cannot yet be tested.

---

# Phase 10 — Implementation Proof

**Branch:** `phase-10-api-foundation` · **Base commit:** `7ac20a5` (merged PR #20).
**Status:** `local_complete` — pushed; pending PR #21 CI, governance review, and merge.
All work below was implemented on this branch on top of the Checkpoint-0 baseline above.

## Checkpoint 1 — Gate-closure lifecycle reconciliation

PR #20 is merged (`7ac20a5`, 2026-06-23 04:44Z; CI Backend/Frontend/Docker/Security
all SUCCESS; reviewDecision blank under the solo-maintainer governance exception —
not an independent approval). The pre-merge wording ("effective when this PR
merges", "Phase 10 must not start", "not started") was corrected to **CLOSED and
effective / Phase 10 started** across:

- `docs/PROGRESS.md` (gate header, blockquote, feature-roadmap row, gate-closure
  section, R7 gate-status paragraph)
- `docs/CHANGELOG.md` (gate entry + Phase 10 eligibility line)
- `docs/remediation/pre-feature-completion-report.md` (gate-status block + decision)
- `docs/remediation/register.yaml` (`meta` comment)
- `docs/governance/solo-maintainer-pre-feature-gate-closure-exception.md` (decision)
- `docs/proof/pre-feature-remediation-gate-closure.md` (final decision + current phase)
- `docs/traceability/servana-requirements.csv` (SRV-GATE-001 row)

The governance exception is recorded as a solo-maintainer review posture, never as
independent approval. No historical technical detail was rewritten.

## Checkpoint 2 — Route classification registry (REM-ROUTE-001)

Extended the **existing R4 seam** (`RouteClass`, `RouteClassification`,
`EnsureIdempotentRequest`, `FinancialRouteIdempotencyCoverageTest`) — not replaced.

- `RouteClass` gained the eighth class `liveness_readiness` and, per class,
  `requiredMiddleware()` / `forbiddenMiddleware()` (substrings matched against the
  gathered middleware) + `requiresValidation()`.
- `RouteClassification` gained `requiredMiddlewareMissing()`,
  `forbiddenMiddlewarePresent()` and the `VALIDATION_EXEMPT` allowlist (one explicit
  place, 12 bodiless mutations, each with a written reason). The R4
  `financialRoutesMissingIdempotency()` guard is preserved verbatim in behaviour.
- Every production **non-GET** route now declares exactly one class via
  `->defaults(RouteClassification::KEY, …)`; the two health probes declare
  `liveness_readiness`; the test-only step-up routes declare
  `authenticated_global_mutation`; the R4 idempotency harness keeps
  `financial_mutation`.

Classification map (25 production mutations): public_mutation ×4
(`auth.magic-link.request/verify`, `merchant-registration.self-register`,
`staff-invitations.accept`); authenticated_global_mutation ×6 (`auth.logout`, MFA
enroll/confirm/challenge/recovery-challenge/recovery-codes); tenant_mutation ×10
(first-time-setup.store, branches.store, staff-invitations store/resend/revoke,
staff suspend/activate/deactivate, staff.permissions store/destroy);
branch_mutation ×5 (branches update/archive/operating-hours.update/day.open/day.close).
`platform_mutation` / `provider_webhook_mutation` exist in the enum and are
enforced by the contract test but are used by **no current route** (those domains
arrive in later phases — no fake routes created). Live compliance scan output:

```
docker compose exec -e APP_ENV=testing app php (registry scan over all api+health routes)
=> ALL-COMPLIANT
```

## Checkpoint 3 — Route security contract + forbidden-route absence

- `tests/Feature/Security/RouteSecurityContractTest.php` — fails on: missing/invalid
  classification on a non-GET route; missing required class middleware; forbidden
  class middleware (public/auth-global tenant context, platform merchant context,
  webhook Sanctum); a financial route without idempotency; a mutation without
  validation (unless explicitly exempted); a stale exemption entry; a test route
  outside the env-gated `testing` namespace.
- `tests/Feature/Security/ForbiddenRouteAbsenceTest.php` — asserts no
  Super-Administrator/platform merchant-creation route and no Merchant Personnel
  contact-export route exists anywhere (Guardrail 8; ADR-010).
- `FinancialRouteIdempotencyCoverageTest` preserved and passing (3 tests).

```
RouteSecurityContractTest ........ 7 passed
ForbiddenRouteAbsenceTest ........ 2 passed
FinancialRouteIdempotencyCoverageTest ... 3 passed
```

## Checkpoint 4 — Pagination / filter / sort substrate

- New reusable `App\Http\Api\ApiPagination` (default 25, max 100, allowlisted sort
  with stable `id` tiebreaker, per-page rejection >100 → 422). The existing
  compliant audit-log endpoints keep their equivalent implementation (not rewritten).
- New index Form Requests `BranchIndexRequest`, `StaffIndexRequest`,
  `StaffInvitationIndexRequest` (allowlisted sorts + validated enum filters +
  bounded `per_page`).
- Retrofitted the three unbounded `get()` collections — `branches.index`,
  `staff.index`, `staff-invitations.index` — to paginate + filter + sort. Bootstrap
  payloads (`me`, dashboard, first-time-setup) and bounded lookups
  (operating-hours, permissions) were intentionally NOT paginated.
- Tests: `PaginationContractTest` (8), `FilterSortContractTest` (5) — default/max/
  over-limit-422/empty/tenant-isolation/ULID-only/allowlisted-sort/invalid-sort-422/
  valid-filter/invalid-filter-422/stable-order.

## Checkpoint 5 — Resource capability (`can`) maps

- New `App\Http\Resources\Concerns\HasCapabilities` derives a `can` map from the
  model's Policy (the §10.3 permission registry). Applied to `BranchResource`
  (view/update/archive/manage_operating_hours/manage_day), `StaffProfileResource`
  (view/manage), `StaffInvitationResource` (manage), `AuditLogResource` (view).
- Only real current actions; values are booleans; ULID public ids only; public/
  health payloads receive no map.
- `ResourceCapabilityMapTest` (4) proves the map differs by authority — a Merchant
  Admin gets `archive=true, update=false`; a Branch Manager gets `update=true,
  manage_operating_hours=true, archive=false`.

Example (`GET /api/v1/branches/{branch}` as Merchant Admin):

```json
"can": { "view": true, "update": false, "archive": true,
         "manage_operating_hours": false, "manage_day": false }
```

## Checkpoint 6 — OpenAPI + TypeScript contract

> **Correction (commit `phase-10: adopt maintained OpenAPI generator`).** The first
> cut used a custom route-derived generator. Per the Phase 10 requirement to use a
> **maintained Laravel 12-compatible** generator, `dedoc/scramble` was adopted as
> the authoritative schema engine. Compatibility was proven against the locked
> repository: `composer require dedoc/scramble --dry-run` resolved
> **v0.13.28** cleanly on Laravel 12.62 / PHP 8.3 (adding only
> `spatie/laravel-package-tools`), with `composer audit` reporting no advisories.

- **Authoritative engine:** `dedoc/scramble` (declared in `composer.json` `require`)
  analyses the routes, Form Requests and API Resources and produces the operations,
  request/response/component schemas and pagination/filter/sort parameters. It
  already emits `operationId` = route name. Configured in `AppServiceProvider`:
  `Scramble::routes()` restricts it to the production `/api/v1` surface (test-only
  harness routes excluded at the source, so the document is environment-identical),
  and `Scramble::ignoreDefaultRoutes()` keeps the docs UI routes out of the app
  (Servana ships the committed artifact, not a live docs endpoint). `api_path` is
  `api/v1` (`config/scramble.php`).
- **Thin wrapper** `App\Support\OpenApi\OpenApiGenerator` (+ `servana:openapi`,
  `composer api:openapi`) invokes Scramble's `Generator` and ONLY: restores full
  `/api/v1` paths under a single `/` server; adds the `/health` probes Scramble does
  not cover; enforces `operationId` = route name; drives production-route parity from
  the live route collection; augments the session security scheme, the §11.5 error
  envelope responses and the financial `Idempotency-Key` header; and writes
  `docs/api/openapi.json` deterministically. Determinism verified: two CLI runs
  byte-identical, and the testing-env regeneration is byte-identical to the
  CLI-committed file.
- Committed artifact `docs/api/openapi.json` — **43 operations / 37 paths**: all 41
  `/api/v1/*` routes + `/health` + `/health/deep`; zero test/future operations;
  operationId = route name; `sanctumSession` security scheme; `ErrorEnvelope`
  schema; 401/403/404/409/422/423/429 where applicable.
- Generated client types `resources/spa/src/types/generated/api.ts` via pinned
  `openapi-typescript@7.4.4` (`npm run api:types`).
- `npm run api:contract:check` (`scripts/check-api-contract.mjs`) fails on: stale
  generated TypeScript, a test-only path/operationId, a duplicate operationId, or a
  spec path missing from the TypeScript. Wired into CI (frontend job).
- Tests: `OpenApiContractTest` (6 — byte-current vs generator, every production
  route documented, both health probes, no test/future/nonexistent operation, no
  duplicate operationId, envelope + security scheme present), `OpenApiTypeParityTest`
  (4 — TS exists, every path + operationId present, no test leak).

## Checkpoint 7 — ADR-004 + migration manifest

- `docs/architecture/adr/0004-migration-strategy.md` — expand-and-contract,
  migration-before-application order, schema compatibility window, backfill+verify,
  contract timing, forward-repair (not destructive rollback), image rollback only
  while schema-compatible, PITR restoration boundary, PostgreSQL-only verification,
  data-dictionary-before-migration.
- `docs/architecture/migrations/README.md` + `manifest.yaml` — inventory of all 33
  migrations (4 framework + 29 Servana business), each business entry with table(s),
  domain, owner phase, change type, data-dictionary reference, dependencies,
  production compatibility, destructive flag, forward-repair, verification.
- `MigrationManifestTest` (6) — fails on missing/dangling/duplicate entries, a
  business migration without an existing data-dictionary reference, a destructive
  change without a forward-repair plan, or an invalid dependency.
- No shipped migration was edited; no new business migration was created.
- Tracked gap: `audit_logs`, `permissions`, `roles`, `role_permission_assignments`
  predate the dictionary files; they carry a domain reference + a `notes` gap marker
  (full per-table entries owed to Phase 19).

## Deliberate failure demonstrations (then reverted)

1. **Route-classification violation** — removed the classification from
   `staff.activate`; `RouteSecurityContractTest` failed:
   `staff.activate => NULL` (1 failed). Reverted.
2. **Stale contract violation** — deleted `/api/v1/branches` from the committed
   `openapi.json`; `OpenApiContractTest` "byte-current" failed (1 failed). Reverted
   via `composer api:openapi`; `diff` against the pre-edit backup → identical.

## Parallel-suite hardening (commit `1d25224`)

- **Initial failure:** `php artisan test --parallel` failed where the serial suite
  passed. The OpenAPI helpers `committedSpec()` / `specOperationIds()` were defined
  as global functions inside `OpenApiContractTest.php`. Under parallel execution the
  worker running `OpenApiTypeParityTest.php` did not load that file, so the helpers
  were undefined → fatal "Call to undefined function" in that process.
- **Root cause:** test helpers depended on another test file being loaded first —
  unsafe across parallel workers.
- **Correction:** commit `1d25224` ("test: make OpenAPI helpers parallel-safe")
  moved `committedSpec()` and `specOperationIds()` into `tests/Pest.php` (always
  autoloaded for every file and worker). These helpers are preserved and reused by
  both OpenAPI tests; the maintained-generator adoption builds on top of them.
- **Result:** the complete parallel backend suite passes — **485 passed, 4 skipped,
  2102 assertions, 4 parallel processes** (re-run again after the Scramble adoption;
  see results below).

## Work skipped (exact owner phases)

```
uploaded files / media pipeline                 -> Phase 10F
role navigation / landing / get-started         -> Phase 11
services / clients / personnel availability     -> Phases 15A/15B
appointments / queues / sessions                -> Phases 16A-16C
invoices / customer payments / receipts         -> Phases 17-18
full audit/flagged-event workflow               -> Phase 19
billing / M-Pesa / compensation / payouts       -> Phases 20A-20H
notifications / reports / personnel SMS          -> Phases 21N/21S
search                                          -> Phase 22
release-wide accessibility/security audit        -> Phase 23
performance                                     -> Phase 24
deployment / backups / alerts                   -> Phase 25
full per-table dict entries for audit/perms/roles -> Phase 19
platform_mutation / provider_webhook routes      -> owning Phase 20 subphases
```

## Remaining risks

- The OpenAPI response **body** schemas are generic (`type: object`) — full
  per-Resource response schemas land as the resources stabilise in feature phases;
  the path/operation/parameter/security/error inventory is complete now.
- `openapi-typescript` pulls two **moderate** (dev-only) advisories via
  `@redocly/openapi-core`; below the `--audit-level=high` gate. Tracked.
- `platform_mutation` / `provider_webhook_mutation` classes are enforced but unused
  until their owning phases add real routes.

## REM-ROUTE-001 / REM-MIG-001 status

`local_complete` — evidence above. They are FEATURE_DELIVERY_OBLIGATION items and
remain `local_complete` until **PR #21 merges**. They are **not** marked
`verified_complete` until that PR merges with green CI — which now includes the
authoritative Linux `E2E — Playwright` job (see Checkpoint 8).

## Test & quality results

### Initial implementation run (commit `e67d2d3`)

```
composer pint/stan/validate ........ clean ; php artisan test ... 485 passed / 4 skipped
api:openapi/types/contract:check ... OK ; lint 0 err ; typecheck clean ; vitest 79 ; build OK
composer audit 0 ; npm audit high 0 ; gitleaks clean
```

### Maintained-generator correction — full gate run (commit `phase-10: adopt maintained OpenAPI generator`)

```
PASSED:
  composer pint -- --test ............................ 382 files, no style issues
  composer stan (Larastan level 8) ................... No errors
  composer validate --strict ......................... ./composer.json is valid
  php artisan test (serial, PostgreSQL) .............. 489 passed, 4 skipped (2110 assertions)
    incl. RouteSecurityContractTest(7), ForbiddenRouteAbsenceTest(2),
          FinancialRouteIdempotencyCoverageTest(3), PaginationContractTest(8),
          FilterSortContractTest(5), ResourceCapabilityMapTest(4),
          OpenApiContractTest(9: +declares dedoc/scramble, +authoritative engine, +pagination params),
          OpenApiTypeParityTest(5: +scramble component schemas), MigrationManifestTest(6)
  php artisan test --parallel ........................ 489 passed, 4 skipped, 4 processes
  php artisan audit:verify-chain ..................... OK (no chains on dev DB)
  composer api:openapi ............................... docs/api/openapi.json (43 ops, dedoc/scramble authoritative)
  npm run api:types .................................. resources/spa/src/types/generated/api.ts (openapi-typescript 7.4.4)
  npm run api:contract:check ......................... OK — 37 paths, 43 operations
  npm run lint ....................................... 0 errors (28 pre-existing warnings)
  npm run typecheck (vue-tsc) ........................ clean
  npm run test (vitest) .............................. 17 files, 79 passed (single-worker isolated run)
  npm run build ...................................... built in 40.92s
  composer audit --locked ............................ No advisories
  npm audit --audit-level=high ....................... exit 0 (2 moderate dev-only, below gate)
  gitleaks detect --no-git --redact .................. no leaks (also clean as pre-commit hook)
  docker build php.Dockerfile --target dev ........... DONE (one external Docker Hub TLS-handshake timeout, clean rerun)
  docker build nginx.Dockerfile --target prod ........ DONE (incl. SPA build)
  npm run e2e (playwright, isolation) ................ STALLED on this Windows host (see below)

FLAKED then passed (recorded, not erased):
  npm run test (vitest) .............................. under concurrent docker load the Windows forks
    pool failed to spawn workers (69/72-79 passed + 3-9 "failed to start forks worker" infra errors,
    not assertion failures). Re-run with --no-file-parallelism (single worker): 17 files / 79 passed
    / 0 errors. No frontend source changed in this correction (only the generated api.ts + tests).
  docker build php.Dockerfile (first attempt) ........ external Docker Hub TLS-handshake timeout pulling
    php:8.3-fpm-alpine; clean rerun built (DONE 318s). Not a Dockerfile/code defect.

NOT COMPLETED on this host (environment, not a code defect):
  npm run e2e (playwright) ........................... STALLED — the Vite preview webServer started
    (port 4173 LISTENING) but the Playwright workers hung without producing a run (no
    test-results/.last-run.json after >10 min); the run was terminated. This is a documented
    Windows webServer/worker flake (R5/R6/R7 proofs record the same e2e instability; Phase 23
    owns the release e2e gate). This correction changes NO e2e flow and NO frontend runtime
    code — only the generated TypeScript types (eslint-ignored, not imported by app code), the
    backend OpenAPI generator, config, and tests — so e2e behaviour is unaffected. The Linux
    CI frontend job runs Playwright and is authoritative; the SPA `npm run build` passed here.
```

## Checkpoint 8 — Linux CI Playwright gate (commit `ci: enforce Phase 10 Playwright gate`)

### Problem proven

The local Windows Playwright run **stalled without producing a completed run** (no
`test-results/.last-run.json`; the run was terminated — recorded above). **No passing
local E2E result is claimed for Phase 10.** Because Phase 10 ships frontend contract
artifacts (generated TypeScript types, pagination/can-map-driven payloads) that the SPA
consumes, the browser suite must be exercised somewhere authoritative before Phase 10
proceeds. The prior CI workflow had **no explicit Playwright job** — the four jobs were
Backend, Frontend (ESLint/vue-tsc/Vitest/build only), Docker and Security. Browser
end-to-end coverage was therefore unenforced in CI.

### Fix

Added an explicit, separate Linux job **`E2E — Playwright`** to `.github/workflows/ci.yml`,
alongside (not merged into) the existing Backend / Frontend / Docker / Security jobs:

```
job id:        e2e
name:          E2E — Playwright            (exact)
runs-on:       ubuntu-latest
timeout-minutes: 20                        (fails the workflow if the run stalls)
steps:
  actions/checkout@v4
  actions/setup-node@v4 (node-version 20, npm cache)
  npm ci
  npx playwright install --with-deps chromium   (browser + Linux system deps)
  npm run build                                 (frontend production build — Vite)
  npm run e2e                                    (Playwright; non-zero exit fails the job)
  actions/upload-artifact@v4 (if: failure())     (playwright-report/ + test-results/)
```

- **Fails on any failure or stall:** `npm run e2e` exits non-zero on any failed Playwright
  test (workflow fails); the job `timeout-minutes: 20` fails the workflow if the run stalls
  without completing (the exact local Windows failure mode), rather than hanging.
- **No weakening:** existing Playwright tests are run unmodified — no test skipped, no extra
  retry, no suppression. `playwright.config.ts` is untouched (CI already pins `retries: 1`,
  `workers: 1`, `forbidOnly` true under `CI`).
- **Diagnosability:** on failure the job uploads `playwright-report/` and `test-results/`
  (`if-no-files-found: ignore`, 7-day retention).
- **Separation preserved:** the four pre-existing jobs (Backend, Frontend, Docker, Security)
  are unchanged; `e2e` is a fifth, independent job. The SPA preview is a static build, so the
  job needs no Postgres/Redis service.

### Authority

The Linux **`E2E — Playwright`** job is the **authoritative Phase 10 browser gate**. **PR #21
must not merge unless that job passes.** REM-ROUTE-001 and REM-MIG-001 remain `local_complete`
until PR #21 merges with green CI (Backend, Frontend, Docker, Security, **and E2E — Playwright**).

### Validation run (this correction)

```
js-yaml parse of .github/workflows/ci.yml ........ valid; jobs = [backend, frontend, docker, security, e2e]
  jobs.e2e.name .................................. "E2E — Playwright" (exact)
  jobs.e2e.runs-on ............................... ubuntu-latest
  jobs.e2e.timeout-minutes ....................... 20
  four existing jobs (backend/frontend/docker/security) ... preserved unchanged
npm run e2e (playwright, this Windows host) ...... STILL STALLS locally — no completed run;
  no passing local E2E result is claimed. The Linux CI job is the authoritative gate.
```

This change touches only `.github/workflows/ci.yml` and the Phase 10 documentation
(`docs/proof/phase-10.md`, `docs/PROGRESS.md`, `docs/CHANGELOG.md`). No product source,
route, migration, dependency, generated artifact, test, Playwright config or frontend
runtime code was changed, so backend/frontend/contract gate results above are unaffected.

