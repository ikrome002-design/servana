# Phase 15A — Services, Catalogue, Clients — Proof

**Branch:** `phase-15a-services-catalogue-clients` · **Base commit:** `d098f37`
(Phase 11 merge, PR #23). **Status:** `local_complete` — the full Phase 15A
specification (backend, authorization, canonical permission activation, client
search, frontend screens, tests, contracts, docs) is implemented and **all local
gates pass**. This is **not** `ci_passed`, `merged`, or `verified_complete`: no PR
has been opened and CI has not run. CI remains authoritative for the Linux
browser/Docker gates.

Frontend visibility is UX only; the API is the security boundary. Money is integer
minor units via the `Money` value object. Tests run against PostgreSQL (never
SQLite). Client contact is encrypted at rest, masked at read, and searched/
de-duplicated via a keyed HMAC blind index that is never returned or logged.

---

## What was built (two slices)

### Slice 1 — foundation (commit `73c7d26`)
5 branch-owned migrations (`service_categories`, `services`,
`service_personnel_eligibility`, `clients`, `client_consents`) with composite-FK
tenant/branch consistency, partial-unique constraints, CHECK enums, integer
minor-unit money, the legacy non-editable `services.preferred_personnel_fee_minor`
seam; 5 enums + 5 models; `PhoneNumberNormalizer` + `ClientContactIndex` (HMAC
blind index); `TenantOwnership` registration; 5 factories; data dictionary;
migration manifest; `ClientContactProtectionTest`.

### Slice 2 — API, permissions, search, frontend (this completion commit)
- **Canonical permission activation (Plan §19.2/§19.3):** reconciled the Phase 15A
  keys from the §10.3 baseline to canonical in `PermissionRegistry` (→ DB seed +
  generated TS): Branch Manager `service.view/create/update/archive` (was
  `services.manage`); HR `personnel.eligibility.manage` (was `eligibility.manage`);
  Front Office `client.view/create/update` + `front_office.search` (was
  `clients.*`). Per §19.3 `client.view` defaults to Front Office only, so the
  unwired legacy `clients.view` grants on other roles were dropped in the
  reconciliation. The 7 affected §10.3 spec tests were updated to canonical key
  names **without weakening** any assertion. **REM-PERM-001 is NOT closed** — the
  full canonical `permission-matrix.yaml` + schema/parity/per-key infrastructure
  remains the Phase 19 deliverable.
- **Backend (architecture: Form Request → Policy/permission → thin controller →
  domain action → Resource):**
  - Catalogue: `CreateService(Category)`, `UpdateService(Category)`,
    `ArchiveService` actions; `ServicePolicy`/`ServiceCategoryPolicy`;
    `CatalogueStateException` (422 `invalid_state_transition`).
  - Eligibility: `AssignEligibility`/`RevokeEligibility`;
    `ServicePersonnelEligibilityPolicy`; `EligibilityConflictException` (409).
  - Clients: `CreateClient`/`UpdateClient`/`ChangeClientConsent`; `ClientPolicy`;
    `DuplicateClientException` (409 `duplicate_client`, returns the existing ULID).
  - Masked Resources (`ServiceResource`, `ServiceCategoryResource`,
    `ServicePersonnelEligibilityResource`, `ClientResource`, `ClientConsentResource`);
    `ResolvesWriteBranch` controller concern.
  - 12 typed audit events (`service(_category).created/updated/archived`,
    `personnel_eligibility.assigned/revoked`, `client.created/updated`,
    `client_consent.opted_in/out`) — context carries only safe ids + masked
    last-four, never full contact or the blind index.
- **Routes (`/api/v1`, 16 new):** category list/create/update; service
  list/show/create/update/archive; service eligibility list/assign/revoke (nested
  under `/services/{service}`); client list-search/show/create/update; client SMS
  consent. Every mutation is classified `branch_mutation` (Sanctum +
  ResolveTenantContext + EnsureBranchScope) and gated by `EnsurePermission`; reads
  authorize via policy. Two bodiless mutations (`services.archive`,
  `services.eligibility.destroy`) carry explicit `VALIDATION_EXEMPT` reasons.
- **Client search:** branch/tenant-scoped (BranchScope) by name OR normalized
  phone (HMAC blind-index exact match); paginated; masked-only; distinct
  `front_office.search` capability enforced; no cross-tenant existence leak.
- **Frontend (Phase 11 shell):** Branch Manager `ServiceCatalogue.vue`
  (list/filter, create/edit modal, archive confirmation, empty/loading/error/
  no-permission states); HR `ServiceEligibility.vue` (select service, assign/
  revoke); Front Office `ClientList.vue` (search, masked), `ClientCreate.vue`
  (duplicate-conflict link), `ClientDetail.vue` (masked detail, edit, SMS consent).
  `catalogueStore`/`clientStore` (Pinia). Navigation flips (`branch.services`,
  `hr.eligibility`, `front-office.clients` → `live` with routes), get-started
  deep links, §27.1 screen specs (5, generated), inventory.json/yaml regen.

## Controlling decisions recorded
1. **Eligibility ownership:** §80's eligibility-screen-with-Branch-Manager wording
   is overridden by §19.3 — **HR** owns `personnel.eligibility.manage`; Branch
   Manager gets a read-only eligibility view only. (Phase 11 deferral line
   corrected: eligibility schema + HR management → 15A; availability → 15B.)
2. **Preferred-personnel fee:** kept as an internal, non-editable legacy seam (not
   `$fillable`, no API field); `preferred_personnel_fee_rules` is Phase 20A — not
   created here.
3. **Billing-status mutation gate (material conflict):** Plan §22 billing-status
   enforcement (`merchants.billing_status`, the projection service, the gate
   middleware) is owned by the billing phases (20A–20E) and **does not exist at
   Phase 15A**. A billing-status gate cannot be built here without inventing that
   subsystem (an explicit deferral). Phase 15A mutations are classified
   `branch_mutation`, so they will inherit the billing-status gate automatically
   once it lands. This is the controlling, Plan-aligned position — not a gap in
   required 15A scope.

---

## Commands + results (all local; PostgreSQL 16 in the `app` container)

```
php artisan migrate:fresh                          5 new tables DONE; full rebuild OK
php artisan test (full, --parallel)                573 passed, 4 skipped, 0 failed (2591 assertions)
  ├ tests/Feature/Catalogue/ServiceCatalogueTest    9 passed
  ├ tests/Feature/Catalogue/ServiceEligibilityTest  6 passed
  ├ tests/Feature/Clients/ClientRecordsTest         8 passed
  ├ tests/Unit/ClientContactProtectionTest          7 passed
  ├ PermissionMatrixTest + 6 reconciled auth tests  green (canonical keys)
  ├ RouteSecurityContractTest / ForbiddenRouteAbsence green
  ├ TenantColumnCoverage / ModelTenancyTraitCoverage green
  ├ MigrationManifestTest                            green
  └ OpenApiContractTest                              green (regenerated, deterministic)
composer pint -- --test                            PASS (490 files)
composer stan (Larastan level 8)                   No errors (338 files)
composer api:openapi (×2)                           byte-identical (deterministic), 63 routes
npm run api:types + check-api-contract             OK — 51 paths, 63 operations
npm run typecheck (vue-tsc)                         clean
npm run lint (eslint)                              0 errors
npx vitest run                                     142 passed (30 files) incl. 9 new 15A specs
npm run build (production SPA)                      built OK
npx playwright test catalogue-clients (chromium)   5 passed (47.5s): BM catalogue, HR eligibility,
                                                   FO masked clients + duplicate conflict + 360px
                                                   no-overflow + axe (0 serious/critical)
composer audit                                     no advisories
npm audit --audit-level=high                       0 high/critical (2 moderate only)
gitleaks detect                                    no leaks (34 commits, 9.27 MB)
```

**Docker:** the dev application image (`servana-app:dev`) builds and runs (the
stack served every backend test above). The production application image and the
frontend/nginx production image build on **Linux CI** (authoritative Docker gate),
consistent with Phases 10/10F/11.

## Authorization & isolation evidence (from the feature suites)
- Branch Manager creates/updates/lists/archives services; Front Office, HR, and
  Merchant Admin are each **403 `permission_denied`** on catalogue mutation; HR is
  403 on service-price mutation.
- HR assigns/revokes eligibility in-branch; Branch Manager is 403; a service or
  personnel in another branch **404s** before any check (BranchScope).
- Front Office creates/searches/views/updates clients; cross-tenant service/client
  **404** (no existence leak); cross-branch follows the established 403 posture.
- `service.create` is not grantable to Finance (override rejected 403).

## Contact-protection & search evidence
- A same-branch active duplicate phone is rejected with a deterministic **409
  `duplicate_client`** (existing ULID in `meta.client_id`); the same phone is
  accepted in a different branch.
- The DB stores ciphertext (not plaintext); `phone_index` is a 64-char HMAC; the
  blind index and full phone/email never appear in API resources, `toArray()`,
  logs, or audit payloads (asserted).
- Search by name and by normalized phone is branch- and tenant-isolated and
  returns masked contact only; Personnel has no client access and **no
  contact-export route exists** (`/clients/export` and `/clients/{id}/export`
  → 404).
- SMS consent opt-in/opt-out persists a single current state per (client, sms) and
  is audited; no SMS is sent (Phase 21S).

## Remaining known limitations (required Phase 15A scope)
None. Every Phase 15A acceptance criterion is met and verified locally. The
billing-status mutation gate is owned by Plan §22 / Phases 20A–20E and is out of
required 15A scope (see decision 3). REM-PERM-001 full closure is owned by
Phase 19. CI/PR/merge are pending (no PR opened, per instruction).

## Solo-Maintainer Review Exception - PR #24

An independent second reviewer was unavailable because the repository currently
has one eligible maintainer. The product owner authorized a PR-specific
governance exception instead of fabricating approval.

Evidence:

- PR: #24
- verified implementation head:
  23aeed1f464d9b3efb412eaf98f9b1ea239276f1
- CI/Backend: passed
- CI/Frontend: passed
- CI/Docker: passed
- CI/Security: passed
- CI/E2E - Playwright: passed
- GitHub reviewDecision: intentionally blank
- governance record:
  docs/governance/solo-maintainer-review-exception-pr-24.md

This exception applies only to PR #24 and is not independent reviewer approval.
