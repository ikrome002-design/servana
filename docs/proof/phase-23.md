# Phase 23 — Security Hardening, Responsive/Dark/Accessibility Release Audit, Threat-Model Verification, and Requirement Traceability Enforcement

> **Lifecycle status: `in_progress`.**
> Branch `phase-23-release-hardening-audit`, based on `d010ec50f412dfe97ee1c412362e16bf263c2a4d`
> (the verified Phase 22 squash-merge). Plan authority: §80 Phase 23 entry, §9/§9.1, §19, §23–§24,
> §27, §64, §65, §68, §70, §73, §74, §75, §76, §85.

---

## 1. Predecessor verification (Gate A) — Phase 22 / PR #47

Every value below was re-verified **live** against Git and GitHub before any file changed.

| Item | Verified value |
|---|---|
| `git fsck --full` | exit 0 (4 dangling objects only; no corruption) |
| Branch at preflight | `main` |
| HEAD / `origin/main` / merge-base | `d010ec50f412dfe97ee1c412362e16bf263c2a4d` |
| Divergence `origin/main...HEAD` | `0 0`; working tree, index, untracked all clean |
| PR | [#47 — Phase 22: Implement scoped search](https://github.com/ikrome002-design/servana/pull/47) — `MERGED`, not draft, base `main` |
| Final PR head | `8dbb2740c9603a75392a32139270f518eb789839` |
| Squash-merge commit | `d010ec50f412dfe97ee1c412362e16bf263c2a4d` |
| Squash-merge parent (single ⇒ squash) | `1e1b0fd3c9ed76a50e9d47adf1cea0c0222c1408` (the REM-DEP-002 merge) |
| Merge subject | `Phase 22: Implement scoped search (#47)` |
| Merged at / by | `2026-07-26T20:39:50Z` / `ikrome002-design` |
| **Final successful CI run** | **`30218560304`** — <https://github.com/ikrome002-design/servana/actions/runs/30218560304> |
| Backend — Pint, Larastan, Pest | SUCCESS |
| Frontend — ESLint, vue-tsc, Vitest, build | SUCCESS |
| Docker — build images | SUCCESS |
| Security — gitleaks | SUCCESS |
| E2E — Playwright | SUCCESS |
| Governance comment | id `5085264996`, present exactly once — <https://github.com/ikrome002-design/servana/pull/47#issuecomment-5085264996> |
| `reviewDecision` | blank — PR-specific solo-maintainer exception. **Not** independent reviewer approval. |
| Submitted reviews | `0` |
| Branch cleanup | `phase-22-search` absent locally, in remote-tracking, and on `origin` |

**REM-DEP-002** — PR [#46](https://github.com/ikrome002-design/servana/pull/46) `MERGED`, merge commit
`1e1b0fd3c9ed76a50e9d47adf1cea0c0222c1408`, head `b97340802ff8d142f0f7b0d8c0d7e4e65f28ea3d`,
register status `verified_complete`. Live re-verification during Phase 23:
`npm audit` → **0 vulnerabilities** (0 info/low/moderate/high/critical across 410 dependencies).

---

## 2. External Gate W (Gate B)

| Evidence path | Result |
|---|---|
| `docs/integrations/wallet/gate-w-evidence.md` | **absent** |
| `docs/proof/phase-20d-w.md` | **absent** |
| `docs/integrations/` | contains `refer-earn/` only — **no `wallet/` directory exists** |

**Gate W = CLOSED**, unambiguously. Consequences, recorded truthfully and **not** worked around:

- **Phase 20D-W — blocked.** §80.2 gate evidence was never produced.
- **Phase 21R-B — blocked.** §80.1 requires 20D-W.
- **Phase 21N — blocked.** §80.1 `(17,18,20D-W) → 21N`.

The live §80.1 chain `… → 22 → 23 → 24 → 25` makes **Phase 23 the next executable phase**, and it is
executable with Gate W closed because Phase 23 does not depend on 20D-W. No Wallet or R&E
platform-owned capability is implemented, stubbed, or simulated by this phase.

---

## 3. Baseline inventory (captured before any corrective edit)

### 3.1 Routes

`php artisan route:list --json` → `storage/app/phase-23-routes.json`.

| Metric | Baseline | After §4 |
|---|---|---|
| Total routes | 294 | 295 |
| `api/v1` routes | 287 | 288 |
| `api/v1` GET/HEAD-only | 117 | 118 |
| `api/v1` with mutating verbs | 170 | 170 |
| Non-API routes | 7 (`/`, `health`, `health/deep`, `sanctum/csrf-cookie`, `storage/{path}` GET+PUT, `up`) | unchanged |

### 3.2 Screens

`docs/frontend/screens/inventory.json` — **124 entries**: 98 `implemented` + 18 `phase_11`
foundation = **116 live**, **8 `planned`**.

- §27.1 spec coverage: **116 / 116** live screens have a spec file on disk. **0 missing.**
- 117 spec files exist; **1 orphan**: `docs/frontend/screens/finance/finance-dashboard.md` has no
  inventory entry (recorded; disposition pending in the screen-inventory increment).
- 2 live entries intentionally have no route (`unsupported-role`, `no-branch-assignment` — access-state
  boundaries).

Planned-screen ownership (verified live, not assumed — the Phase 22 expectation of eight is still exact):

| Screen | Owning phase | Owner status | Disposition |
|---|---|---|---|
| `platform-wallet-config` | 20D-W | blocked (Gate W closed) | **Blocked** — truthful absence, not fabricated |
| `platform-billing-reconciliation` | 20D-W | blocked (Gate W closed) | **Blocked** — truthful absence, not fabricated |
| `merchant-reports` | 21N | blocked (needs 20D-W) | **Blocked / deferred** |
| `branch-reports` | 21N | blocked (needs 20D-W) | **Blocked / deferred** |
| `merchant-profile` | 15A | **verified_complete** | **Proven release gap** — classification pending |
| `branch-calendar` | 16A | **verified_complete** | **Proven release gap** — classification pending |
| `platform-audit-reports` | 19 | **verified_complete** | **Proven release gap** — classification pending |
| `hr-eligibility` | 15B | **verified_complete** | **Likely duplicate** of the implemented `service-eligibility` (same route name `hr.eligibility`) — narrow inventory wiring defect |

### 3.3 Tests

344 backend test files (326 Feature, 18 Unit) · 98 Vitest specs · 33 Playwright specs.

Guards already present: `RouteSecurityContractTest`, `FileRouteSecurityContractTest`,
`FinancialRouteIdempotencyCoverageTest`, `AuditMutationCoverageTest`, `AuditSeverityCoverageTest`,
5 × `PermissionMatrix*`, `PermissionDatabaseProjectionTest`, `PermissionRoleBoundaryTest`,
`PermissionMfaCoverageTest`, `PermissionStepUpCoverageTest`, `NoDirectProviderIntegrationTest`,
`ForbiddenRouteAbsenceTest`, `TenantColumnCoverageTest`, `ModelTenancyTraitCoverageTest`,
`TenancyStaticAnalysisTest`, `SessionRevocationTest`, 6 × MagicLink, `FileUploadValidationTest`,
13 × Search, 4 × ReferEarn, 9 × AuditExport, `FinanceExportTest`,
`SmsContactExportProhibitionTest`, `ClientContactProtectionTest`, `OpenApiContractTest`,
`OpenApiTypeParityTest`.

**Absent and owed by Phase 23:** traceability guard · threat-model verification suite ·
**protected-read authorization coverage guard** · screen-inventory/spec guard.

### 3.4 Traceability

`docs/traceability/servana-requirements.csv` — **53 rows**, all 15 §85 columns present.

| Status value | Rows |
|---|---|
| `verified_complete` | 24 |
| `implemented` | 18 |
| `local_complete` | 3 |
| `architecture_adopted` | 2 |
| `local_complete pending PR CI/review/merge` | 2 |
| **`not_implemented`** | **1** (`SRV-AUDIT-004`) |
| `partially_implemented` | 1 |
| **narrative prose in the status cell** | **2** (`SRV-PAYMENT-001`, `SRV-PAYMENT-002`) |

Stale statuses confirmed against merged owner phases: `SRV-PERM-002`, `SRV-AUDIT-005`,
`SRV-SEARCH-001` (`local_complete` but owners merged), `SRV-COMPENSATION-001/002` (20F/20G merged),
`SRV-AUDIT-003` (`partially_implemented`). No rows model the blocked 20D-W / 21R-B / 21N
requirements beyond two `architecture_adopted` rows.

**REM-TRACE-001** = `in_progress`, gated at Phase 23; CSV maintained, CI enforcement never wired.

---

## 4. Defect PH23-SEC-001 — `GET /api/v1/staff` had no authorization boundary

### Observed problem

`GET /api/v1/staff` (route name `staff.index`) enforced **no authorization of any kind** beyond
tenant/branch scoping. Any authenticated, active merchant member — **including Front Office,
Personnel, and the read-only Audit role** — could enumerate the full staff roster of their branch
**together with unmasked personnel phone numbers**.

### Evidence

1. `route:list` — the `staff.index` middleware stack ends at `EnsureMerchantActive`; there is **no**
   `EnsurePermission`:
   `api | ThrottleRequests:api | Authenticate:sanctum | EnforceIdleTimeout | EnsureActivePrincipal | EnsurePrivilegedMfa | ResolveTenantContext | EnsureMerchantActive`
2. `StaffController::index()` made **no** `authorize()` call — unlike `show`/`suspend`/`activate`/
   `deactivate`, which all routed through `authorizeManages()`.
3. `StaffIndexRequest::authorize()` returned `true` with the comment
   *"route middleware + StaffProfilePolicy are the authorization boundary"* — **neither ran**.
4. `StaffProfilePolicy` had **no `viewAny` method at all**, and its `view()` merely delegated to
   `manage()`.
5. Direct comparators on the identical middleware stack **do** authorize in-controller:
   `ClientController::index` → `$this->authorize('viewAny', Client::class)`;
   `AppointmentController::index` → the same. `staff.index` was the outlier.
6. `StaffProfileResource` returns `phone` **unmasked**, plus `first_name`, `last_name`, `role`,
   `status`, `employment_type`, `employment_status`, `primary_branch_id`.
7. `staff_profiles.phone` is a plain, unencrypted `string` column.
8. The canonical key existed but was inert: `docs/auth/permission-matrix.yaml` carried
   `staff.view` with `implementation_status: planned` and `owning_phase: Phase 20F` — **while
   Phase 20F is `verified_complete`**.

Plan §19.3:1481 defines the key unambiguously — `staff.view  B|-|A|n/a|-|-|info|-` — under the
heading `# HR and Staff (default_roles: hr)` (Plan:1480).

### Affected files / routes / screens / tables

- Routes: `staff.index` (`GET /api/v1/staff`), `staff.show` (`GET /api/v1/staff/{staff}`)
- `app/Http/Controllers/Api/V1/Hr/StaffController.php`
- `app/Policies/StaffProfilePolicy.php`
- `app/Http/Resources/StaffProfileResource.php` (the payload that leaked)
- `app/Domain/Auth/Services/PermissionRegistry.php`, `docs/auth/permission-matrix.yaml`
- `app/Domain/Search/Definitions/StaffSearchDefinition.php` (anchored on the defect)
- Screens: `hr-staff`, `hr-staff-profile` (HR, correct) and `personnel-schedule` (Branch Manager)
- Table: `staff_profiles` (`phone`)

### Root cause

Phase 20F completed without performing the permission activation it owned. `staff.view` was left
`implementation_status: planned`, so it was absent from the PHP registry, the DB projection and the
generated TypeScript set, and therefore **could not** be referenced by `EnsurePermission`. The
roster route was shipped relying on a policy that was never invoked, and the misleading comments in
`routes/api.php` (*"Authority is StaffProfilePolicy"*) and `StaffIndexRequest` concealed it.

### Why this is the root cause

The route, the Form Request and the policy each deferred authorization to one of the others, and no
layer actually performed it. Activating the key alone would not have fixed the list route (it had no
middleware and no policy call); adding a policy call alone would have failed (`viewAny` did not
exist, and `view` resolved to the *mutation* authority). Both layers had to be wrong simultaneously
to produce the leak, and both trace to the skipped 20F activation. Removing either — an active key
with an enforced `viewAny` — closes it.

### Correct fix (product-owner decision: **narrow options endpoint**)

The product owner selected the Phase 20G `commission-rule-service-options` precedent: gate the HR
roster with its canonical key, and serve the Branch Manager's shipped picker from a separate narrow
endpoint under the authority it already holds. **No role grant was widened; no permission key was
invented.**

1. **Activate `staff.view`** across YAML → PHP registry → DB projection → generated TypeScript,
   with `owning_phase: null` (the schema requires an active canonical key to carry none;
   the historical owner is preserved here and in the CHANGELOG, not in the matrix row), and
   `audit_event: none` (route-derived: a pure read emits no audited mutation).
   Granted to **`hr` only**, exactly as Plan §19.3 specifies.
2. **Separate READ from MANAGE in `StaffProfilePolicy`**: new `viewAny(User)` → `staff.view`;
   `view(User, StaffProfile)` → `staff.view` + same merchant + accessible branch. `manage()` is
   **unchanged** (`branches.manage_users_lifecycle` or `staff.suspend` in branch scope).
3. **Enforce in the controller**: `index()` now calls `authorize('viewAny', StaffProfile::class)`;
   `show()` authorizes `view`. The private helper was renamed `authorizeManages` → `authorizeScoped`
   because it now serves both a read and the mutations.
4. **New narrow endpoint** `GET /api/v1/branch/personnel-options`
   (`branch.personnel-options.index`), inside the existing `EnsureBranchScope` group, gated by
   `EnsurePermission:branch.dashboard.view` — the same key `PersonnelAvailabilityPolicy::view`
   already accepts, so the option list is exactly the set whose availability the caller may then
   read. Returns **only** `{id, display_name}` via a dedicated `BranchPersonnelOptionResource`
   (never `StaffProfileResource`). Roster semantics were **not** invented: the shipped Phase 15B
   screen applied no status filter, so none was added — the response simply stops carrying the
   status/role/branch metadata it never needed.
5. **Frontend migration**: `PersonnelSchedule.vue` reads the new endpoint, is typed by the new
   `BranchPersonnelOption` interface, and clears options **and** the selection when branch context
   changes so one branch's personnel can never remain selectable in another.
6. **Search anchor corrected** (see §4.1).

`id` (not `ulid`) is the field name because `id` is the public identifier the staff contract already
exposes and the schedule workflow already passes to `GET /api/v1/staff/{staff}/availability`; a
second alias for the same value was explicitly rejected.

### 4.1 Regression found and fixed during the change — staff search

Enforcing the fix broke `SearchTenantIsolationTest > it lets a merchant-wide role reach every branch
of its own merchant but no other merchant`. **This was a genuine regression I introduced, verified by
`git stash`: the test passed on the pristine branch base and failed with the change applied.**

- **Root cause.** `AbstractSearchDocumentDefinition::passesRecheck()` calls
  `Gate::allows('view', $model)`. Before Phase 23, `StaffProfilePolicy::view` delegated to `manage`,
  so a Merchant Admin (holding the legacy `branches.manage_users_lifecycle`) passed. After the fix,
  `view` requires `staff.view`, which the Merchant Admin does not hold — so every staff result was
  filtered out while `StaffSearchDefinition::canSearch()` still admitted the caller. The gate and
  the per-record re-check had drifted apart.
- **Correct fix, not a test weakening.** `canSearch()` now returns `$context->can('staff.view')` —
  the authority that genuinely governs this type's own list and detail routes, which is exactly what
  the search-catalogue Rule 2 requires. This **tightens** the type: a Merchant Admin can no longer
  open `hr.staff-profile` (the `routeName` every staff result links to), so it must not receive
  staff results — **search must never be a wider surface than the page it points at**.
- **Test repair.** The branch-expansion test's *probe* changed from `staff` to `receipt` (the
  Merchant Admin's default `receipt.view`); the property under test — branch expansion for a
  merchant-wide membership with a matching row on the far side of the tenant boundary — is
  unchanged and still proven end to end. A **new** case was added asserting the Merchant Admin now
  receives **no** staff results *and* is denied on `GET /api/v1/staff/{staff}`, with HR still
  finding the same row (so the boundary is proven while the row is genuinely findable).

Two further test corrections were required, and both were **my own expectations being wrong**, not
product defects:

- Same-tenant out-of-branch staff detail returns **403**, not 404. Plan §9 rule 2 documents the 403
  posture for same-tenant out-of-branch (contrast the foreign-tenant 404), and this matches the
  pre-Phase-23 posture of `manage`. The test now asserts 403.
- Pest's `toContain` treats extra arguments as further expected **values**, not as a message. The
  generated-TypeScript assertion was rewritten as a boolean with the message on `toBeTrue`.

### 4.2 Hand-maintained count snapshots updated (not weakened)

Activating one key moves the absolute active/planned split, breaking snapshots owned by earlier
phases. The established **Phase 20H precedent** was applied: a superseded phase's test stops
asserting absolute counts and instead asserts *what that phase owns* plus the invariant that the
canonical catalogue never grows.

- `Phase21SPermissionActivationTest` — now asserts its own two keys are still active and
  `active + planned == 168`.
- `Phase22SearchGateTest` — now asserts `active + planned == 168`; the "Phase 22 owns no key" claim
  is proven exhaustively by the neighbouring case, unchanged.
- `PermissionPlannedKeyIsolationTest` — planned count `38 → 37`, with the reason recorded in the
  running comment.
- `PermissionMatrixTest::expectedMatrix()` — `staff.view` added to the hand-maintained `hr` list.

Counts: **active 130 → 131, planned 38 → 37, catalogue 168 unchanged.**

### Files changed

**Backend**
- `app/Domain/Auth/Services/PermissionRegistry.php` — `staff.view` catalogue entry + `hr` default grant
- `app/Policies/StaffProfilePolicy.php` — new `viewAny`; `view` separated from `manage`
- `app/Http/Controllers/Api/V1/Hr/StaffController.php` — `viewAny`/`view` enforcement; helper renamed
- `app/Http/Controllers/Api/V1/Branches/BranchPersonnelOptionController.php` — **new**
- `app/Http/Resources/BranchPersonnelOptionResource.php` — **new**
- `app/Domain/Search/Definitions/StaffSearchDefinition.php` — anchor moved to `staff.view`
- `app/Domain/Hr/Models/StaffProfile.php` — stale "Phase 23 upload seam" note corrected
- `routes/api.php` — `branch.personnel-options.index` + import

**Contracts / docs (generated where a generator owns them)**
- `docs/auth/permission-matrix.yaml` · `resources/spa/src/types/generated/permissions.ts`
- `docs/api/openapi.json` · `resources/spa/src/types/generated/api.ts`
- `docs/architecture/search/search-catalogue.md`

**Frontend**
- `resources/spa/src/pages/branch/PersonnelSchedule.vue` · `resources/spa/src/types/models.ts`

**Tests**
- **new** `tests/Feature/Hr/StaffReadAuthorizationTest.php`
- **new** `tests/Feature/Branches/BranchPersonnelOptionsTest.php`
- **new** `tests/Feature/Auth/Phase23PermissionActivationTest.php`
- `tests/Feature/Search/SearchTenantIsolationTest.php` · `tests/Feature/Auth/PermissionMatrixTest.php`
- `tests/Feature/Auth/PermissionPlannedKeyIsolationTest.php`
- `tests/Feature/Auth/Phase21SPermissionActivationTest.php` · `tests/Feature/Auth/Phase22SearchGateTest.php`
- `resources/spa/src/pages/branch/PersonnelSchedule.spec.ts` · `tests/e2e/personnel-availability.spec.ts`

### Tests added or updated

`StaffReadAuthorizationTest` proves: unauthenticated → 401; HR reads roster + detail in its own
branch; HR cannot enumerate another merchant (foreign tenant → **404**, no existence leak); HR cannot
read out-of-branch (→ **403**, documented authority denial); **Branch Manager, Front Office,
Personnel, Audit and Finance are each denied on BOTH index and detail**; a denial body contains
neither the roster nor any contact value; revoking `staff.suspend` leaves the read working while all
three lifecycle mutations fail (read ≠ manage); a revoke override on `staff.view` works and a grant
override is a **no-op** (`revocable_only`, Plan §19.4).

`BranchPersonnelOptionsTest` proves: unauthenticated → 401; Branch Manager gets its branch's
personnel in deterministic `display_name` order; **every row has exactly the keys `['id',
'display_name']`**; the payload contains no phone, no internal numeric PK, no branch ULID, and none
of the strings `phone` / `employment_status` / `primary_branch_id` / `profile_photo`; other-branch
and other-merchant personnel never appear; Front Office, Personnel, Audit, Finance **and HR** are
denied; **neither key grants the other endpoint** (HR: roster yes / options no — Branch Manager:
options yes / roster no); caller-supplied `merchant_id`/`branch_id`/`role`/`staff_profile_id`
filters cannot widen the server scope.

`Phase23PermissionActivationTest` proves the four-way parity of the single activation, the
131/37/168 counts, the HR-only grant across defaults **and** overrides for every role, the full
Plan §19.3 attribute set, that the read key is non-mutating and did not replace a management key,
and that no options-style permission was invented and the Branch Manager grant was not widened.

Frontend: `PersonnelSchedule.spec.ts` proves the page calls `/branch/personnel-options` and **never**
`/staff`, clears stale options and the selection on branch change, and renders no phone number.
E2E `personnel-availability.spec.ts` records every request and asserts
`/api/v1/branch/personnel-options` was called and `/api/v1/staff` was **not**.

### Test commands

```bash
docker compose exec -T app php artisan test tests/Feature/Hr/StaffReadAuthorizationTest.php tests/Feature/Branches/BranchPersonnelOptionsTest.php tests/Feature/Auth/Phase23PermissionActivationTest.php
docker compose exec -T app php artisan test tests/Feature/Search/
docker compose exec -T app php artisan test --group=permissions,auth,phase23,phase22,isolation,tenancy,hr,staff,branches,search,matrix,security
npx vitest run resources/spa/src/pages/branch/PersonnelSchedule.spec.ts
```

### Test results

| Command | Result |
|---|---|
| New Phase 23 suites (3 files) | **29 passed**, 0 failed (after the two self-inflicted expectation fixes in §4.1) |
| `tests/Feature/Search/` (13 files) | **173 passed**, 0 failed |
| Affected groups (12 groups) | **616 passed, 4 skipped, 0 failed** (6 486 assertions, 475 s) |
| `PersonnelSchedule.spec.ts` | **6 passed** |
| Vitest (full) | **522 passed / 98 files** |
| ESLint | **0 errors, 138 warnings** — exactly the documented baseline; **no new warning** |
| `vue-tsc --noEmit` | clean |
| Pint | **PASS, 1 660 files** |
| Larastan level 8 | **[OK] No errors** |
| `servana:permission-types --check` | up to date |
| `npm run api:contract:check` | OK — **244 paths, 290 operations** |
| `npm audit` | **0 vulnerabilities** |

Failure/rerun evidence, preserved deliberately:

- `SearchTenantIsolationTest` failed with the change and **passed on the pristine base under
  `git stash`** — this is what established it as a real regression rather than a flake, and drove
  the §4.1 root-cause fix.
- The first run of the new suites failed twice on my own expectations (403-vs-404 and Pest
  `toContain` arity). Both were corrected in the **test**, because the product behaviour was already
  correct and documented; no assertion was weakened and no role was widened.
- One Pint failure (`line_ending`) was introduced by a scripted edit writing CRLF on Windows and was
  auto-fixed by `pint`; re-run is clean.

### Proof of resolution

- `staff.index` and `staff.show` both have an explicit server-side authorization boundary; the
  five previously-capable roles are denied on both, proven per role.
- A Merchant Admin can no longer read a staff profile **and** no longer receives staff search
  results — the two surfaces are now consistent.
- The Branch Manager's shipped workflow still works, and its data exposure **shrank** from the full
  `StaffProfileResource` (including `phone`) to `{id, display_name}`.
- No permission key was created; `staff.view` remains HR-only; `branch.dashboard.view` was not
  widened; catalogue size unchanged at 168.

### Remaining risk

1. **Merchant Admin can still `manage` a staff profile it cannot `view`.** This asymmetry is
   **pre-existing** and untouched: it flows from the *legacy* `branches.manage_users_lifecycle`
   grant, whose canonical §19.2 successors are `merchant.user.suspend` / `merchant.user.deactivate`.
   Reconciling that legacy key is a permission-matrix change requiring product-owner authority and is
   **not** Phase 23 scope. Recorded here and in the remediation register.
2. `staff_profiles.phone` remains a plaintext column. Phase 23 removed the unauthorized *read path*;
   at-rest encryption of this column is a §74 privacy concern with no active Phase 23 requirement.
3. The `exports.staff_roster` permission key is granted to HR by default. No route currently
   consumes it; it is recorded for the forbidden-capability increment.

---

## 5. Defect PH23-DET-001 — the compensation suite depended on the wall clock (**pre-existing**)

### Observed problem

The full backend serial suite reported **27 failed / 7 skipped / 2 232 passed**. Twenty-two failures
were in `tests/Feature/Compensation/`, all reducing to one message:

> A backdated compensation change requires an impact preview before approval.

### Evidence

- Reproduced in isolation: `tests/Feature/Compensation/` → **22 failed / 442 passed**.
- **Reproduced identically on the pristine branch base under `git stash`** — *22 failed / 442
  passed*. The defect is **pre-existing on `main`**, not introduced by Phase 23.
- The divergence, proved directly in the container at the time of the run:

  | Probe | Value |
  |---|---|
  | `config('app.timezone')` | `UTC` |
  | `CarbonImmutable::now('UTC')` | `2026-07-26 23:37:59` |
  | `CarbonImmutable::now('Africa/Nairobi')` | `2026-07-27 02:37:59` |
  | Laravel `today()` | `2026-07-26` |
  | `CompensationBusinessDate::today()` | **`2026-07-27`** |
  | `CompensationBusinessDate::isBackdated(today())` | **`true`** |

- Phase 22's CI passed on 2026-07-26; the suite was run here after 21:00 UTC on the same day.

### Affected files / routes / screens / tables

`tests/Feature/Compensation/*.php` (87 `today()` call sites across 7 files) — fixtures only.
No route, screen or table is affected.

### Root cause

CLAUDE.md §1 and Plan §59 require timestamps in UTC but **business-day logic in `Africa/Nairobi`**.
`app.timezone` is `UTC`, so Laravel's global `today()` helper is three hours behind the business day.
Between **21:00 and 23:59 UTC** the UTC calendar date is still yesterday while Nairobi has already
rolled over. Every fixture that wrote `'effective_from' => today()->toDateString()` therefore created
a plan the domain evaluated as **yesterday** — `is_backdated` was computed `true` at submission, and
`ApproveCompensationPlan` correctly failed closed demanding an impact preview (F8).

### Why this is the root cause

The production code is **correct**, and this was verified rather than assumed: every business-date
decision in `app/Domain/Compensation/` routes through `CompensationBusinessDate` (Nairobi) —
`ActivateScheduledCompensationPlan`, `ApproveCompensationPlan`, `BuildCompensationPlanImpactPreview`,
`ExpireCompensationPlan`, `ResolveEffectiveCommissionRule`, `ResolveEffectiveCompensationPlan`. The
only bare `now()` calls are UTC **timestamps** (`approved_at`, `submitted_at`, `created_at`), which is
exactly what CLAUDE.md §1 prescribes. The SPA was checked too: `Compensation.vue` defaults
`effective_from` to `''` and requires the user to pick a date, so **no product surface** computes a
business date from a UTC clock. The divergence existed **only** in the test fixtures, and the failure
window matches the three-hour UTC/Nairobi offset exactly.

### Correct fix

A new `businessToday()` Pest helper returns
`CarbonImmutable::now(CompensationBusinessDate::TIMEZONE)->startOfDay()` — the same authority the
domain uses. All **87** `today()` call sites in `tests/Feature/Compensation/` were switched to it;
every one of them feeds a business-date field (`effective_from`, `effective_to`, or a resolver date
argument), and none is a timestamp, so the replacement is exact rather than blanket. No sleep, no
retry, no `setTestNow` smeared across the suite, and **no assertion weakened**.

### Files changed

`tests/Pest.php` (the `businessToday()` helper + import) ·
`tests/Feature/Compensation/{CommissionRuleApiTest, CommissionRuleSelectedServicesTest,
CompensationPlanActionTest, CompensationPlanApiTest, CompensationResolverTest,
CompensationScopeIsolationTest, Phase20FSchemaTest}.php` ·
**new** `tests/Feature/Compensation/CompensationBusinessDateDeterminismTest.php`.

### Tests added or updated

A permanent regression guard that **pins the clock inside the failing window** (22:30 UTC on
2026-07-26 = 01:30 on 2026-07-27 in Nairobi), so it holds year-round instead of depending on when
the suite happens to run:

- reproduces the divergence explicitly (`today()` = `2026-07-26`, business date = `2026-07-27`,
  `isBackdated(today())` **true**, `isBackdated(businessToday())` **false**);
- proves `businessToday()` is a no-op outside the window (midday UTC);
- **end-to-end** (in `CompensationPlanApiTest.php`, beside the plan-API helpers): create → submit →
  approve at the divergent instant returns **200**;
- proves the fix did **not** weaken the F8 control: a genuinely backdated plan still returns **422**
  without its impact preview at that same instant.

### Test commands

```bash
docker compose exec -T app php artisan test tests/Feature/Compensation/
docker compose exec -T app php artisan test tests/Feature/Compensation/CompensationPlanApiTest.php --filter="divergent window"
```

### Test results

`tests/Feature/Compensation/` → **468 passed, 0 failed** (1 649 assertions), including both
frozen-clock cases. Pint **PASS (1 661 files)**; Larastan level 8 **[OK] No errors**.

**Full backend serial suite, after both PH23-DET-001 and PH23-TEST-001 were fixed:**

| Run | Result |
|---|---|
| Before (this session, after the §4 change) | **27 failed**, 7 skipped, 2 232 passed (13 520 assertions) |
| After | **0 failed**, 7 skipped, **2 263 passed** (13 602 assertions) |

The delta reconciles exactly: 2 232 + 27 = 2 259 previously-executing cases, + 4 new determinism
cases = 2 263.

**Serial and parallel agree exactly**, which is itself determinism evidence:

| Mode | Result | Duration |
|---|---|---|
| `php artisan test` (serial) | **2 263 passed, 7 skipped, 0 failed** (13 602 assertions) | — |
| `php artisan test --parallel` (4 processes) | **2 263 passed, 7 skipped, 0 failed** (13 602 assertions) | 480 s |

### Proof of resolution

The guard was verified **non-vacuous**: reverting the end-to-end case's fixture to the UTC `today()`
made it fail with `Expected response status code [200] but received 422`; restoring `businessToday()`
made it pass. The test therefore genuinely detects the defect rather than merely passing.

### Remaining risk

`today()`/`now()` is used in other suites (`tests/Feature/Billing/`, and others). None of them failed
in this run, so no further wall-clock dependence is *proven* — but the same latent pattern may exist
wherever a UTC helper feeds a Nairobi business-date comparison. Increment 9 (E2E determinism) will
sweep the remaining suites; a repository-wide guard is a candidate deliverable there.

---

## 6. Defect PH23-TEST-001 — global constant collision (**introduced and fixed in this session**)

**Observed problem.** The remaining 5 of the 27 failures were in
`tests/Feature/Compensation/CommissionRuleServiceOptionsTest`, returning **403 instead of 200**.

**Evidence.** They did not fail when that directory ran alone, only in the full suite; the assertion
was a Phase 20G HR request receiving 403.

**Root cause.** My new `tests/Feature/Branches/BranchPersonnelOptionsTest.php` declared
`const OPTIONS_URL = '/api/v1/branch/personnel-options';`. A Pest file-scope `const` is a **global**
constant, and `CommissionRuleServiceOptionsTest.php` already declared `OPTIONS_URL` for
`/api/v1/commission-rule-service-options`. `tests/Feature/Branches/` loads first, so the Phase 20G
suite silently sent its requests to my endpoint — which HR is correctly forbidden from (it holds
`compensation.plan.view`, not `branch.dashboard.view`). The 403 was the new authorization working
correctly on the wrong URL.

**Correct fix.** Renamed to `BRANCH_PERSONNEL_OPTIONS_URL`, with a comment recording why a generic
name is unsafe here. An audit of all PHP file-scope constants in `tests/` confirmed
`OPTIONS_URL` was the **only** duplicate, and it is now unique.

**Proof of resolution.** `tests/Feature/Compensation/ tests/Feature/Branches/` → **500 passed,
0 failed** (before the determinism guards were added).

---

## 7. Increment 2 — whole-product threat-model verification (Plan §9.1, §73)

**Delivered.** The matrix is **machine-checked**, not prose: the authoritative data lives in
`P23_THREAT_MATRIX` inside
[`tests/Feature/Security/Phase23ThreatModelCoverageTest.php`](../../tests/Feature/Security/Phase23ThreatModelCoverageTest.php),
and [`docs/security/phase-23-threat-model-verification.md`](../security/phase-23-threat-model-verification.md)
is its human-readable rendering. The guard fails if a scenario loses evidence, if a referenced
suite is renamed or deleted, if a status leaves the closed vocabulary, or if a scenario id
disappears from the published document.

**All 40 required scenarios (TM-01 … TM-40) carry a definite disposition:**

| Status | Count | Scenarios |
|---|---|---|
| `automated` | 36 | TM-01…TM-17, TM-19…TM-28, TM-30…TM-38 |
| `absence_proof` | 2 | TM-18 (export-shaped routes), TM-29 (SSRF) |
| `blocked_external_gate` | 2 | TM-39 (R&E inbound reconciliation → 21R-B), TM-40 (Wallet webhook → 20D-W) |
| `not_applicable` | 0 | — |

Vague dispositions ("covered", "looks safe") are rejected by the closed vocabulary.

**Three absence proofs are executed directly**, because absence has no natural home suite:

1. **TM-29 SSRF** — verified there is *no user-controlled outbound fetch anywhere in `app/`*. The
   single HTTP client, `HttpReferEarnClient`, targets `config('refer-earn.base_url')`; the guard
   walks every PHP file under `app/` and fails if an outbound call or remote stream is ever built
   from a request-derived value.
2. **TM-40 Wallet webhook forgery/replay** — asserts `docs/integrations/wallet/` does **not** exist
   and that no `*/mpesa/*`, `wallet/webhook`, `stk-callback` or `c2b/` route exists. **No route was
   created in order to test it** — doing so would implement a Wallet-owned capability inside Servana
   (Plan §9 rule 20, §2.2). If Gate W opens, this guard fails and forces re-evaluation rather than
   going stale.
3. **TM-39 R&E inbound reconciliation** — asserts no inbound partner-**write** route exists. Phase
   21R-A delivered the outbound outbox only; the inbound endpoint is Phase 21R-B.

**Defect found in my own guard, fixed:** the first run flagged
`POST api/v1/testing/step-up/reconciliation_resolution`. That is the Phase R3 **test-only** security
harness, never registered outside the `testing` environment, so the finding was a false positive from
an over-broad pattern. The guard now excludes `api/v1/testing/*` and matches inbound partner-write
routes only. Also corrected: `toHaveKey($id, $message)` — Pest treats the second argument as an
expected **value**, not a message (the same arity trap as `toContain`).

**Also delivered:** [`docs/security/phase-23-penetration-test-checklist.md`](../security/phase-23-penetration-test-checklist.md)
— 9 sections (A–I, 40 items) including the Plan-mandated **outbox tamper** (G1–G4) and **webhook
forgery/replay** (H1–H4) cases. H1–H3 are recorded `BLOCKED`, explicitly *not* passing, with the
owner phase, blocking gate and future acceptance suite named.

**Commands and results**

```bash
docker compose exec -T app php artisan test tests/Feature/Security/Phase23ThreatModelCoverageTest.php
docker compose exec -T app php artisan test --group=security
```

| Gate | Result |
|---|---|
| Threat-model coverage guard | **6 passed** |
| `--group=security` | **286 passed, 0 failed** (4 366 assertions) |
| Pint | PASS (1 662 files) |
| `git diff --check` | clean |

---

## 8. Increment 3 — route, permission, policy and contract hardening

### 8.1 Protected-read authorization coverage (the gap that caused PH23-SEC-001)

`RouteSecurityContractTest` classifies **non-GET routes only**, so nothing mechanically proved a
read route had any authority at all — which is precisely how `GET /api/v1/staff` shipped unguarded.
New guard:
[`tests/Feature/Security/ProtectedReadAuthorizationCoverageTest.php`](../../tests/Feature/Security/ProtectedReadAuthorizationCoverageTest.php).

It enumerates the **live** route table and requires every shipped `api/v1` read route to carry one of
four named boundary kinds. It deliberately does **not** demand identical middleware — Plan §24 allows
different read classes — it demands *equivalent server-side authority, named and evidenced*.

**Investigation result: all 118 read routes already had a real boundary — no new defect.** The
initial run flagged 13, then 5, purely because my detector was too narrow. Each was resolved to a
genuine control rather than waved through:

| Route(s) | Real boundary (verified in source) |
|---|---|
| `branches.show`, `branches.operating-hours.show` | `EnsureBranchScope` middleware |
| `merchant-registration.first-time-setup.show` | `EnsureFirstTimeSetupAccess` middleware |
| `staff.show`, `staff-invitations.index`, `hr.permission-preview`, `cash-ups.branch-day` | policy call via a private controller helper |
| `staff.availability.show` | direct `PersonnelAvailabilityPolicy::view` invocation |
| `files.show`, `files.download` | `FileAccessService::authorizeView/authorizeDownload` |
| `personnel.appointments.index`, `personnel.queue.index`, `personnel.sessions.index` | `abort_unless($this->context->can('personnel.my_*.view'), 403)` |

**Five documented exceptions** remain, each carrying a substantive reason the guard enforces
(>80 chars) and each asserted to still be a live route: `me` and `auth.mfa.status`
(authenticated-self), `search.index` (documented permission intersection, D-22-01), `branches.index`
and `merchant.dashboard` (policy-gated scoped queries). A tripwire caps the exception list at 6 so it
cannot quietly accumulate.

**Pinned boundaries** — a later refactor cannot silently downgrade the contact-bearing surfaces:
`staff.index` and `staff.show` must stay `controller_authorization`;
`branch.personnel-options.index` must stay `permission_middleware`; `files.show`/`files.download`
must keep the `FileAccessService` boundary. Both Phase 23 routes are therefore included in the guard
as required.

### 8.2 Mutation contracts

Re-ran the existing suites unchanged — `RouteSecurityContract`, `FinancialRouteIdempotencyCoverage`,
`AuditMutationCoverage`, `AuditSeverityCoverage`, all `PermissionMatrix*`,
`PermissionDatabaseProjection`, `PermissionRoleBoundary`, `PermissionMfaCoverage`,
`PermissionStepUpCoverage`, plus tenant/branch/own-scope isolation. **No regression**; see the
combined result below.

### 8.3 Forbidden capabilities

New cross-surface guard:
[`tests/Feature/Security/Phase23ForbiddenCapabilityAbsenceTest.php`](../../tests/Feature/Security/Phase23ForbiddenCapabilityAbsenceTest.php).
The existing `ForbiddenRouteAbsenceTest` covers two forbidden **routes** only; this extends the proof
to the permission registry, the canonical matrix, the OpenAPI document, both generated TypeScript
contracts, the screen inventory and the navigation YAML — covering Super-Admin merchant/first-admin
creation and impersonation, personnel contact export in every form, Wallet-owned ledger/reconciliation
concepts, R&E referrer/reward/payout concepts, provider runtime surfaces (STK/C2B/PayBill/Till/
callbacks), provider config namespaces, and frontend-held Meilisearch credentials.

**A guardrail on the guard**: a dedicated case proves the legitimate Phase 18A merchant-client payment
method **`mpesa_offline`** still exists in the contract and that none of the provider-runtime patterns
can ever match it. Every provider pattern matches a path **segment** (`/mpesa/`, `/stk/`, `/c2b/`),
so broadening one to a bare `mpesa` substring breaks this case immediately.

### 8.4 Contract privacy

New guard:
[`tests/Feature/Security/Phase23ContractPrivacyTest.php`](../../tests/Feature/Security/Phase23ContractPrivacyTest.php)
— proves the published artefacts (`openapi.json`, `api.ts`, `permissions.ts`) carry no
storage-internal or secret field name (`phone_index`, `phone_encrypted`, `email_encrypted`,
`totp_secret`, `recovery_code_hash`, `token_hash`, `magic_link_token`, `webhook_secret`,
`signing_key`), no credential-shaped literal, no JWT-shaped literal and no absolute private storage
path; that no API Resource exposes a masked phone **and** the raw phone together; and that no
Playwright trace/screenshot/report directory is tracked in git.

### Files changed (Increments 2–3)

**New:** `tests/Feature/Security/Phase23ThreatModelCoverageTest.php`,
`tests/Feature/Security/ProtectedReadAuthorizationCoverageTest.php`,
`tests/Feature/Security/Phase23ForbiddenCapabilityAbsenceTest.php`,
`tests/Feature/Security/Phase23ContractPrivacyTest.php`,
`docs/security/phase-23-threat-model-verification.md`,
`docs/security/phase-23-penetration-test-checklist.md`.
**No production code changed in Increments 2–3** — no defect required one.

### Increment 3 commands and results

```bash
docker compose exec -T app php artisan test tests/Feature/Security/ProtectedReadAuthorizationCoverageTest.php
docker compose exec -T app php artisan test tests/Feature/Security/Phase23ForbiddenCapabilityAbsenceTest.php
docker compose exec -T app php artisan test tests/Feature/Security/Phase23ContractPrivacyTest.php
docker compose exec -T app php artisan test --group=security,route-contract,permissions,matrix,isolation,tenancy,phase23
```

| Gate | Result |
|---|---|
| Protected-read coverage guard | **5 passed** (16 assertions) |
| Forbidden-capability guard | **6 passed** (15 assertions) |
| Contract-privacy guard | **5 passed** (8 assertions) |
| Combined security/permission/isolation groups | **471 passed, 4 skipped, 0 failed** (6 077 assertions) |
| Pint | PASS (1 665 files) — one `unary_operator_spaces` fix applied to the new guard |
| Larastan level 8 | **[OK] No errors** |
| `git diff --check` | clean |

### Remaining risk (Increments 2–3)

1. The protected-read guard is **static**: it proves a boundary *exists and is of the right kind*, not
   that every policy decides correctly. Behavioural correctness stays with the per-domain suites
   (e.g. `StaffReadAuthorizationTest` proves the five denied roles).
2. `merchant.dashboard` and `branches.index` are scoped-query reads with no permission key. That is
   the shipped design (Plan §8.1/§8.2) and both are tenant-resolved, but they are the two exceptions
   most worth revisiting if a future phase adds a dashboard permission.
3. TM-39/TM-40 remain **blocked**, not proven safe. Their controls (§9 rule 21 signature verification,
   first-seen `wallet_event_id`) cannot be exercised until Gate W opens.

---

## 9. Increment 4 — finance and audit export hardening

**Delivered.** The canonical matrix is machine-checked: `P23_EXPORT_SURFACES` /
`P23_NON_DOCUMENT_ROUTES` inside
[`tests/Feature/Security/Phase23ExportHardeningTest.php`](../../tests/Feature/Security/Phase23ExportHardeningTest.php)
is the source of truth, and
[`docs/security/phase-23-export-hardening.md`](../security/phase-23-export-hardening.md) renders it.

**Inventory (live `route:list`, filtered on `export|download|pdf|statement|receipt|invoice|report|document`):**
**22 document surfaces** classified (5 finance-export, 6 audit-export, 3 file-domain, 3 receipt,
4 subscription-invoice, 1 personnel statement) plus **13 shaped-but-not-document routes** recorded
with a reason rather than silently ignored. Exactly **2** routes serve raw bytes
(`audit-exports.download`, `files.download`); both require signature **and** authentication **and**
re-run `authorizeDownload` at stream time.

No report-download or scheduled-report route exists in the live table. `FilePurpose::DayCloseReport`
and `FilePurpose::CashUpReport` are registered generated-only purposes whose generators belong to
**Phase 21N** (Plan §69) — recorded as truthful absence; **no export type was created.**

All 22 prompt §9 controls have automated evidence; the per-control disposition table and the
Audit-role table (branch-scoped only · `branch_id IS NULL` never exported · review/export metadata as
the only Audit write · operational/financial/hash-chain source rows immutable) are in the matrix
document. Two of the 22 were **defective** and are recorded below.

---

### Defect PH23-EXP-001 — export revocation and expiry never reached the file domain

#### Observed problem

Revoking a finance or audit export made it un-downloadable **only through that export's own
route**. The generated CSV remained fully downloadable through the generic Phase 10F file routes,
indefinitely.

#### Evidence

Written as a failing test before any fix
(`tests/Feature/Security/Phase23ExportHardeningTest.php`), then run:

```
⨯ it stops serving a REVOKED finance export through the generic file endpoints
⨯ it stops serving an EXPIRED finance export through the generic file endpoints
⨯ it stops serving a REVOKED audit export through the generic file endpoints
⨯ it stops serving an EXPIRED audit export through the generic file endpoints
Tests: 4 failed (14 assertions)  —  "Expected response status code [404] but received 200"
```

The 200 came from `POST /api/v1/files/{ulid}/download-link` **after** the export's own route had
correctly returned 409. Supporting source evidence:

1. `RevokeFinanceExport` / `RevokeAuditExport` / `ExpireFinanceExport` / `ExpireAuditExport` set the
   **export** status and nothing else — a repository-wide search for `FileLifecycleStatus::Revoked`
   returned exactly one production writer, `GenerateSubscriptionInvoicePdf` (superseding an old PDF).
2. `FileAccessService::authorizeDownload` consults `UploadedFile::isDownloadable()`, i.e. the
   **file's** `lifecycle_status`/`scan_status`/`final_path` — never the owning export's status.
3. The file ULID needs no guessing: `issueSignedUrl()` returns
   `…/files/{uploadedFile}/download?signature=…`, so the caller legitimately learns it from the very
   link it was issued.
4. Both `files.download-link` and `files.show` are authorized by the purpose permission
   (`finance_export.download`, `audit.export`) which the same Finance/Audit caller holds.

#### Affected files / routes / tables

Routes `files.show`, `files.download-link`, `files.download` (the bypass) and
`finance-exports.revoke`, `audit-exports.revoke` (the control that did not take effect).
Actions `RevokeFinanceExport`, `ExpireFinanceExport`, `RevokeAuditExport`, `ExpireAuditExport`.
Table `uploaded_files` (`lifecycle_status`). No schema change.

#### Root cause

Export revocation/expiry was modelled purely on the export aggregate, but the **file domain is a
separate authorization boundary with its own routes**, and it decides on the file's lifecycle. The
`FileLifecycleStatus::Revoked` / `Expired` states exist for exactly this purpose and nothing wired
the export lifecycle into them.

#### Why this is the root cause

Removing either half closes the hole, and only the file half is correct. Blocking the generic route
for export-purpose files would break the legitimate download path that deliberately reuses it.
Making `FileAccessService` consult the owning export would invert the dependency (the file domain
would have to know about FinanceOps and Audit). Writing the terminal state onto the file is the
established in-repo pattern — `GenerateSubscriptionInvoicePdf` already calls
`markLifecycle(Revoked)` for the same "must no longer be served" meaning.

#### Correct fix

Propagate the terminal state onto the file **inside the same transaction** as the export
transition:

- `RevokeFinanceExport`, `RevokeAuditExport` → `$locked->file?->markLifecycle(Revoked)`
- `ExpireFinanceExport`, `ExpireAuditExport` → `$locked->file?->markLifecycle(Expired)`

and widen the file-domain retention sweep so byte cleanup is **not** regressed:
`ExpireSignedExport` now selects `available` **or** `revoked` rows past `retention_until`. Revoked
rows converge to `expired` on sweep, so it stays bounded and never re-selects. Byte deletion remains
solely in the file domain (Plan §65 storage boundary) — no domain action touches storage.

#### Files changed

`app/Domain/FinanceOps/Actions/RevokeFinanceExport.php` ·
`app/Domain/FinanceOps/Actions/ExpireFinanceExport.php` ·
`app/Domain/Audit/Actions/RevokeAuditExport.php` ·
`app/Domain/Audit/Actions/ExpireAuditExport.php` ·
`app/Domain/Files/Jobs/ExpireSignedExport.php`

#### Tests added

The four cases above, each asserting the export's own route still 409s **and** the generic file
route now 404s, including a real end-to-end signed URL replay for the finance cases.

#### Test command and result

```bash
docker compose exec -T app php artisan test tests/Feature/Security/Phase23ExportHardeningTest.php
```

**12 passed (59 assertions)** — 4 red → green, plus the 8 matrix cases.

#### Proof of resolution

The four cases failed on the pristine code and pass with the change; the failure text
("received 200") is the bypass itself, so the guard is demonstrably non-vacuous. No test was
weakened and no role was widened.

#### Remaining risk

Byte retention after a **domain-triggered** expiry — see **REM-EXP-001** (§6 of the matrix
document). Inert in production today because neither expiry action is scheduled and the hourly file
sweep fires at the same retention instant.

---

### Defect PH23-EXP-002 — the billing invoice PDF purpose declared no resource permission

#### Observed problem

**Front Office and Personnel could download the merchant's platform subscription invoice PDF**
through the generic file routes, bypassing the Merchant-Administrator-only
`merchant.subscription.invoice.download` that guards the domain route.

#### Evidence

```
⨯ it gates the billing invoice PDF on its resource permission, not tenant membership alone
  Expected response status code [403] but received 200.
```

`FilePurposeRegistry` declared `FilePurpose::BillingInvoicePdf` with `permission => null`,
`requiresBranch => false`, `requiresOwner => false`. `FileAccessService::authorizeView` skips the
permission check entirely when the purpose declares none, so tenant membership was the whole
boundary. Every comparable financial purpose declares one — `InvoicePdf → invoice.view`,
`ReceiptPdf → receipt.view`, `FinanceExport → finance_export.download`,
`AuditExport → audit.export`, `DayCloseReport`/`CashUpReport → reports.view`.

Plan §65 is explicit: *"Download authorization: authenticated … + tenant ownership + branch scope +
**resource permission** + file purpose + available status …"*.

#### Affected files / routes

`app/Domain/Files/FilePurposeRegistry.php`; routes `files.show`, `files.download-link`,
`files.download` for `billing_invoice_pdf` files. No schema change; **no permission was invented** —
`merchant.subscription.invoice.download` already exists and already gates the domain route.

#### Root cause

Phase 20B attached the PDF generator and gated the **domain** route, but left the purpose's registry
entry at the Phase 10F placeholder `null`. Nothing mechanically required a generated purpose to
declare a download authority, so the omission shipped.

#### Why this is the root cause

The domain route is correctly gated; the leak is only reachable through the *generic* file routes,
which consult the registry and nothing else. Setting the purpose permission closes it at the single
point every file route already passes through, and the Merchant Administrator path is unaffected
because it holds the key.

#### Correct fix

Declare the existing key on the purpose:
`FilePurpose::BillingInvoicePdf → 'merchant.subscription.invoice.download'`.

**Plus the mechanical guard the defect escaped**: a new case fails for *any* generated (non-uploadable)
purpose whose `permission` is `null` **and** which is not owner-scoped. `EarningsStatement` passes it
legitimately — `requiresOwner` is its authority.

#### Files changed

`app/Domain/Files/FilePurposeRegistry.php` (one purpose definition + the reason),
`tests/Feature/Billing/SubscriptionInvoicePdfDownloadTest.php` (fixture repair, below).

#### Test-fixture repair (not a product change)

Three `SubscriptionInvoicePdfDownloadTest` cases then failed with `AccessDeniedHttpException`. They
called `FileAccessService` directly under `TenantContext::bindForJob()`, which by design carries
**no permissions**, with a bare `User::factory()` holding no membership at all — a context no
download request can ever have (every caller of `authorizeDownload` is a controller behind
`ResolveTenantContext`). The fixture now builds a real Merchant Administrator via `activeAdmin()`
and binds through `TenantContextResolver::populate()`, exactly as the middleware does. This
**strengthens** the tests — they now exercise a caller who genuinely holds the download authority
instead of one whose permission set was vacuously empty. No assertion was weakened.

#### Test command and result

```bash
docker compose exec -T app php artisan test tests/Feature/Billing/ tests/Feature/Security/Phase23ExportHardeningTest.php
```

**468 passed (1 488 assertions)**, 0 failed.

#### Proof of resolution

Front Office and Personnel now receive **403** on both `files.show` and `files.download-link` for a
billing invoice PDF; the Merchant Administrator path is unchanged and still green across the whole
`tests/Feature/Billing/` suite.

#### Remaining risk

None specific. The generated-purpose authority guard now covers every present and future purpose.

---

### Detector correction (false positive in my own guard)

The first run of the control-set case reported *"declared control 'auth' is NOT present"* for 6
surfaces and a missing `ValidateSignature` on both stream routes. **The product was correct**:
`Route::gatherMiddleware()` returns Laravel **aliases** (`auth:sanctum`, `signed`) for framework
middleware while `route:list --json` prints resolved FQCNs. The matcher now accepts both forms. This
was a detector defect, corrected in the detector — the middleware itself was verified present on the
live routes.

### Increment 4 commands and results

```bash
docker compose exec -T app php artisan test tests/Feature/Security/Phase23ExportHardeningTest.php
docker compose exec -T app php artisan test tests/Feature/Files/ tests/Feature/Finance/ tests/Feature/Audit/ tests/Feature/Billing/ tests/Feature/Receipts/ tests/Feature/Isolation/
docker compose exec -T app php artisan test --group=security,files,audit,audit-exports,finance-exports,payments,billing,subscription-invoice,phase23,route-contract,permissions,matrix,isolation,tenancy
```

| Gate | Result |
|---|---|
| Export-hardening guard | **12 passed** (59 assertions) |
| Export/file/finance/audit/billing/receipt/isolation directories | **714 passed, 7 skipped, 0 failed** (3 287 assertions) |
| Combined 14-group regression | **898 passed, 7 skipped, 0 failed** (8 455 assertions) |
| Pint | PASS (1 666 files) — one `fully_qualified_strict_types` fix applied to the new guard |
| Larastan level 8 | **[OK] No errors** |
| `git diff --check` | clean |

---

## 10. Increment 5 — requirement traceability and CI enforcement

Closes **REM-TRACE-001** locally. The CSV is now a checked contract, not a document:
[`tests/Feature/Traceability/Phase23TraceabilityTest.php`](../../tests/Feature/Traceability/Phase23TraceabilityTest.php)
(14 cases) plus the extended
[`screenInventory.spec.ts`](../../resources/spa/src/screens/screenInventory.spec.ts) (13 cases), both
invoked by CI, with the vocabulary documented in [`docs/traceability/README.md`](../traceability/README.md).

### 10.1 Controlled status vocabulary

Seven values, chosen to match the lifecycle names the repository already uses (the remediation
register's `verified_complete` / `local_complete`) rather than inventing new wording:

`verified_complete` · `local_complete` · `implemented` · `architecture_adopted` ·
`blocked_external_gate` · `deferred_future_phase` · `not_applicable`

**Rejected:** `not_implemented`, `partially_implemented`, any prose/parenthetical/multiline value.

### 10.2 Reconciliation — five distinct drifts, each verified live

| Drift | Rows | Evidence used |
|---|---|---|
| `implemented` although the owning phase is merged **and** verified | **18** (phases 3–9, R2–R4) | merged PRs #3–#9 / #14–#16 with green CI, a proof file each, Phase V as-built verification (PR #12, `c58b64a`), gate closure (PR #20, `7ac20a5`); PROGRESS records R2/R3/R4 as `verified_complete` verbatim |
| Stale against a merged owner | **4** — `SRV-PERM-002`, `SRV-AUDIT-005` (Phase 19 PR #32 `7ef259e2`), `SRV-COMPENSATION-001` (20F PR #39 `f4bc664`), `SRV-COMPENSATION-002` (20G PR #41 `dcdbfb6`) | live PROGRESS lifecycle + merge commits |
| Wrong disposition | **2** — `SRV-AUDIT-003` `partially_implemented`, `SRV-AUDIT-004` `not_implemented` | see below |
| Narrative prose inside `status` | **2** — `SRV-PAYMENT-001/002` | the cells held whole CI histories; moved verbatim into `evidence` |
| `automated_tests` naming suites that **do not exist** | **41 references across 6 rows** | filesystem resolution |

**`SRV-AUDIT-003`** was `partially_implemented` because integration (Wallet) audit emissions were
outstanding. Plan §70 is explicit — *"integration audit events land with their owning phases
(20D-W, 21R-A, 21R-B)"* — so they are **not in this row's scope**. Every emitting domain that is in
scope is merged (20A–20H billing/compensation, 21S SMS, 10F file/export), and
`AuditMutationCoverageTest` mechanically proves **every** live mutating route emits a typed event.
→ `verified_complete`, with the Wallet emissions tracked on the new blocked `SRV-WAL-002`.

**`SRV-AUDIT-004`** claimed `not_implemented` under phase 25 — **stale and wrong**. The scheduled
chain verification *and* its bounded redacted failure signal shipped in **Phase 19 Increment 7**:
`routes/console.php` registers `audit:verify-chain` `->daily()->withoutOverlapping()->onOneServer()`,
proven by `AuditChainScheduleTest` + `AuditChainFailureSignalTest` + `AuditChainVerificationTest`.
Only the **centralized alert transport** is Phase 25 (§71) — split out as the new
`SRV-AUDIT-006` (`deferred_future_phase`) rather than left overstating absence.

**The 41 fictional test references** were aspirational names written when each row was drafted and
never reconciled to the suites that shipped — e.g. `PayoutRunStateMachineTest`,
`PromotionStateMachineTest`, `LargestRemainderAllocationTest`, `SalaryProrationTest`,
`CommissionCalculationTest`. Every one was replaced with the **actual** suite list for that domain
(`SRV-OPS-001`, `SRV-PROMOTION-001`, `SRV-FREE-PERIOD-001`, `SRV-PLATFORM-FEE-001`,
`SRV-COMPENSATION-002`, `SRV-PAYOUT-EARNINGS-001`). This is the single most important fix in the
increment: a requirement claiming coverage from a non-existent suite is worse than an empty cell.

### 10.3 Blocked and future work now modelled (5 new rows)

| Row | Phase | Status | Absence evidence |
|---|---|---|---|
| `SRV-WAL-002` — Wallet payment runtime | 20D-W | `blocked_external_gate` | `NoDirectProviderIntegrationTest`, `ForbiddenRouteAbsenceTest`, `Phase23ForbiddenCapabilityAbsenceTest`, TM-40; Gate W evidence paths absent |
| `SRV-RE-002` — R&E inbound reconciliation | 21R-B | `blocked_external_gate` | TM-39 (no inbound partner-write route), forbidden-capability guard; §80.1 chain |
| `SRV-REPORT-001` — reporting catalogue / notifications / queue topology | 21N | `blocked_external_gate` | `Phase23ExportHardeningTest` (no report-download surface exists), `screenInventory.spec` |
| `SRV-AUDIT-006` — centralized alert transport | 25 | `deferred_future_phase` | `AuditChainFailureSignalTest` proves the bounded signal seam exists |
| `SRV-SEC-001` — Phase 23 itself | 23 | `implemented` | the Phase 23 suites; **cannot** be `verified_complete` — a dedicated guard case enforces that |
| `SRV-PERF-001` / `SRV-DEPLOY-001` | 24 / 25 | `deferred_future_phase` | Plan §72 / §71,§76–§78 + §80.1 order |

**CSV: 53 → 60 rows.** Final distribution: `verified_complete` 51 · `blocked_external_gate` 3 ·
`deferred_future_phase` 3 · `architecture_adopted` 2 · `implemented` 1. Zero `not_implemented`, zero
prose statuses, zero blank cells.

---

### Defect PH23-SCAN-001 — five static security guards were silently scanning ~89% of the codebase

#### Observed problem

`RecursiveIteratorIterator(RecursiveDirectoryIterator(...))` **truncates directory listings** on the
Docker Desktop bind mount this project develops against. Five static-analysis guards were built on
it, so each reported a clean result while never reading part of the code it claimed to cover.

#### Evidence

Measured directly in the container against two independent enumerations:

| Root | `RecursiveDirectoryIterator` | `scandir` recursion | Symfony Finder |
|---|---|---|---|
| `app/` (`*.php`) | **970** | **1 087** | 1 087 |
| `routes/` | 3 | 3 | — |
| `config/` | 17 | 17 | — |

**117 of 1 087 PHP files under `app/` — 10.8% — were never opened.** The truncation is not
alphabetical-prefix consistent either: in `tests/Feature/Auth/` the iterator returned 15 of ~40
files, starting mid-alphabet at `PermissionPreviewTest.php`, so `MagicLinkRequestTest`,
`MfaEnrollmentTest` and `PermissionMatrixTest` were invisible despite `file_exists()` returning true
for each. This was discovered because the new traceability guard reported real, existing suites as
missing.

#### Affected files

- `tests/Feature/Security/Phase23ThreatModelCoverageTest.php` — **the TM-29 SSRF absence proof**,
  which asserts it "walks every PHP file under `app/`"
- `tests/Feature/Security/NoDirectProviderIntegrationTest.php` — the Plan §9 rule 20 guard
- `tests/Feature/Files/FileStorageBoundaryTest.php` — the Plan §65 storage-boundary guard
- `tests/Feature/Security/Phase23ForbiddenCapabilityAbsenceTest.php` — SPA credential scan
- `tests/Feature/Integrations/ReferEarn/ReferEarnScopePurityTest.php`

No production code is affected. The defect is in the **evidence**, not the product.

#### Root cause

`RecursiveDirectoryIterator` holds an open directory handle per level and re-reads it lazily; on this
mount's FUSE layer the handle returns a partial listing part-way through traversal. `scandir()` reads
the whole directory in one call and Symfony Finder buffers, so neither is affected.

#### Why this is the root cause

Swapping only the enumeration, changing nothing else, makes the invisible files visible (970 → 1 087)
and makes the traceability guard resolve every real suite. The regex patterns, the roots and the
assertions were all correct; the file list they were applied to was incomplete.

#### Correct fix

One shared, deterministic enumeration — `sourceFilesUnder(string $dir, array $extensions)` in
`tests/Pest.php` (the established home for parallel-safe shared helpers) — built on `scandir`
recursion and sorted for determinism, adopted by all five guards. **Plus a self-check**: the SSRF
absence proof now asserts its own walked count equals an independent Symfony Finder count, so a
truncated scan can never again masquerade as a clean result:

```
expect(count($scanned))->toBe($independent,
    'SSRF scan coverage is incomplete: walked %d of %d PHP files under app/.');
```

#### Files changed

`tests/Pest.php` (new helper) · the five guards above · `Phase23TraceabilityTest.php`.

#### Test commands and results

```bash
docker compose exec -T app php artisan test tests/Feature/Security/ tests/Feature/Files/ tests/Feature/Integrations/ tests/Feature/Infrastructure/
docker compose exec -T app php artisan test tests/Feature/Traceability/
```

`314 passed, 3 skipped, 0 failed` (1 584 assertions) · traceability `14 passed`.

#### Proof of resolution

The five guards still pass **with 117 more files in scope**, so their clean results are now genuine
rather than incidental. The coverage self-check fails if the walked count ever diverges again.

#### Remaining risk

The mount behaviour is environmental and could affect any future use of
`RecursiveDirectoryIterator`. `sourceFilesUnder()` is now the only sanctioned enumeration for
static guards; the coverage self-check on the SSRF proof is the tripwire.

---

### 10.4 Screen-inventory and §27.1 specification guard

Three narrow metadata defects were proven from live evidence and fixed; the fourth finding is a
**release gap** that Phase 23 must not invent its way out of.

**1. Orphan generated spec — `docs/frontend/screens/finance/finance-dashboard.md` (deleted).**
Proven stale, not merely unreferenced: it self-describes as *"Finance dashboard (stub)"* with owning
phase *Phase 4*, was generated by Phase 11 (PR #23, `d098f37`), and its inventory key
`finance-dashboard` was **renamed** to `finance-task-inbox` by Phase 18B (PR #31, `64bd0a1`) — which
generated `finance-task-inbox.md` for the same route (`finance.dashboard`), same layout, same roles,
and left the superseded file behind. Deleting regenerable generator output whose owner no longer
exists is the smallest correct fix; the new orphan guard prevents recurrence.

**2. Duplicate planned entry — `hr-eligibility` (removed).** Identical `domain`, `layout`, `roles`
and `permissions: ["personnel.eligibility.manage"]` to the **implemented** `service-eligibility`,
which already owns the very route name `hr.eligibility`. The "availability" half of its title is
delivered by the implemented `personnel-schedule` (Phase 15B). It was a placeholder for work that had
already shipped under a different key.

**3. Mis-attributed phase — `platform-audit-reports` (Phase 19 → Phase 21N).** Plan §27.3 lists
"platform audit/reports" under **Super Administrator**, and Plan §69 places the entire reporting
catalogue in **Phase 21N**. Every §27.3 **Audit-role** screen (branch audit log, flagged-event review,
compensation/finance audit, masked export) is already implemented by Phase 19. Attributing a
reporting screen to Phase 19 made a 21N deferral look like a Phase 19 gap.

**Inventory: 124 → 123 entries** (98 `implemented` + 18 `phase_11` + **7** `planned`);
**116 specs on disk = 116 referenced, 0 orphans, 0 missing.** `inventory.yaml` regenerated via its
Vitest file snapshot and all 116 specs regenerated by `node scripts/generate-screen-specs.mjs`
(byte-identical apart from the removed entry).

**New guard cases** (Vitest, CI Frontend job): no orphan spec · no **unregistered** planned screen
owned by a verified-complete phase · the registered release-gap list is **exact** (it fails when the
owning phase finally delivers, forcing removal) · every other planned screen names a genuinely
unshipped phase · a route-less live screen only for the declared access-state boundaries.

### 10.5 CI enforcement

Two **named steps added** to the existing jobs — no job removed, no check made optional, nothing
bypassed, no network call:

- **Backend** — `Contract — requirement traceability (Plan §85)`:
  `php artisan test tests/Feature/Traceability --fail-on-skipped`, placed after Larastan and before
  the parallel suite so the failure is unambiguous. The suites also run inside `--parallel`.
- **Frontend** — `Contract — screen inventory and §27.1 specifications`:
  `npx vitest run src/screens/screenInventory.spec.ts`, before the full Vitest run.

### 10.6 Increment 5 commands and results

```bash
docker compose exec -T app php artisan test tests/Feature/Traceability/
npx vitest run resources/spa/src/screens/screenInventory.spec.ts
docker compose exec -T app php artisan test tests/Feature/Security/ tests/Feature/Files/ tests/Feature/Integrations/ tests/Feature/Infrastructure/
npm run test
npm run lint
```

| Gate | Result |
|---|---|
| Traceability guard | **14 passed** (19 assertions) |
| Screen-inventory guard | **13 passed** (was 8) |
| Security/files/integrations/infrastructure | **314 passed, 3 skipped, 0 failed** (1 584 assertions) |
| Vitest (full) | **527 passed / 98 files** (was 522) |
| ESLint | **0 errors, 138 warnings** — exact baseline, **no new warning** |
| Pint | PASS (1 667 files) — 2 `fully_qualified_strict_types` fixes on the new/edited guards |
| Larastan level 8 | **[OK] No errors** |
| `git diff --check` | clean |

### 10.7 Increment 5 residual items

1. **REM-SCR-002 opened — two Plan §27.3 launch screens do not exist.** See §12; this is the one
   material decision blocking Phase 23 local completion.
2. **`branch.calendar.manage` and `exports.staff_roster` are the only two ACTIVE permission keys with
   no consumer of any kind.** An audit of all 131 active keys against the live route table found 18
   not referenced by `EnsurePermission`; 16 are legitimately enforced by a policy call,
   `abort_unless(context->can(...))`, or a `FilePurposeRegistry` purpose. The remaining two are
   genuinely unconsumed. `branch.calendar.manage` is part of REM-SCR-002. `exports.staff_roster`
   ("Export the staff roster only", HR default grant, `owning_phase: Phase 21N`) **has no Plan
   definition at all** — it appears nowhere in the Plan — and sits adjacent to the permanently
   prohibited personnel-contact-export boundary (Plan §9 rule 6). Retiring or re-scoping it changes
   the permission matrix and needs product-owner authority (CLAUDE.md §9), so it is recorded, not
   changed.

---

## 12. REM-SCR-002 — the two omitted Plan §27.3 launch screens

**Product-owner decision, 2026-07-27: Option A — build both screens now.** Option B (accept as a
documented pre-release gap and close Phase 23) was explicitly declined, and Plan §27.3 was explicitly
**not** amended. Executed on this branch as bounded corrective remediation for omitted owning-phase
deliverables, deliberately **before** Increments 6–9 so the release-wide responsive / dark-mode /
accessibility / E2E audits include both screens rather than auditing an absent surface.

### Observed problem

Plan §27.3 (Minimum Screen Inventory) line 1937 lists **"merchant profile"** among the Merchant
Administrator launch screens and line 1938 lists **"branch profile/calendar"** among the Branch
Manager launch screens. Neither existed. Both inventory entries were `planned` with `route: null` and
no spec, while their owning phases (**15A** and **16A**) were recorded `verified_complete`.

### Evidence

Surfaced by the Increment 5 screen-inventory release-gap guard, then verified live:

| | `merchant-profile` | `branch-calendar` |
|---|---|---|
| Permission | `merchant.profile.view`/`.update` **planned** (owner 20A, merged); legacy `merchant.profile.manage` **active** | `branch.calendar.manage` **active canonical** (`owning_phase: null`) |
| Only consumer | the `merchant_logo` file purpose | **none anywhere in `app/`** |
| Table | `merchant_profiles` exists, filled by first-time setup | **`branch_calendar_exceptions` EXISTS** (`Schema::hasTable` confirmed) |
| Runtime consumer | — | **`AppointmentBranchScheduleValidator` already honours every type** |
| Route / controller / screen | none | none |

`branch.calendar.manage` and `exports.staff_roster` were the **only two** of 131 active permission
keys with no consumer of any kind. An active canonical key plus a live table plus a complete runtime
consumer plus **zero** operator surface is a shipped-schema, unshipped-feature gap.

### Affected files / routes / screens / tables

Tables `merchant_profiles`, `branch_calendar_exceptions` (both as-built; **no migration added**);
absent routes `merchant.profile.*` and `branches.calendar-exceptions.*`; absent screens
`merchant/merchant-profile.md`, `branch/branch-calendar.md`; `docs/auth/permission-matrix.yaml`.

### Root cause

Phases 15A/16A/20A were closed against their own increment checklists rather than against the Plan
§27.3 launch-screen list, and **no guard compared the two** — which is exactly why the omission
survived three `verified_complete` records. For the calendar, the schema, the permission and the
scheduling gate were delivered as groundwork by the Phase 7/16A work; only the surface that feeds
them was never built, and because the inventory entry read `planned`, the omission looked deliberate.

### Why this is the root cause

The capability was absent, not unguarded — nothing was mis-authorized, and both keys are
`revocable_only`. The single missing artefact in each case is the operator surface, and the guard
added in Increment 5 (`planned` screen owned by a verified-complete phase) is what makes recurrence
impossible: it fails for any new occurrence, and its registered-gap list is asserted **exact**, so it
also failed the moment these two were delivered and forced the register entries out.

### Correct fix

**REM-SCR-002A — merchant profile.** Activated the canonical §19.3 pair
(`merchant.profile.view  M|-|A|n/a|Y|-|info`, `merchant.profile.update  M|-|R|n/a|Y|-|high`), Merchant
Administrator only. **Retired the legacy duplicate `merchant.profile.manage`** — the matrix invariant
(`PermissionLegacyKeyReconciliationTest`) forbids a legacy key whose successor is already active, and
retirement-on-activation is the precedent Phases 20A, 20B, 20E and 20F each applied (legacy keys
17 → 8, now → **7**). Its `merchant_logo` file purpose moved to the canonical write key; the dead
`MerchantPolicy::manageProfile` (no caller anywhere) was deleted.
`GET|PATCH /api/v1/merchant/profile` carry **no `{merchant}` binding** — the tenant is resolved from
the membership, so no request can name another tenant. `UpdateMerchantProfile` locks the row, writes a
**7-field allowlist** (the same fields first-time setup supplies), and audits
`merchant.profile_updated` with the changed **field names only**, never the values.
`MerchantProfileResource` exposes ULIDs only; the logo is `{id, filename}` — never a path, URL or
signature — and upload continues through the **existing Phase 10F scanned pipeline**
(`POST /api/v1/files`, purpose `merchant_logo`). The legacy, never-written
`merchant_profiles.logo_path` column was deliberately left untouched.

**REM-SCR-002B — branch calendar.** **No key activated and none invented.** Four routes inside
`EnsureBranchScope`, each gated by `branch.calendar.manage`, with `EnsureBillingMutable` on every
write (matrix `R`) and `BranchMutation` classification. `(branch, date)` is the public identity — the
row has no ULID — and **exactly one exception per date** is permitted. That last rule is not
cosmetic: `AppointmentBranchScheduleValidator::openWindowFor()` resolves a date with
`whereDate('date', …)->first()`, so two exceptions on one date (which `UNIQUE(branch_id, date, type)`
would allow) would have made the scheduling decision **order-dependent**. Constraining the only
surface that can create an exception keeps that pre-existing latent ambiguity unreachable.
Closure types are normalised to a null window and reject supplied times; `modified_hours` requires
both and rejects an inverted window — because the validator treats a windowless modified-hours row as
fully closed, which would silently contradict the operator.

### Files changed

**Backend (new):** `UpdateMerchantProfile`, `MerchantProfilePolicy`, `UpdateMerchantProfileRequest`,
`MerchantProfileResource`, `MerchantProfileController`; `SetBranchCalendarException`,
`DeleteBranchCalendarException`, `BranchCalendarException` (exception),
`BranchCalendarExceptionPolicy`, `Store`/`UpdateBranchCalendarExceptionRequest`,
`BranchCalendarExceptionResource`, `BranchCalendarExceptionController`.
**Backend (modified):** `PermissionRegistry`, `AuditEvent` (+3 cases with severities),
`AuditMutationCoverage` (+4 route mappings), `RouteClassification` (1 validation exemption),
`FilePurposeRegistry`, `MerchantPolicy`, `AppServiceProvider`, `BranchCalendarException` (model
docblock), `routes/api.php`.
**Contracts:** `permission-matrix.yaml`, `permissions.ts`, `openapi.json`, `api.ts`,
`inventory.json`, `inventory.yaml`, `role-navigation.yaml`.
**Frontend:** `MerchantProfile.vue`, `BranchCalendar.vue`, `merchantProfileStore.ts`,
`branchCalendarStore.ts`, `models.ts`, `roleNavigation.ts`, `router/routes/{merchant,branch}.ts`,
`BranchDetail.vue`, 2 new §27.1 specs.
**Tests:** 2 new backend suites, 2 new component specs, plus 8 hand-maintained expectation updates.

### Permission counts

| | active | planned | catalogue | legacy |
|---|---|---|---|---|
| Phase 21S left | 130 | 38 | 168 | 8 |
| + `staff.view` (PH23-SEC-001) | 131 | 37 | 168 | 8 |
| + `merchant.profile.view`/`.update` | 133 | 35 | 168 | 8 |
| − `merchant.profile.manage` (retired) | **132** | **35** | **167** | **7** |

The catalogue shrank **only** by the retired legacy duplicate. No canonical key was invented.

### Routes / screens

Routes **295 → 301** (`api/v1` 288 → 294; `api/v1` GET 118 → 120). OpenAPI **296 production routes,
247 paths, 296 operations**. Screens **123 entries** — `implemented` 98 → **100**, `phase_11` 18,
`planned` 7 → **5**; specs on disk **116 → 118**, 0 orphans, 0 missing. Registered release gaps:
**2 → 0**.

### Tests added or updated

`MerchantProfileApiTest` (12): auth on both verbs · read exposes no internal id/`logo_path`/storage
path · the allowlist ignores `country`/`merchant_id`/`service_fee_tier`/`status`/`billing_status` ·
audit exactly once with **field names only and neither the new nor the old value** · a no-op payload
writes no audit row · validation incl. a real-timezone check and a clearable optional field · **all
six non-admin roles denied on read and write** · cross-merchant isolation both directions · read
allowed / write 403 `billing_read_only` in both read-only billing states · logo as `{id, filename}`
with no `signature=`/`generated/` · another merchant's logo never surfaces · the `merchant_logo`
purpose now gates on the canonical write key.

`BranchCalendarExceptionApiTest` (19): auth on all four routes · closure create + read-back with no
`branch_id`/`merchant_id`/`created_by`/`id` · modified-hours window required and ordered · closure
rejects times · **one exception per date** with `calendar_exception_exists` · date/type/reason
validation · update changes the window and reason but **never the date or type** · delete + 404 on an
unknown date · exactly one typed audit event per create/update/remove · bounded range with inverted
and oversized rejection · five roles + the Merchant Administrator denied · foreign-tenant 404 and
same-tenant out-of-branch 403 · caller-supplied `branch_id`/`merchant_id` cannot widen scope ·
billing read-only blocks writes but not the read · **five runtime-integration cases** proving the
existing scheduling gate closes on a closure, honours modified hours exactly, stops blocking on
removal, confines the closure to its own branch and tenant, and **resolves on the Nairobi business
date, not the UTC date** (pinned against a PH23-DET-001-style regression).

Frontend `MerchantProfile.spec` (6) and `BranchCalendar.spec` (11) prove the endpoints called, the
exact writable payload keys, `aria-invalid`/`aria-describedby` error association, read-only rendering
without the write key, the short-lived logo link, the conflict and billing-read-only messages,
delete-by-date, edit that never offers date/type, no window sent for a closure, and that the calendar
**never** calls an availability, appointment, day or staff endpoint.

### Test commands and results

```bash
docker compose exec -T app php artisan test tests/Feature/Merchants/ tests/Feature/Branches/
npx vitest run resources/spa/src/pages/merchant/MerchantProfile.spec.ts resources/spa/src/pages/branch/BranchCalendar.spec.ts
docker compose exec -T app php artisan test --group=security,phase23,traceability,contracts,route-contract,permissions,matrix,isolation,tenancy,branches,merchants,branch-calendar,merchant-profile,rem-scr-002,audit,files
npm run test && npm run typecheck && npm run lint && npm run build
```

| Gate | Result |
|---|---|
| `tests/Feature/Merchants/` + `tests/Feature/Branches/` | **67 passed** (301 assertions) |
| Component specs | **17 passed** (6 + 11) |
| Combined 16-group backend regression | **680 passed, 7 skipped, 0 failed** (7 824 assertions) |
| Traceability guard | **14 passed** (21 assertions) |
| Screen-inventory guard | **13 passed** |
| Vitest (full) | **544 passed / 100 files** (was 527) |
| ESLint | **0 errors, 138 warnings** — exact baseline restored after `--fix` on the new files |
| `vue-tsc --noEmit` | clean |
| Production build | succeeded |
| Pint | PASS (1 682 files) |
| Larastan level 8 | **[OK] No errors** |
| `api:contract:check` | OK — 247 paths, 296 operations |
| `servana:permission-types --check` | up to date |
| `git diff --check` | clean |

### Proof of resolution

Both `planned` inventory entries are now `implemented` with a live route, a generated §27.1 spec and
tests; the screen guard's registered-release-gap list is **empty** and asserted exact, so it now fails
if either regresses. `branch.calendar.manage` has a consuming route for the first time, and the
calendar demonstrably drives the shipped scheduling gate. The legacy `merchant.profile.manage` is
absent from the YAML, the PHP registry, the DB projection and the generated TypeScript.

### Remaining risk

1. Responsive / dark-mode / accessibility / E2E coverage for both new screens is **owed by Increments
   6–9** — which is why they were built first. Until those run, the screens have unit/API/component
   evidence but no axe, viewport or Playwright evidence.
2. `exports.staff_roster` remains the last ACTIVE key with no consumer and **no Plan definition at
   all**. Retiring or re-scoping it changes the permission matrix and needs product-owner authority.
3. Day open/pause/close remains the separate Branch Day workflow; this screen deliberately does not
   duplicate those transitions.
4. Editing a calendar exception cannot re-point its `(date, type)` identity — the operator deletes and
   re-creates. This is deliberate (that pair is what the unique constraint and the scheduling gate key
   on) and is recorded in the §27.1 spec.

---

## 13. Increment 6 — whole-product responsive release audit (Plan §28)

Full matrix and per-screen reasoning: **`docs/frontend/phase-23-responsive-dark-audit.md`**.

### 13.1 What was built

| Artefact | Purpose |
|---|---|
| `tests/e2e/support/releaseAudit.ts` | The audit harness: the 118-screen matrix, the deterministic clock/identifier/fixture layer, the per-screen `/me` bootstrap, and the shared assertions. |
| `tests/e2e/phase-23-release-audit.spec.ts` | Increments 6–9 as one data-driven spec. |
| `docs/frontend/phase-23-responsive-dark-audit.md` | The recorded matrix and findings. |

The matrix is **derived from `docs/frontend/screens/inventory.json` at run time**. The
`release-audit coverage` test compares the audited keys against every live (non-`planned`)
inventory entry and fails in both directions, so neither a missing screen nor an invented one can
pass. **118 live screens** are audited: 100 `implemented` + 18 `phase_11`. The 5 `planned` screens
are owned by phases that genuinely have not shipped and are deliberately not audited.

Viewports: **360 × 780**, **768 × 1024**, **1280 × 900**. Each screen is navigated once and then
**resized** through the matrix, which additionally proves §9.2(18) — a live resize re-lays-out
correctly.

### 13.2 Defect PH23-RSP-001 — hand-rolled inputs without `w-full`

**Observed problem** `merchant-profile` and `branch-calendar` scrolled horizontally at 360 px.

**Evidence** `merchant-profile @ mobile 360px: page scrolls horizontally (scrollWidth 371 >
clientWidth 360) — main#main-content[flex-1 p-4 …] right=371 width=371`. Identical failure on
`branch-calendar`.

**Affected files** `resources/spa/src/pages/merchant/MerchantProfile.vue`,
`resources/spa/src/pages/branch/BranchCalendar.vue`.

**Root cause** An in-page min-content probe measured the chain:
`input 241 → form 241 → SvCard(p-8) 307 → section(p-4) 339 → main(p-4) 371`. The inputs on both
screens are hand-rolled as
`class="min-h-[44px] rounded-control border border-border bg-surface px-3 text-text"` and **omit
`w-full`**, so each keeps its intrinsic `size=20` width of 241 px and, as a flex item, refuses to
shrink.

**Why this is the root cause** These are the only two files in `resources/spa/src/pages` using that
class string, and the only inputs in the product without `w-full` — the shared `SvInput` has it
(`SvInput.vue:62`), which is why no other form screen fails. Adding `w-full` removes the 241 px
floor and the whole chain collapses to the viewport.

**Correct fix** `w-full` on all 15 inputs/selects across the two screens, matching the `SvInput`
convention. The time/reason fields sit in `flex flex-wrap` rows, so their wrappers additionally take
`min-w-[8rem] flex-1` so a full-width input can share the row and wrap rather than force a width.
No field was removed and no label hidden.

### 13.3 Defect PH23-RSP-002 — `main` could not shrink below its content (**pre-existing**)

**Observed problem** `audit-event-detail` scrolled to 440 px at a 360 px viewport;
`audit-event-list`, `audit-finance`, `audit-compensation` and `finance-audit` to 364 px.

**Evidence** `h1[font-display text-2xl font-bold text-heading] w=376 minc=376` — the audit action
`branch.calendar_exception_set` is a machine token with no space to break at.

**Affected files** `resources/spa/src/components/layout/AppShell.vue`,
`resources/spa/src/pages/audit/AuditEventDetail.vue`.

**Root cause** `main#main-content` is a flex item and therefore defaults to `min-width: auto`, so it
cannot shrink below its content's min-content width. One wide child widens the **entire document**
instead of being contained.

**Why this is the root cause** Removing the wide child removes the symptom, and constraining `main`
removes it for every screen at once. The measurement shows `main`'s width tracking its content's
min-content exactly (371 = 339 + its 32 px padding; 440 = 408 + 32).

**Pre-existing, not a Phase 23 regression:** the shell has been this way since Phase 11, and any
sufficiently long audit action reaches it. Earlier phase specs only ever rendered these screens
with short fixture actions.

**Correct fix** Both parts are required:

- `min-w-0` on `main` (`AppShell.vue`) — one line in one audited place;
- `break-words` on the audit action heading (`AuditEventDetail.vue`).

`overflow-wrap: break-word` does **not** reduce min-content width, so the heading fix alone left the
failure in place; it only takes effect once `min-w-0` lets the container narrow. Tables already sit
in `overflow-x-auto` wrappers, so they now scroll inside their container — the Plan §28 behaviour —
instead of widening the page.

### 13.4 Result

| Suite | Command | Result |
|---|---|---|
| Coverage guard | `--grep "release-audit coverage"` | 3 passed |
| Increment 6 | `--grep "responsive:"` | **118 passed** (1.8 m) |

Also proven for every screen: the viewport meta never disables zoom, and a static source guard over
`resources/spa/src` finds **no** JavaScript device detection and **no** jQuery.

---

## 14. Increment 7 — whole-product dark-mode audit (Plan §29, ADR-009)

For all 118 screens, in **light and dark**, the audit proves the theme genuinely applied
(`html.dark` present/absent — a screen that silently stayed light would prove nothing), that **no
text is transparent and no text resolves to the surface behind it** (with every translucent layer
composited against its ancestors), and that neither theme introduces horizontal overflow.

Precise contrast **ratios** are measured by axe (`wcag2a` + `wcag2aa`, which includes
`color-contrast`) in Increment 8, run in **both themes at both widths**. That is where body text,
muted text, headings, links, buttons, borders, inputs, placeholders, validation, disabled states,
status badges, tables, mobile cards, dialogs, empty states and loading states are checked for AA.
Increment 7 catches the one failure axe cannot see: a token that resolves to the same colour as its
background.

### 14.1 False positive — corrected in the guard, no product change

The first run reported `text matches its background: "Overview" (rgb(255, 255, 255))` on all five
Super-Administrator screens. The active header nav item is `bg-white/15 text-white` over the dark
`bg-brand-deep` header; the guard was taking the first non-transparent background instead of
compositing the 15 % overlay. The rendered result is a light-blue tint and the treatment is correct
and AA-compliant.

**This was a defect in the new guard, not in the product.** The guard now composites every
translucent layer and compares within an 8/255 per-channel tolerance. **No brand token was changed
and no product file was touched for this finding** — blanket token replacement was explicitly
avoided.

### 14.2 Result

| Suite | Command | Result |
|---|---|---|
| Increment 7 | `--grep "theme:"` | **118 passed** (2.2 m) |

Zero theme defects were found in the product. Merchant Profile and Branch Calendar both pass in
both themes; the per-item mapping is in `docs/frontend/phase-23-responsive-dark-audit.md` §7.2.

---

## 15. Increment 8 — whole-product accessibility audit (Plan §30)

Full matrix, workflow mapping and per-screen reasoning:
**`docs/accessibility/phase-23-release-audit.md`**.

Every one of the 118 live screens is analysed with `@axe-core/playwright` under `wcag2a` +
`wcag2aa` in **four combinations** — mobile-light, mobile-dark, desktop-light, desktop-dark — for a
total of **472 axe analyses**. Coverage was not reduced to shorten the run. No axe rule is
suppressed anywhere: `withTags` selects the rule set, and violations are filtered only by impact to
the `serious`/`critical` release gate.

### 15.1 Defect PH23-A11Y-001 — `aria-controls` pointed at a tabpanel that did not exist

**Observed problem** `platform-registration-monitoring @ mobile-light`:
`aria-valid-attr-value (critical) — 1 node(s): #tab-monitoring`.

**Evidence** The tabs declare `aria-controls="panel-monitoring"` / `aria-controls="panel-directory"`,
and both `section[role=tabpanel]` elements were nested **inside** `SvStateBoundary`.

**Affected file** `resources/spa/src/pages/platform/RegistrationMonitoring.vue`.

**Root cause** `SvStateBoundary` renders its slot only in the `success` state
(`SvStateBoundary.vue:78`). In `loading`, `empty` and `error` it renders its own status element
instead, so **no tabpanel exists** and both `aria-controls` references dangle.

**Why this is the root cause** Those three states are reachable on every page load (loading), on a
platform with no registrations (empty) and on any API failure (error). The screen passed earlier
phase audits only because those specs always stubbed populated data; the release audit's empty
default exposed it.

**Correct fix** Both tabpanel sections now always exist, the inactive one carrying `hidden`, with
the state boundary rendered **inside** each panel. The directory panel's grid moved from the section
onto an inner wrapper, because a `display: grid` class outranks the `hidden` attribute's user-agent
rule and would otherwise have left the inactive panel visible.

**Proof of resolution** `platform-registration-monitoring` passes all four axe combinations plus the
responsive and theme sweeps; `npm run typecheck` clean; `npm run lint` back to the 0-error /
138-warning baseline.

### 15.2 Behavioural verification

`accessibility behaviour` proves, on the authenticated role shell shared by all 104 shell screens:
the skip link is the first focus stop and moves focus to `#main-content`; landmarks are present and
unique; the mobile drawer takes initial focus, closes on `Escape` and restores focus to its trigger;
every keyboard focus stop has a visible indicator; 200 % zoom (a 640 px CSS viewport) leaves the
page free of horizontal overflow with labelled fields and the primary action visible;
`prefers-reduced-motion: reduce` leaves no animation or transition longer than 200 ms; and the
viewport meta never disables scaling.

**False positive corrected, no product change:** the first version called `element.focus()` and read
the computed style. `:focus-visible` — which draws the ring throughout this SPA — matches only
keyboard-initiated focus, so a programmatic focus reported "no ring" for correctly styled controls.
The check now performs a real `Tab` traversal of the whole focus ring.

### 15.3 Critical workflows

All 28 workflow screens are inside the axe sweep; the behavioural spec for each workflow is mapped
in `docs/accessibility/phase-23-release-audit.md` §6.

**Two entries have no implemented surface: tenant switching and branch switching.** There is no
screen, control, route or inventory entry for either — the tenant and assigned branches are
resolved server-side from the caller's membership (`/api/v1/me` returns one `membership` plus
`branch_ids`) and no screen offers a selector. This is recorded as a residual observation for the
product owner (§18), **not** as a registered release gap: Plan §27.3 does not define a switcher, so
building one here would be new feature delivery outside the audit's scope.

### 15.4 Result

| Check | Result |
|---|---|
| axe serious violations (118 screens × 4 combinations) | **0** |
| axe critical violations (118 screens × 4 combinations) | **0** |
| `--grep "axe:"` | **118 passed** (10.7 m) |
| `--grep "accessibility behaviour"` | **6 passed** |
| Defects fixed | PH23-A11Y-001 |

---

## 16. Increment 9 — E2E determinism and flake hardening

### 16.1 Determinism is built in, not asserted afterwards

Every non-deterministic input is pinned at the source:

| Input | Pinned to | Where |
|---|---|---|
| Clock | `2026-07-15T09:00:00.000Z` = 12:00 Africa/Nairobi (a Wednesday) | `page.clock.setFixedTime` in `prepare()` |
| Business date | `2026-07-15` | `AUDIT_BUSINESS_DATE` |
| Future date | `2026-08-12` | `AUDIT_FUTURE_DATE` — never "tomorrow" |
| Identifiers | fixed 26-character constants | `IDS` |
| Session | one stubbed `/api/v1/me` per screen, built from the inventory's own role + permission list | `bootstrapBody()` |
| API responses | ordered fixture registry; every response a pure function of the request | `baseFixtures()` |
| Theme | `localStorage` seeded before navigation | `addInitScript` |

`audit fixtures are deterministic` asserts **inside the browser** that `new Date().toISOString()` is
exactly `2026-07-15T09:00:00.000Z`, so a regression that reintroduces the wall clock fails
immediately rather than intermittently.

`retries` is `0` outside CI (`playwright.config.ts:7`). Every result below is a first-attempt pass —
retry success is never treated as determinism.

### 16.2 Deterministic workflows for the two remediated screens

**Merchant Profile (REM-SCR-002A)** — open → change one authorized field (`town`) → save → assert the
success toast → **reload** → assert the persisted value. The route handler holds the value
server-side, so the reload proves persistence rather than a retained form value. The same test
asserts the long business name renders inside `#main-content` and that no private object path
(`merchants/…/logo`, `s3://`, `/storage/app/`) appears anywhere in the document.

**Branch Calendar (REM-SCR-002B)** — two deterministic paths against one stateful endpoint that
mirrors `BranchCalendarExceptionResource` (derives `closes_branch` from the type, normalizes
`HH:MM:SS` → `HH:MM`):

1. create a **future full-day closure** on the fixed `2026-08-12` → reload → the row shows
   `Special closure` and `Closed all day`;
2. create a **modified-hours** exception → reload → the row shows the normalized `10:00 – 15:30`.

Both assert on the row filtered by date, not on a page-wide text match, so a second row carrying the
same words cannot make the assertion pass or fail by accident. The test also asserts
`AUDIT_FUTURE_DATE > AUDIT_BUSINESS_DATE` against the pinned clock, so "future" is a proven property
rather than an assumption about when the suite runs.

### 16.3 The §14.1 queue / service-session time boundary

Investigated as a determinism question. No failure was found to fix, and nothing was fabricated:

- The one real defect of this class was found and fixed in **Increment 1 — PH23-DET-001**: the
  compensation fixtures built business dates from Laravel's UTC `today()` while the domain decides in
  `Africa/Nairobi`, so 22 tests failed for three hours of every day. It reproduced on pristine
  `origin/main` (**pre-existing**); the fixtures were corrected to a `businessToday()` helper — the
  production rule was not touched, because the product was already right — and
  `CompensationBusinessDateDeterminismTest` pins the clock at `2026-07-26 22:30 UTC` (01:30 Nairobi)
  so the guard holds year-round instead of depending on when the suite runs.
- The scheduling suites (`AppointmentBranchClosureTest`, `QueueBranchClosureTest`) already build
  their dates with an explicit `CarbonImmutable::now('Africa/Nairobi')`, not the UTC helper, so they
  are not exposed to the PH23-DET-001 defect class.
- **`waiting -> called` is structurally impossible without an assignment.**
  `QueueEntryStatus::allowedTransitions()` gives `Waiting` only
  `[Assigned, Transferred, Cancelled, NoShow]`, and
  `tests/Unit/Scheduling/QueueEntryStateMachineTest.php` walks the **entire** status × status matrix
  asserting that exactly the 15 legal pairs are accepted and every other pair — including
  `waiting → called` — is rejected with the 422 `invalid_state_transition` envelope.
- A repository-wide scan of `tests/e2e/*.spec.ts` finds **no** `new Date()`, `Date.now()`,
  `toISOString()`, `waitForTimeout`, `test.setTimeout` or ad-hoc `timeout:` anywhere outside the
  Phase 23 spec's pinned constants. The suite has no wall-clock dependence and no arbitrary sleeps
  to remove.

### 16.4 Results

| Run | Command | Tests | Result | Duration |
|---|---|---|---|---|
| Concurrent | `npx playwright test tests/e2e/phase-23-release-audit.spec.ts --workers=4 --reporter=line` | 367 | **367 passed, 0 failed, 0 flaky** | 7.7 m |
| Repeated serial | `npx playwright test tests/e2e/phase-23-release-audit.spec.ts --workers=1 --repeat-each=3 --reporter=line` | 1 101 | **1 101 passed, 0 failed, 0 flaky** | 34.3 m (17:14:56 → 17:49:15) |
| Full suite | `npm run e2e` | 846 | **846 passed, 0 failed, 0 flaky** | 25.4 m |

Retries used: **0**. Skipped: **0**. Flaky: **0**.

The repeated-serial run was executed in isolation: the Docker dev stack stopped, port 4173 confirmed
free beforehand, ~3.55 GB RAM available, and no other Playwright job active. It owned its own
preview server for the whole run, and no browser process disappeared.

The full-suite result predates the isolated repeated-serial run and remains valid: no product file,
test file, fixture, support utility or Playwright configuration changed after it completed — only
documentation did.

### 16.5 Flake protocol outcome

**No intermittent failure occurred.** Every failure observed during Increments 6–9 was
deterministic and reproducible on demand, and each was classified before being acted on:

| Finding | Classification | Action |
|---|---|---|
| `merchant-profile` / `branch-calendar` overflow at 360 px | product defect | PH23-RSP-001 fixed |
| `audit-event-*`, `audit-finance`, `audit-compensation`, `finance-audit` overflow | product defect (**pre-existing**) | PH23-RSP-002 fixed |
| `aria-controls` → missing tabpanel | product defect (**pre-existing**) | PH23-A11Y-001 fixed |
| `hr-staff-profile`, `front-office-client-detail`, `front-office-queue-detail` blank | **fixture** defect — the default empty-list envelope is not a valid detail payload | real detail fixtures added |
| `unsupported-role` demanded shell navigation | **audit** defect — the fail-safe boundary deliberately has no navigation | harness corrected |
| `Overview` "text matches background" on 5 platform screens | **detector false positive** — `bg-white/15` over the dark brand header was not composited | guard corrected, no product change |
| every control "has no focus ring" | **detector false positive** — `element.focus()` never matches `:focus-visible` | replaced with real `Tab` traversal |
| merchant-profile / branch-calendar determinism assertions | **audit** defect — the stateful route was registered before `prepare()`, so the catch-all outranked it | registration order corrected |

Nothing was papered over: no sleep added, no timeout raised, no retry enabled, no assertion
weakened, no screen or role skipped.

### 16.6 Artifact hygiene

`playwright-report/` and `test-results/` hold no residue after the runs, and `test-results/` is
git-ignored (`.gitignore:222`). `git ls-files` matching
`playwright-report|test-results|trace\.zip|\.webm$` returns exactly one path —
`docs/verification/evidence/test-results.md`, a committed Phase-4/5 evidence document that the broad
filename pattern matches by coincidence. It is **not** a Playwright artifact and was not removed. No
trace, screenshot, video or report is tracked, so no Magic-Link token, TOTP secret, session cookie,
full phone number, private object path, credential or signed URL can reach the repository through
one.

---

## 17. Environment findings (NOT product defects)

Recorded separately because they affected how the evidence above was produced, and because
misclassifying either one as a flake would be false.

### ENV-P23-E2E-001 — memory pressure / OOM during long single-worker runs

**Observed** Two long `--workers=1` Playwright runs stopped progressing with the runner still alive.

**Evidence** No Chromium process existed, the preview server was still serving, the command had not
exited, and output had stopped. Host RAM **8 089 MB**; free memory measured at **1 903 MB** while the
Docker dev stack (10 containers including PostgreSQL, Redis and Meilisearch) was up. Chromium
re-injecting axe-core across hundreds of consecutive tests in a single worker exhausted it.

**Classification** Local resource-pressure/OOM failure. **Not** a product defect, **not** a test
defect, **not** a flake — the identical tests pass repeatedly and concurrently once memory is
available.

**Response** Stopped the Docker dev stack for the isolated Playwright run (the audit needs no
backend — every response is stubbed), confirmed free memory, ran one Playwright workload at a time,
and restarted the stack immediately afterwards for the backend gates. `--repeat-each` was **not**
reduced, no retry was added, and no timeout was raised.

### ENV-P23-E2E-002 — shared preview-server teardown

**Observed** A repeated-serial attempt failed from test 652 onward with
`net::ERR_CONNECTION_REFUSED at http://localhost:4173/…`, 14 identical connection errors and **zero**
assertion failures.

**Evidence** An earlier chained command had continued into `npm run e2e`, which **owned** the
Playwright `webServer` on port 4173. The repeated-serial run reused it via
`reuseExistingServer: true` (`playwright.config.ts`). When the full suite finished it tore its own
preview server down, and every subsequent navigation in the other run was refused.

**Classification** Test-orchestration failure. **Not** a product defect, **not** a test defect,
**not** a flake.

**Response** Confirmed no Playwright or preview process remained, confirmed port 4173 free, confirmed
~3.55 GB RAM available, then reran the repeated-serial proof **unchanged** as the only active job —
1 101 passed. No assertion, timeout, retry or product file was altered. The full-suite run that
caused the teardown was itself green and remains valid; the overlap invalidated the repeated-serial
attempt, not the full-suite result.

---

## 18. Final Phase 23 gate evidence

Every command below was run on this branch, sequentially, with the working tree in its final
pre-commit state. Playwright and the backend suites were never run concurrently (see §17).

### 18.1 Backend

| Gate | Command | Result |
|---|---|---|
| Composer validation | `composer validate --strict` | **PASS** — `./composer.json is valid` |
| Style | `composer pint -- --test` | **PASS** — 1 682 files |
| Static analysis | `composer stan` | **level 8, 0 errors** (1 302 files) |
| Full suite, serial | `php artisan test` | **2 342 passed, 7 skipped, 0 failed** — 14 012 assertions, 1 442.31 s |
| Full suite, parallel | `php artisan test --parallel` | **2 342 passed, 7 skipped, 0 failed** — 14 012 assertions, 597.34 s |

Serial and parallel agree exactly on passed, skipped, failed and assertion counts — no
parallel-only defect, and the suite was not serialized to hide one.

**Targeted proof** — `php artisan test` over
`tests/Feature/{Security,Auth,Traceability,Merchants,Branches,Scheduling,Idempotency,Audit,Tenancy,Isolation,Files,Finance,Search,Integrations,Api}`
and `tests/Unit/Scheduling`: **1 264 passed, 7 skipped, 0 failed** (7 979 assertions, 1 415.75 s).
Every suite the phase directive names executes inside that path set:

| Named suite | Resolved file |
|---|---|
| Phase23 (threat model, protected read, forbidden capability, contract privacy, export hardening) | `tests/Feature/Security/Phase23*.php`, `ProtectedReadAuthorizationCoverageTest` |
| MerchantProfile | `tests/Feature/Merchants/MerchantProfileApiTest.php` |
| BranchCalendar / BranchClosureGuard | `tests/Feature/Branches/{BranchCalendarExceptionApiTest,BranchClosureGuardTest}.php` |
| RouteSecurityContract | `tests/Feature/Security/FileRouteSecurityContractTest.php` |
| FinancialRouteIdempotency | `tests/Feature/Security/FinancialRouteIdempotencyCoverageTest.php` |
| AuditMutationCoverage / AuditSeverityCoverage / AuditExport | `tests/Feature/Audit/*` |
| PermissionMatrix / DatabaseProjection / RoleBoundary / MfaCoverage / StepUpCoverage / LegacyKeyReconciliation | `tests/Feature/Auth/*` |
| Tenant | `tests/Feature/{Tenancy,Isolation}/*` |
| OwnScope | `tests/Feature/Search/SearchServedClientOwnScopeTest.php` |
| MagicLink / SessionRevocation | `tests/Feature/Auth/*` |
| FileUpload / SignedDownload | `tests/Feature/Files/{FileUploadValidationTest,FileSignedUrlExpiryTest,FileDownloadAuthorizationTest}.php` |
| FinanceExport | `tests/Feature/Finance/FinanceExportTest.php` |
| Search | `tests/Feature/Search/*` |
| ReferEarn | `tests/Feature/Integrations/ReferEarn/*` |
| NoDirectProviderIntegration | `tests/Feature/Security/NoDirectProviderIntegrationTest.php` |
| Traceability | `tests/Feature/Traceability/Phase23TraceabilityTest.php` |
| OpenApi | `tests/Feature/Api/OpenApiContractTest.php` |

`ScreenInventory` is a frontend guard (`resources/spa/src/screens/screenInventory.spec.ts`) and is
covered by Vitest below. No filter executing zero tests was used.

### 18.2 Frontend

| Gate | Result |
|---|---|
| `npm run lint` | **0 errors, 138 warnings** — the exact baseline, unchanged |
| `npm run typecheck` (`vue-tsc --noEmit`) | clean |
| `npm run test` (Vitest) | **544 passed / 100 files** |
| `npm run build` | built in 13.94 s |

### 18.3 Playwright

| Run | Result |
|---|---|
| Concurrent, `--workers=4` | **367 passed, 0 failed, 0 flaky** (7.7 m) |
| Repeated serial, `--workers=1 --repeat-each=3` | **1 101 passed, 0 failed, 0 flaky** (34.3 m) |
| Full suite, `npm run e2e` | **846 passed, 0 failed, 0 flaky** (25.4 m) |

Retries 0, skipped 0, flaky 0.

### 18.4 Generator determinism (two passes, byte-identical)

Sequence run twice, in full: `composer api:openapi` → `npm run api:types` →
`artisan servana:permission-types` → `artisan servana:permission-types --check` →
`npm run api:contract:check` → `node scripts/generate-screen-specs.mjs` → the Vitest file-snapshot
generators for `inventory.yaml` and `role-navigation.yaml`.

| Artefact | SHA-256 pass one | SHA-256 pass two | Equal |
|---|---|---|---|
| `docs/api/openapi.json` | `9BEE1E4AE8601655…` | `9BEE1E4AE8601655…` | ✅ |
| `resources/spa/src/types/generated/api.ts` | `C5ECA3BB714C3B84…` | `C5ECA3BB714C3B84…` | ✅ |
| `resources/spa/src/types/generated/permissions.ts` | `48FCBB4589A5F48F…` | `48FCBB4589A5F48F…` | ✅ |
| `docs/frontend/screens/inventory.yaml` | `53F3A607E801B296…` | `53F3A607E801B296…` | ✅ |
| `docs/frontend/navigation/role-navigation.yaml` | `76FD26CF73F91B30…` | `76FD26CF73F91B30…` | ✅ |

Mismatches: **0**. `git diff --check` clean after both passes, and the dirty-path count was
unchanged — the generators are idempotent against the committed state, so no generated file was
hand-edited.

### 18.5 Security and dependencies

| Gate | Result |
|---|---|
| `composer audit --locked` | **No security vulnerability advisories found** |
| `npm audit --audit-level=high` | **0 vulnerabilities** |
| `npm audit` | **0 vulnerabilities** |
| `gitleaks detect --source . --no-git --redact` | **no leaks** (25.44 MB scanned) |
| OpenAPI remote HTTP(S) `$ref` | **0** of 0 total `$ref`s |
| `redocly.yaml` `resolve.http.headers` path | file absent entirely |

No dependency override was removed.

### 18.6 Docker images

| Command | Exit | Duration | Warnings |
|---|---|---|---|
| `docker build -f docker/php.Dockerfile --target dev .` | 0 | 0.5 m | 0 |
| `docker build -f docker/php.Dockerfile --target prod .` | 0 | 2.0 m | 0 |
| `docker build -f docker/nginx.Dockerfile --target prod .` | 0 | 2.7 m | 0 |

Verified by inspection, not assumption:

- **PHP parity** — `php:8.3-fpm-alpine`, matching the pinned PHP 8.3.
- **Node parity** — `node:20-alpine` in the SPA build stage, inside `engines.node >=20 <21`.
- **Lock reproducibility** — `composer install` against the committed `composer.lock`; `npm ci`
  against `package-lock.json`.
- **No secrets in images** — `.dockerignore` excludes `.env`, `.env.*` (keeping only
  `.env.example`), `.git`, `vendor`, `node_modules` and build artifacts, so nothing secret enters
  the build context that `COPY . .` would pick up.
- **Non-root runtime** — the PHP image drops to `USER servana` in both dev and prod; the edge image
  is built on `nginxinc/nginx-unprivileged` and copies with `--chown=nginx:nginx`, exposing 8080.
- **Health / readiness** — compose healthchecks for `app` (`php -v`), `nginx`
  (`/health` spider on 127.0.0.1:8080), `postgres` (`pg_isready`) and `redis` (`redis-cli ping`);
  the live stack reported every service `healthy` before the backend gates ran.

Nothing was deployed.

### 18.7 Disposable PostgreSQL 16 proof

A throwaway database, migrated from nothing, inspected, then dropped. The developer database was
never touched (`migrate:fresh` was scoped to the disposable database by a one-off `DB_DATABASE`
override on a single command).

```text
PostgreSQL 16.14 on x86_64-pc-linux-musl
disposable database  servana_p23_proof_20260727191124   (CREATE DATABASE, exit 0)
php artisan migrate:fresh --seed --force                exit 0
migrations = 118      base_tables = 97      permissions seeded = 132
FORBIDDEN table scan  : (empty)
php artisan audit:verify-chain                          exit 0 ("No audit chains to verify")
DROP DATABASE                                           exit 0
pg_database rows matching the name afterwards           0
developer database migrations afterwards                118  (untouched)
```

The forbidden-table scan checked, and found **none** of: `wallet_webhook_inbox`,
`merchant_wallet_accounts`, `merchant_billing_credits`, `billing_reconciliation_exceptions`,
`subscription_payments`, `subscription_payment_attempts`, `subscription_payment_reversals`,
`reward_ledgers`, `referrer_accounts`, `referrer_payouts`, `referrer_statements`,
`referral_campaigns`, `reward_rules`, `re_inbound_requests`, `re_qualification_decisions`,
`daraja_transactions`, `mpesa_callbacks`, `stk_push_requests`, `personnel_contact_exports`,
`staff_contact_exports` — i.e. no Wallet-owned ledger, no R&E reward-owned table, no direct-provider
table and no personnel-contact-export structure exists.

**No migration was added by Phase 23** — `git status` shows zero paths under
`database/migrations`, and the migration ledger is the same 118 as the developer database. The
migration-justification protocol was therefore never triggered.

Credentials are deliberately absent from this record; only the database NAME is given.

### 18.8 Final counts (measured, not assumed)

| Item | Value | How it was measured |
|---|---|---|
| Routes | **301** | `php artisan route:list --json` |
| OpenAPI | **247 paths / 296 operations** | parsed from the generated `openapi.json` |
| Permission catalogue | **167** | YAML parse of `docs/auth/permission-matrix.yaml` `keys` |
| Permissions active | **132** | same parse, **and independently** `SELECT count(*) FROM permissions` on the freshly seeded disposable database |
| Permissions planned | **35** | same parse (132 + 35 = 167) |
| Legacy-active keys | **7** | `PermissionLegacyKeyReconciliationTest` asserts exactly 7 |
| Screens | **100 implemented + 18 phase_11 + 5 planned = 118 live** | `inventory.json` |
| Specifications | **118** referenced, **118** on disk, **0 orphans** | inventory vs. filesystem walk |
| Registered release gaps | **0** | `screenInventory.spec.ts` |
| Traceability rows | **62** | CSV parse |

**Count adjudication.** An interim reading of "133 active" was reported earlier in this phase. It
was wrong, and the error was mine, not the repository's: a line-based regex over
`permission-matrix.yaml` counted one `implementation_status: active` occurrence outside the `keys`
map. Two independent canonical sources agree on **132**: a structured YAML parse of the `keys` map
(132 + 35 = 167) and the row count in the `permissions` table of a database seeded from scratch.
`PermissionMatrixTest`, `PermissionDatabaseProjectionTest`, `PermissionRoleBoundaryTest`,
`PermissionMfaCoverageTest`, `PermissionStepUpCoverageTest`,
`PermissionLegacyKeyReconciliationTest` and `Phase23PermissionActivationTest` all pass against that
state. **No permission key was added or removed to make a number match.**
