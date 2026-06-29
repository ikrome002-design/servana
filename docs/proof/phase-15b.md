# Phase 15B — Personnel Availability and Eligibility Completion — Proof

**Branch:** `phase-15b-personnel-availability` · **Base commit:** `81a5866`
(Phase 15A merge, PR #24). **Status:** `verified_complete` — the full Phase 15B
specification (schema, resolver, reusable scheduling validator, HR API,
Branch-Manager read-only surface, HR frontend, tests, contracts, docs) is
implemented, all local gates passed, and the work merged to `main` as **PR #25**.

**Lifecycle (verified against GitHub):** original implementation commit
`93f2e72`; initial CI run `28353377796` **failed** — Backend reported Laravel
Pint formatting violations in the Phase 15B scheduling tests, and E2E reported
the HR personnel-availability screen failing the dark-mode axe contrast test. CI
remediation commit `4b75eb4` applied Pint-only scheduling-test formatting
corrections and a precise dark-mode contrast correction in
`PersonnelAvailability.vue` (no unrelated product capability added); successful
pre-governance CI run `28358888303`; final governance/PR head `050cca7`; final
successful CI run `28359652332` (Backend, Frontend, Docker, Security, E2E all
SUCCESS); squash merge commit `02f4dc5`. `reviewDecision` remained blank under
the documented PR-specific solo-maintainer governance exception
(`docs/governance/solo-maintainer-review-exception-pr-25.md`) — **not**
independent reviewer approval. The local and remote Phase 15B branches were
deleted after merge. CI remains authoritative for the Linux browser/Docker
gates. **REM-PERM-001 remains open** (Phase 19).

Times are branch business time in `Africa/Nairobi`; timestamps are UTC. Frontend
visibility is UX only — the API (`personnel.availability.manage` + EnsureBranchScope
+ PersonnelAvailabilityPolicy) is the security boundary. Tests run against
PostgreSQL 16 (never SQLite).

---

## Phase 15A lifecycle reconciliation (done first)

Phase 15A shipped as **PR #24**, MERGED into `main`:

```
Phase branch:        phase-15a-services-catalogue-clients
Foundation commit:   73c7d26
Implementation:      23aeed1
Final PR head:       1fcfa40
PR:                  #24  (base main, MERGED 2026-06-28)
Merge commit:        81a5866
Final CI run:        28338582235 (head 1fcfa40) — completed/success
Required CI:         Backend (Pint/Larastan/Pest), Frontend (ESLint/vue-tsc/
                     Vitest/build), Docker (build images), Security (gitleaks),
                     E2E (Playwright) — all SUCCESS
Review:              reviewDecision blank under the documented PR-specific
                     solo-maintainer governance exception — NOT independent approval
```

Promoted on the evidence: **Phase 15A → `verified_complete`**;
**REM-CAT-CLI-001 → `verified_complete`**. **REM-PERM-001 stays open** (Phase 19
owns the full permission-matrix YAML, parity infrastructure, and per-key closure).
Records updated: `docs/PROGRESS.md`, `docs/CHANGELOG.md`,
`docs/proof/phase-15a.md` (lifecycle note prepended; original `local_complete`
history preserved), `docs/remediation/register.yaml`,
`docs/traceability/servana-requirements.csv`.

---

## Controlling source decisions (recorded before implementation)

1. **Table phase ownership.** Plan §13.7 schema summary tags
   `personnel_availability (16A)`, but the §80 roadmap entry for **Phase 15B**
   explicitly assigns *"DB: `personnel_availability`"* while Phase 16A assigns
   *"DB: `appointments`"*. The specific §80 sequencing entry is the controlling
   instruction → **15B creates `personnel_availability`; 16A creates
   `appointments`.** No appointment table is created here.
2. **Scheduling-enforcement boundary.** 15B builds and DIRECTLY tests the reusable
   `PersonnelSchedulingValidator`. Because no appointment/queue/session aggregate
   exists, 15B does **not** claim a production workflow invokes it — only direct
   domain-service tests exercise it. Mandatory invocation is the binding Phase 16A
   handoff (below).
3. **HR authority.** HR owns availability mutation within its resolved
   merchant + branch scope. The Merchant Administrator receives no default Phase
   15B availability mutation permission.
4. **Branch Manager authority.** Branch-scoped, real-time, READ-ONLY visibility via
   the existing canonical `branch.dashboard.view` read key; never any mutation key.
5. **No operational-mode enum / business-reason column.** Canonical §13.7 columns
   only; `change_reason` is a command + audit field (sanitised), not a stored
   column. `busy`/`no_show`/walk-in active-inactive participation and Personnel
   self-toggle are deferred to their owning 16A/16B/16C workflows.
6. **`branch.dashboard.view` activation.** The Plan §19 matrix grants
   `branch.dashboard.view` to the Branch Manager; it was absent from the Phase 8
   baseline registry. 15B activates it (read, non-mutating) for the Branch Manager,
   consistent with the 15A precedent of activating canonical keys for their owners.
   This **contributes to but does not close** REM-PERM-001 (Phase 19).

---

## What was built

### Schema — `personnel_availability` (branch-owned)
Migration `2026_06_29_000001_create_personnel_availability_table.php` (in the
manifest; `TenantOwnership` BRANCH_OWNED + COMPOSITE_CONSISTENCY + MODELS=`branch`):

```
columns: id, merchant_id, branch_id, staff_profile_id, weekday, date,
         start_time, end_time, type, available, created_at, updated_at
```

- **No ulid / no created_by/updated_by / no business-reason / no operational-mode
  column** — exactly the §13.7 canonical column set.
- **CHECK constraints:** `type IN ('recurring','exception')`; polarity
  (recurring ⇒ weekday set + date null; exception ⇒ date set + weekday null);
  `weekday BETWEEN 0 AND 6`; `start_time < end_time` (forbids zero-length AND
  cross-midnight).
- **GiST exclusion constraints (`btree_gist`):** same-polarity overlap rejected per
  staff — recurring `(staff_profile_id, weekday, available, numrange[)) ` and
  exception `(staff_profile_id, date, available, numrange[)) `; opposite-polarity
  break-over-shift permitted (resolved deterministically by the resolver).
- **Composite consistency FKs:** `(branch_id, merchant_id) → merchant_branches`
  CASCADE; `(staff_profile_id, merchant_id) → staff_profiles` RESTRICT — same
  merchant guaranteed in DB; same branch set by the action from
  `staff.primary_branch_id`.
- **Indexes:** `(merchant_id, branch_id)`, `(staff_profile_id, weekday)`,
  `(staff_profile_id, date)`.
- Model `PersonnelAvailability` (BelongsToMerchant + BelongsToBranch, casts);
  `AvailabilityType`, `PersonnelAvailabilityState` enums; `PersonnelAvailabilityFactory`.

### Availability resolver (single deterministic source)
`AvailabilityResolver` — exception beats recurring where they overlap; unavailable
beats available within a layer; half-open `[start, end)`; `Africa/Nairobi`
weekday/date. `isIntervalAvailable()` (scheduling gate) and `currentState()`
(`suspended | available | on_break | unavailable | offline`; `suspended` from
`is_active`, never rows; `busy` deferred to 16B/16C). No availability logic is
duplicated anywhere else.

### Reusable scheduling validator (Phase 16A gate)
`PersonnelSchedulingValidator::validate()/ensure()` checks interval validity (single
business date), merchant scope, branch scope, staff lifecycle, active branch
assignment, service status, service scope, active `service_personnel_eligibility`,
and effective availability. Returns a typed `SchedulingDecision` or throws
`SchedulingValidationException` (422 envelope). Stable safe codes:
`invalid_schedule_window`, `personnel_inactive`, `personnel_wrong_branch`,
`personnel_not_eligible`, `personnel_unavailable`, `service_inactive` — no internal
ids, no cross-tenant existence leak. It does **not** validate branch hours/calendar,
appointment overlap, branch-day open, queue capacity, or session conflicts (later
phases).

### Permissions
- Legacy `availability.manage` → canonical **`personnel.availability.manage`**
  (HR-only default grant). `personnel.eligibility.manage` preserved (HR-only).
- **`branch.dashboard.view`** activated (Branch Manager; read-only).
- `PermissionMatrixTest` (DB == registry) green; `docs/proof/phase8-matrix.txt`
  regenerated by the test. **REM-PERM-001 stays open.**

### Backend / API
Form Request → policy + permission → thin controller → transactional action →
Resource:

```
GET  /api/v1/staff/{staff}/availability                       staff.availability.show
PUT  /api/v1/staff/{staff}/availability                       staff.availability.update    (branch_mutation)
POST /api/v1/staff/{staff}/availability/emergency-unavailable  staff.availability.emergency-unavailable (branch_mutation)
```

- `ReplaceAvailability` — atomic replace under a `lockForUpdate` staff anchor;
  `ScheduleNormalizer` validates (defence over DB CHECK/exclusion); delete + insert
  in one transaction; exactly one `personnel_availability.updated` audit event
  (not one per row); idempotent set-state.
- `EmergencyUnavailable` — transactional date-specific unavailable exception
  (create-or-replace overlapping same-polarity rows); one
  `personnel_availability.emergency_unavailable` audit event.
- Reads expose staff ULID + safe display, recurring/exception rows, derived current
  state, active eligible services (ulid + name), `can.update`, and the branch
  timezone — never sequential ids, contact data, permission/audit internals, the
  change reason, or another branch's schedule. Mutations are `branch_mutation`
  (Sanctum + ResolveTenantContext + EnsureBranchScope + EnsurePermission), in the
  route-classification registry, OpenAPI, and the generated TypeScript contract.
  merchant_id/branch_id are never accepted from the body.

### Audit + redaction
`personnel_availability.updated` (Notice) and `personnel_availability.emergency_unavailable`
(Warning) carry only safe context (merchant, branch, staff ULID, actor, counts,
effective interval/date, sanitised reason). The reason is length-capped and passed
through the `Redactor` (emails/phones masked) before storage; the API never returns
it.

### Frontend
- `pages/hr/PersonnelAvailability.vue` (BranchLayout, `personnel.availability.manage`):
  branch-scoped personnel selector, identity + lifecycle, derived current state,
  eligible-services summary + link to `hr.eligibility`, weekly editor (multiple
  working intervals per weekday = split shifts), recurring break editor,
  date-exception editor (one-off available/unavailable), day-off action,
  emergency-unavailable modal, required change-reason, unsaved-changes guard
  (`onBeforeRouteLeave`), validation summary (server `error.fields`), atomic save,
  success/loading/empty/error/no-permission/no-branch states, visible `Africa/Nairobi`.
- `pages/branch/PersonnelSchedule.vue` (Branch Manager READ-ONLY): current state,
  today's working intervals/breaks/temporary unavailability (Nairobi), weekly
  schedule, eligible services — **no** edit/save/emergency/eligibility/replacement
  controls; the backend rejects BM mutation regardless of UI.
- Store `availabilityStore.ts`; route `hr.availability` (+ `branch.personnel-schedule`);
  navigation `hr.availability` planned→live + new `branch.personnel-schedule` live;
  get-started `set-availability` deep-linked to `/hr/availability`; navigation
  fixture + screen inventory(+yaml) + 2 §27.1 specs regenerated; OpenAPI + TS regen.

---

## Tests and gate results (all local, PostgreSQL 16)

### Backend (Pest, parallel, PostgreSQL)
- `tests/Feature/Scheduling/PersonnelAvailabilitySchemaTest` (16) — migration +
  manifest + TenantOwnership + traits + indexes; CHECK polarity/weekday/interval;
  GiST same-polarity overlap (recurring + exception); opposite-polarity break
  permitted; back-to-back half-open permitted; cross-tenant staff + branch composite
  FK rejection — all via raw-SQL bypass of Eloquent.
- `AvailabilityResolverTest` (16) — contained interval; before/after/over-shift;
  half-open end boundary; split shifts + gap; break overlap + immediately-after;
  date-unavailable override; partial-date block; date-available add; exact-date
  unavailable-wins; offline/day-off; emergency immediate; current state
  available/on_break/unavailable/offline/suspended; `Africa/Nairobi` determinism;
  cross-midnight rejection.
- `PersonnelSchedulingValidatorTest` (11) — pass; missing/inactive eligibility;
  unavailable interval; inactive/suspended staff; inactive service; wrong-tenant
  neutral (no leak); wrong-branch; invalid window; `ensure()` throws; runs with no
  appointment/queue/session record.
- `PersonnelAvailabilityApiTest` (18) — HR replace + read (safe fields, no change
  reason / blind index); one audit event per replace (not per row); emergency +
  audit; required reason; reason redaction (phone/email masked); Branch Manager
  read-only + can.update false + 403 on mutate/emergency; non-HR roles (front_office/
  finance/personnel/audit) 403; Merchant Admin 403; foreign-tenant 404;
  same-tenant out-of-branch 403 (documented binding posture); atomic full replace;
  invalid-row rollback preserves old schedule; idempotent; body merchant_id/branch_id
  ignored; eligible-services read; no platform/super-admin availability route.
- Reconciled auth: `PermissionMatrixTest`, `AuthorityBoundariesTest` (canonical key).
- **Full parallel backend suite: `635 passed / 4 skipped / 0 failed` (2752
  assertions, 4 processes)** — 573 from Phase 15A + 62 new Phase 15B scheduling
  tests, with the reconciled auth + OpenAPI contract tests green.

### Frontend (Vitest)
- `PersonnelAvailability.spec.ts` (12) + `PersonnelSchedule.spec.ts` (3): load/empty/
  no-permission/no-branch; selection; split shift + break; day off; exception;
  required reason (save disabled); atomic save; server validation summary; emergency
  submit; BM read-only renders + NO edit/save/emergency controls.
- Full Vitest suite: **157 passed** (was 142 at 15A; +15). `roleNavigation` and
  `screenInventory` snapshots regenerated and green.

### Playwright E2E (`tests/e2e/personnel-availability.spec.ts`)
HR opens `/hr/availability`, selects personnel, edits split shifts + break +
exception, saves; reload shows persisted; emergency changes derived state;
unauthorized role blocked; Branch Manager read-only with no edit controls;
360/768/1280 no horizontal overflow; light + dark axe-clean (no serious/critical).
**Linux CI is the authoritative browser gate — the local Windows Playwright run is
not claimed as a pass** (established Phase 10/11/15A precedent).

### Contracts / quality gates
- `composer api:openapi` → 66 production routes (+3); regeneration byte-deterministic;
  `npm run api:types`; `OpenApiContractTest` + `OpenApiTypeParityTest` green.
- Pint clean; Larastan level 8 **No errors**; `RouteSecurityContractTest`,
  `MigrationManifestTest`, `TenantColumnCoverageTest`, `ModelTenancyTraitCoverageTest`
  green. `vue-tsc` clean; ESLint **0 errors**; production SPA build OK.
- Migration applies cleanly on PostgreSQL 16 (`php artisan migrate`).

---

## Initial failures → root cause → correction (this phase)

1. **Larastan (7):** `SchedulingValidationException::$code` redeclared
   `Exception::$code` (int) → renamed promoted property to `errorCode`. Carbon
   `createFromFormat` null/false narrowing in `ScheduleNormalizer` → `instanceof`
   guard + catch `InvalidArgumentException`. Controller redundant `is_array`,
   union/`list` return shapes → simplified annotations. Rerun: **No errors**.
2. **Resolver/validator/schema tests:** `StaffProfile::factory()` builds an
   unaligned merchant/branch (branch under a different merchant), violating the
   composite FK → test helpers now create a same-merchant branch (and
   `branchStaff()` for personnel with an active branch assignment). Rerun green.
3. **`validator()` helper collided with Laravel's global `validator()`** → renamed
   to `schedulingValidator()`.
4. **Branch Manager read 403:** the Plan §19 read key `branch.dashboard.view` was
   absent from the Phase 8 baseline registry → activated it (Decision 6) + updated
   `PermissionMatrixTest`. **Same-tenant out-of-branch is 403, not 404** — route
   binding intentionally removes `BranchScope` (`BelongsToMerchant::resolveRouteBinding`),
   so branch authority is a policy 403 while cross-merchant remains a 404; the test
   asserts the established posture.
5. **OpenAPI/TS contract stale** after adding routes → `composer api:openapi` +
   `npm run api:types` regenerated; determinism re-verified.

---

## Skipped work and exact owning phase

```
appointments + state machine + overlap exclusion + assign/transfer/no-show  -> 16A
branch-open / branch-calendar appointment gate                              -> 16A
walk-ins / queue entries / active-inactive participation / queue transfer   -> 16B
live busy state                                                             -> 16B/16C
Personnel operational self-toggle                                          -> owning 16 workflow
service sessions / preferred-personnel execution                           -> 16C
invoicing / payments / receipts / refunds / cash-up / period locks         -> 17 / 18A / 18B
full audit dashboard + flagged events                                      -> 19
complete permission-matrix YAML + parity + per-key closure (REM-PERM-001)   -> 19
billing state machine + billing mutation gate                              -> 20A–20E
compensation / payouts                                                     -> 20F–20H
notifications / reports / personnel SMS                                     -> 21N / 21S
cross-domain search                                                        -> 22
release-wide security / responsive / dark / a11y audit                     -> 23
performance optimization                                                   -> 24
deployment                                                                 -> 25
```

No fake tables, placeholder routes, dead navigation links, speculative buttons,
unsupported statuses, or test-only production endpoints were added for deferred
phases.

---

## Binding Phase 16A handoff

> Every Phase 16A appointment creation, assignment, transfer, and rescheduling
> action MUST invoke the Phase 15B `PersonnelSchedulingValidator`. Appointment
> controllers and actions MUST NOT duplicate eligibility or availability logic.
> Phase 16A must then add branch-open, branch-calendar, and appointment-conflict
> validation AROUND that shared gate. No appointment assignment or transfer may
> bypass the validator.

---

## Residual risks

- The derived `current_state` (`on_break` vs `unavailable`) distinguishes a
  recurring break within a recurring working window (OnBreak) from any other
  unavailable interval (Unavailable); this is deterministic and test-pinned but is
  a presentation choice, not a scheduling-gate input.
- `branch.dashboard.view` is activated for the Branch Manager only; the full
  canonical matrix reconciliation remains Phase 19 (REM-PERM-001 open).
- Local Windows Playwright is not run to completion; Linux CI is authoritative for
  the browser/Docker gates.
- The HR landing chunk size warning is a pre-existing Phase 24 performance item
  (per-role lazy content split), unchanged by this phase.

---

## Pending PR / CI / review / merge work

None opened — the phase branch is pushed only; no PR until separately authorized.
CI remains authoritative for the Linux browser and Docker results. Phase 16A stays
**Not started**.

## Solo-Maintainer Review Exception - PR #25

An independent second reviewer was unavailable because the repository currently
has one eligible maintainer. The product owner authorized a PR-specific
governance exception instead of fabricating approval.

Evidence:

- PR: #25
- original implementation commit:
  93f2e728c2db6aa6e386ae1a0ebb1abd1cf68979
- verified remediated PR head:
  4b75eb4de9d26d3ea21993da5f132c6695fc25e4
- successful pre-governance CI run:
  28358888303
- CI/Backend: passed
- CI/Frontend: passed
- CI/Docker: passed
- CI/Security: passed
- CI/E2E - Playwright: passed
- GitHub reviewDecision: intentionally blank
- governance record:
  docs/governance/solo-maintainer-review-exception-pr-25.md

This exception applies only to PR #25 and is not independent reviewer approval.
