# Phase 16A — Appointments — Proof

**Branch:** `phase-16a-appointments` · **Base commit:** `02f4dc5` (verified Phase
15B merge, PR #25). **Status:** `local_complete` — the full Phase 16A
specification (data dictionary + state-machine spec + screen specs, schema,
state machine, double-booking exclusion, branch-calendar validator, mandatory
Phase 15B `PersonnelSchedulingValidator` integration, domain actions, API,
authorization, Branch Manager read-only surface, Personnel own-scope surface,
audit, branch-closure guard, frontend, tests, contracts, docs) is implemented and
**all local gates pass**. This is **not** `ci_passed`, `merged`, or
`verified_complete`: no PR has been opened and CI has not run. CI remains
authoritative for the Linux browser/Docker gates; local Windows Playwright is not
claimed as a pass (Phase 15B precedent).

Times are branch business time in `Africa/Nairobi`; timestamps are UTC. Frontend
visibility is UX only — the API (AppointmentPolicy + EnsureBranchScope +
EnsurePermission + the scheduling/branch-calendar gates) is the security boundary.
Tests run against PostgreSQL 16 (never SQLite).

---

## Phase 15B lifecycle reconciliation (done first)

Phase 15B shipped as **PR #25**, MERGED into `main`:

- Original implementation commit `93f2e72`; initial CI run `28353377796`
  **failed** — Backend reported Laravel Pint formatting violations in the Phase
  15B scheduling tests, and E2E reported the HR personnel-availability screen
  failing the dark-mode axe contrast test.
- CI remediation commit `4b75eb4` applied Pint-only scheduling-test formatting
  corrections and a precise dark-mode contrast correction in
  `PersonnelAvailability.vue` (no unrelated product capability added).
- Successful pre-governance CI run `28358888303`; final governance/PR head
  `050cca7`; final successful CI run `28359652332` (Backend, Frontend, Docker,
  Security, E2E all SUCCESS); squash merge commit `02f4dc5`.
- `reviewDecision` remained blank under the documented PR-specific solo-maintainer
  governance exception (`docs/governance/solo-maintainer-review-exception-pr-25.md`)
  — **not** independent reviewer approval. Local and remote Phase 15B branches
  were deleted after merge.

Records updated: `docs/PROGRESS.md`, `docs/CHANGELOG.md`,
`docs/proof/phase-15b.md`, `docs/traceability/servana-requirements.csv`
(`SRV-AVAIL-001` → `verified_complete`), and the remediation register
`last_updated` note. **Phase 15B → `verified_complete`.** **REM-PERM-001 remains
open (Phase 19).**

---

## AI Manifesto evidence

### 1. Prove the problem

The active v3 Plan §80 sequences **Phase 16A — Appointments** immediately after
Phase 15B. Repository inspection before implementation proved the appointment
substrate was absent:

```text
NO Appointment model        (grep "class Appointment" app → none)
NO appointment migration    (ls database/migrations | grep appoint → none)
NO appointment routes       (grep appointments routes/api.php → none)
```

`PersonnelSchedulingValidator` (Phase 15B) existed and was **directly tested with
no appointment record** — its data-dictionary handoff bound Phase 16A to invoke
it on every assign/transfer/assigned-create/assigned-reschedule. `BranchClosureGuard`
carried an explicit stub for the appointment blocker (Plan §25.2: "appointment
guards flipped on by Phases 16–18"). Plan §13.7 listed the `appointments` table;
§25.2 the Appointment machine; §19.3 the `appointment.*` / `personnel.my_appointments.view`
keys; §36 the domain; §27 the screens. Omitting Phase 16A would leave the entire
Front Office appointment workflow — the first scheduling aggregate on the 15B
substrate — unbuilt.

### 2. Root cause analysis (one defect found and fixed)

**Bug Fix Protocol — appointment timezone storage:**

```text
Observed problem: A confirmed appointment rescheduled to 14:00 (Africa/Nairobi)
  failed personnel availability ("Personnel is not available") although the
  assignee was available 09:00–17:00; the create end-time assertion also failed.
Evidence: Debug showed the stored interval read back as 17:00–18:00 Nairobi
  (14:00 UTC) instead of 14:00–15:00 Nairobi (11:00 UTC) — a 3-hour shift.
Affected files: app/Domain/Scheduling/Actions/CreateAppointment.php,
  app/Domain/Scheduling/Actions/RescheduleAppointment.php.
Root cause: Eloquent does NOT convert timezones on save; it formats the assigned
  Carbon's wall-clock with the model date format. A Carbon parsed with a +03:00
  offset was stored as its wall-clock ("14:00:00") in a timestamptz column whose
  session timezone is UTC, dropping the offset and shifting the instant by 3h.
Why this is the root cause: the incoming ISO carried the correct instant; only
  the persisted representation was wrong, and only when the wall-clock differed
  from UTC (10:00 create stayed inside 09–17 by luck; 14:00 reschedule exposed it).
Correct fix: normalize the parsed start to UTC (config('app.timezone')='UTC')
  before computing the end and storing — `CarbonImmutable::parse($v)->utc()`.
  Business logic (validators) re-derives Africa/Nairobi from the absolute instant.
Files changed: CreateAppointment.php, RescheduleAppointment.php.
Tests added/updated: AppointmentApiTest "derives the end time from the service
  duration snapshot" (compares instants) + the assign/reschedule/transfer
  workflow; AppointmentSchedulingTest assigned-reschedule availability.
Test command: php artisan test --group=appointments
Test result: 62 passed (231 assertions).
Proof of resolution: workflow + scheduling + audit reschedule tests pass; the
  stored instant round-trips to 14:00 Nairobi.
Remaining risk: none for appointments. Other timestamptz writes use now() (UTC)
  and are unaffected.
```

### 3. Fix with precision

Smallest correct changes only: a new forward-only migration (no shipped migration
edited), a new Scheduling sub-domain (enum, model, factory, state machine,
exceptions, branch-calendar validator, seven actions, a conflict-mapping concern),
reuse of the existing `PersonnelSchedulingValidator` (no eligibility/availability
duplication), reconciliation of the legacy `appointments.manage` permission to the
canonical keys, activation of the appointment blocker in the existing
`BranchClosureGuard`, and the appointment day-close guard wired into the existing
`CloseBranchDay`. No broad rewrites; no frontend fix for a backend concern.

### 4. Test thoroughly · 5. Demonstrate resolution

See the gate table below.

---

## Authoritative decisions (recorded)

1. **State set (resolve §13.7 vs §25.2).** §13.7's schema-summary CHECK lists
   `queued`/`in_service` and omits `cancelled_with_reason`; the active v3
   **Appointment state machine (§25.2)** and the **Phase 16A roadmap (§80)** are
   more specific and control. Phase 16A owns exactly seven states — `scheduled,
   confirmed, checked_in, rescheduled, cancelled, cancelled_with_reason, no_show`
   — and the DB CHECK constrains to them. `queued` (16B) and `in_service` (16C)
   are deferred and added by expand-and-contract in their owning phases.
2. **Columns (resolve §13.7 single `staff_profile_id`).** Realized as the
   authoritative-equivalent `preferred_personnel_staff_profile_id` +
   `assigned_personnel_staff_profile_id` (Scope assigns Front Office both
   preferred-personnel selection and assigned-personnel assignment/transfer; no
   preferred-personnel **fee** in 16A). `scheduled_start/scheduled_end` realized
   as `starts_at`/`ends_at` (`timestamptz`).
3. **Reference.** The appointment ULID is the public id + searchable reference;
   no separate human-readable numbering scheme exists, so none was invented. The
   internal bigint id is never exposed.
4. **No-show authorization.** Authorized through the Front Office
   `appointment.cancel` permission with a distinct `MarkAppointmentNoShow` action,
   `no_show` state, route, and `appointment.no_show` audit event — no new
   permission key (the canonical catalogue defines none).
5. **REM-PERM-001 stays open** (Phase 19 owns the full machine-readable
   permission-matrix closure and per-key parity).

---

## Migration, table, constraints, indexes

- **Migration:** `2026_06_29_000002_create_appointments_table.php` (in
  `docs/architecture/migrations/manifest.yaml`; table + model registered in
  `app/Domain/Tenancy/TenantOwnership.php` BRANCH_OWNED + COMPOSITE_CONSISTENCY +
  MODELS=`branch`). Applies on PostgreSQL 16 (`migrate:fresh --seed` green).
- **Columns:** `id, ulid (unique), merchant_id, branch_id, client_id, service_id,
  preferred_personnel_staff_profile_id (nullable), assigned_personnel_staff_profile_id
  (nullable), starts_at, ends_at (timestamptz), status, cancellation_reason,
  transfer_reason, checked_in_at, cancelled_at, no_show_at, created_by, timestamps`.
- **CHECK constraints (verified via `pg_constraint`):** status ∈ the seven 16A
  states; `starts_at < ends_at`; `checked_in_at` ⟺ status ∈ {checked_in,
  cancelled_with_reason}; `no_show_at` ⟺ no_show; `cancelled_at` ⟺ status ∈
  {cancelled, cancelled_with_reason}; reason required for `cancelled_with_reason`.
- **Exclusion constraint:** `appointments_personnel_no_overlap` —
  `EXCLUDE USING gist (assigned_personnel_staff_profile_id WITH =,
  tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (assigned NOT NULL AND status
  IN ('scheduled','confirmed','checked_in'))`. Back-to-back allowed; overlaps for
  the same personnel rejected; same interval for different personnel allowed;
  unassigned never conflict; maps to **409 `appointment_schedule_conflict`**.
- **Composite FKs:** `(branch_id, merchant_id)→merchant_branches` CASCADE;
  `(client_id, merchant_id)→clients` RESTRICT; `(service_id, merchant_id)→services`
  RESTRICT; `(assigned/preferred personnel, merchant_id)→staff_profiles` RESTRICT;
  `created_by→users` SET NULL.
- **Indexes:** `(merchant_id, branch_id)`, `(branch_id, starts_at, status)`,
  `(client_id, starts_at)`, `(assigned_personnel_staff_profile_id, starts_at)`,
  `(preferred_personnel_staff_profile_id, starts_at)`, plus `UNIQUE (id, merchant_id)`.

## State machine

States (7): `scheduled, confirmed, checked_in, rescheduled, cancelled,
cancelled_with_reason, no_show`. Transitions (Plan §25.2):

```text
scheduled   → confirmed | cancelled
confirmed   → checked_in | rescheduled | cancelled | no_show
checked_in  → cancelled_with_reason
rescheduled → scheduled | confirmed
```

Guarded by `AppointmentStateMachine`; invalid pairs → **422
`invalid_state_transition`**. No generic `PATCH status`. Terminal states immutable.
Spec: `docs/architecture/state-machines/appointment.md`.

## Permission reconciliation

Legacy `appointments.manage` (Phase 8; granted to Branch Manager **and** Front
Office) → canonical §19 keys: `appointment.view/create/reschedule/cancel/
check_in/assign/transfer` (Front Office default grants, branch scope) +
`personnel.my_appointments.view` (Personnel, own scope). **Branch Manager gets
none of the `appointment.*` keys** — read-only visibility via the existing
`branch.dashboard.view`. `PermissionMatrixTest` (DB == registry) green.

## Routes (all `/api/v1`, Sanctum, branch-scoped ULID binding)

```text
GET  /appointments                       appointments.index      (policy viewAny)
POST /appointments                       appointments.store      branch_mutation appointment.create
GET  /appointments/{appointment}         appointments.show       (policy view)
POST /appointments/{appointment}/assign      appointments.assign      branch_mutation appointment.assign
POST /appointments/{appointment}/transfer    appointments.transfer    branch_mutation appointment.transfer
POST /appointments/{appointment}/reschedule  appointments.reschedule  branch_mutation appointment.reschedule
POST /appointments/{appointment}/cancel      appointments.cancel      branch_mutation appointment.cancel
POST /appointments/{appointment}/check-in    appointments.check-in    branch_mutation appointment.check_in
POST /appointments/{appointment}/no-show     appointments.no-show     branch_mutation appointment.cancel
GET  /personnel/me/appointments          personnel.appointments.index  (own-scope; personnel.my_appointments.view)
```

`appointments.check-in` / `appointments.no-show` are reviewed entries in the
`RouteClassification::VALIDATION_EXEMPT` allowlist (no body). `RouteSecurityContractTest`
green.

## Authorization behaviour (proven by AppointmentApiTest)

- Front Office can list/create/assign/transfer/reschedule/cancel/check-in/no-show.
- Branch Manager can read (index/show) via `branch.dashboard.view` but **every**
  mutation is 403.
- HR, Finance, Audit → 403 on mutation; Super Administrator (platform staff) →
  403 (no merchant-operation appointment route).
- Personnel see only their own assigned appointments; cannot mutate.
- Foreign-tenant binding → 404; body-supplied `merchant_id`/`branch_id`/`status`/
  `created_by` ignored (status server-derived `scheduled`); client contact masked;
  public id is a 26-char ULID (no sequential id).

## Branch-calendar validation + Phase 15B validator integration

`AppointmentBranchScheduleValidator` (single source) enforces branch active, full
interval inside operating hours, calendar exceptions
(holiday/special/emergency/modified-hours), no crossing of a closed break,
single business date, `Africa/Nairobi`. Future appointments validate the
appointment date (not the current day); same-day check-in additionally requires
the Branch Day to be operationally open (409 `branch_day_not_open`).
Every create-with-assignment / assign / transfer / assigned-reschedule invokes the
Phase 15B `PersonnelSchedulingValidator::ensure()` — no appointment code
duplicates eligibility/availability (proven by AppointmentSchedulingTest).

## Branch closure

`BranchClosureGuard` now blocks **branch archival** while any active
(scheduled/confirmed/checked_in) appointment exists, and `CloseBranchDay` blocks a
**day close** while a same-day active appointment exists (Plan §25.2 guard flipped
on). Terminal cancelled/no-show never block; another branch/tenant never leaks.

## Audit events

`appointment.created/assigned/transferred/rescheduled/checked_in/cancelled/no_show`
(typed, in `AuditEvent`; `AuditEventCoverageTest` green). One coherent event per
action; safe context only (merchant, branch, appointment/client/service/personnel
ULIDs, state, interval, sanitised reason). No full phone/email, blind index,
tokens, headers, full bodies, or sequential ids. A failed transition writes no
success event.

## Frontend

Front Office: `AppointmentList.vue` (date + status filter, status badge, create
gate), `AppointmentCreate.vue` (client/service/start + server duration preview +
eligible-personnel + scheduling/conflict feedback), `AppointmentDetail.vue`
(capability-map-gated assign/transfer/reschedule/cancel/check-in/no-show dialogs).
Branch Manager: `AppointmentsReadOnly.vue` (no mutation controls). Personnel:
`MyAppointments.vue` (mobile-first own-scope, read-only). `appointmentStore.ts` +
`usePersonnelAppointmentStore`; `utils/appointment.ts` (status labels + Nairobi
ISO). Router: `front-office.appointments[.create|.detail]`, `branch.appointments`,
`personnel.appointments`. Navigation `planned→live` for the three appointment
items; get-started `book-an-appointment` deep-linked to the create screen. Screen
inventory + navigation YAML regenerated (vitest snapshots); per-screen §27.1 specs
generated.

---

## Quality gate results (local)

| Gate | Command | Result |
|---|---|---|
| migrate:fresh --seed | `php artisan migrate:fresh --seed` | appointments migration applied; PermissionSeeder green |
| appointment group | `php artisan test --group=appointments` | **62 passed** (231 assertions) |
| full backend suite | `php artisan test` | **695 passed**, 4 skipped, 0 failed (after OpenAPI regen) |
| Pint | `composer pint -- --test` | PASS (549 files) |
| Larastan L8 | `composer stan` | No errors |
| composer validate | `composer validate --strict` | valid |
| route-security contract | RouteSecurityContractTest | PASS |
| tenant/model/migration coverage | Tenant/Model/Migration coverage tests | PASS |
| permission + role boundaries | PermissionMatrix/AuthorityBoundaries/AuthorizationFreshness | PASS |
| audit-event coverage | AuditEventCoverageTest | PASS |
| OpenAPI gen ×2 + determinism | `composer api:openapi` ×2 | byte-current (OpenApiContractTest green) |
| TS gen + parity | `npm run api:types`, OpenApiTypeParityTest, `api:contract:check` | PASS (62 paths / 76 ops) |
| vue-tsc | `npm run typecheck` | clean |
| ESLint | `npm run lint` | **0 errors** (37 pre-existing warnings elsewhere) |
| Vitest | `npm run test` | **162 passed** |
| SPA build | `npm run build` | built |
| composer audit | `composer audit` | no advisories |
| npm audit (high) | `npm audit --audit-level=high` | 0 high/critical (2 moderate, below gate) |
| Phase 16A Playwright | `tests/e2e/appointments.spec.ts` | authored; **Linux CI authoritative** (local Windows not claimed) |
| gitleaks · Docker images | — | **CI-authoritative** |

### Appointment test breakdown (62)

- `AppointmentSchemaTest` (12): table/columns, TenantOwnership, traits+ULID
  binding, indexes, unique ULID, status CHECK, `starts_at<ends_at`, cross-tenant
  client/service/personnel rejection, raw-SQL composite-consistency bypass.
- `AppointmentStateMachineTest` (5, unit): all valid+invalid pairs, 422 envelope,
  terminal states, reserving states, no queued/in_service.
- `AppointmentConflictTest` (7): overlap reject, back-to-back, different personnel,
  unassigned, cancel/no-show release, DB authoritative when app validation bypassed.
- `AppointmentSchedulingTest` (11): branch hours/closed-weekday/calendar-closure/
  break-crossing/inactive-branch/future-date; validator integration on
  create/assign/transfer/assigned-reschedule.
- `AppointmentApiTest` (16): create (un/assigned), duration snapshot, body-id
  rejection, full workflow, branch-day check-in, cancel/no-show, invalid
  transition, conflict, list masking, Branch Manager read-only + denials, HR/
  Finance/Audit denied, Super Admin no route, Personnel own-scope + denials,
  foreign-tenant 404.
- `AppointmentBranchClosureTest` (7): archival blocked by active/checked-in,
  terminal don't block, no branch/tenant leak, day-close same-day blocked,
  different-day allowed.
- `AppointmentAuditTest` (6): one created event with safe context, assign/check-in/
  no-show distinct, transfer old+new personnel, reschedule old+new interval,
  sanitised cancel reason, no success event on failed transition.

Plus the reconciled `PersonnelSchedulingValidatorTest` (its temporal
`Appointment`-absent guard updated to assert the validator needs no appointment
row — 16A now owns the table).

---

## Skipped work and exact owning phases

```text
walk-in creation / walk_ins / queue_entries / appointment→queue conversion
  / checked_in→queued                                       → 16B
service_sessions / checked_in→in_service / session workflow / preferred-personnel
  execution + fee / commission preview                      → 16C / 20A
invoice creation                                            → 17
payments / receipts / refunds / cash-up                     → 18A/18B
full audit dashboard + flagged events + full permission-matrix closure (REM-PERM-001) → 19
billing state machine + billing mutation gate               → 20A–20E
compensation + payouts                                      → 20F–20H
appointment email/SMS delivery                              → 21N / 21S
cross-domain/fuzzy search                                   → 22
release security/accessibility audit                        → 23
performance optimization                                    → 24
deployment                                                  → 25
```

No walk-in/queue/session/invoice/payment/notification subsystem, no `queued`/
`in_service` state, and no dead navigation/get-started links were introduced.

## Pending PR / CI / review / merge

None opened (branch pushed only; no PR until authorized). CI is authoritative for
the Linux browser/Docker/gitleaks gates. `reviewDecision` will reflect the
documented solo-maintainer governance exception — not independent approval.

## Residual risks

- Local Windows Playwright is not claimed; the Phase 16A E2E spec is verified only
  on Linux CI.
- The Branch Day machine's day-close blocker now includes appointments; existing
  day-close flows without same-day appointments are unaffected (verified).

## Phase 16B handoff

`checked_in → queued` extends this aggregate by expand-and-contract: add `queued`
to the status CHECK, a `ConvertAppointmentToQueue`/queue-entry action, and
`queue_entries.appointment_id`. The DB exclusion already frees the interval on
terminal/non-reserving states. The `BranchClosureGuard` queue stub and the live
personnel-busy projection are 16B/16C.

## Phase 16C handoff

`checked_in → in_service` and `service_sessions` (start/complete/cancel) extend
the aggregate; preferred-personnel execution + fee workflow and the commission
preview are 16C/20A. 16A persists the preferred-personnel ULID (no fee).

## PR #26 Initial CI Remediation - Run 28372954922

The initial PR #26 workflow tested implementation commit
e62da205de0e452b82dcd91d21b6cf88ba60afdd.

Evidence:

- workflow run: 28372954922
- failed job: E2E - Playwright
- failed job ID: 84055148405
- result: 157 passed, 3 failed
- Backend: passed
- Frontend: passed
- Docker: passed
- Security: passed

Initial failed browser checks:

1. Front Office checks a client in from the detail screen.
2. Front Office keeps the status on an invalid transition.
3. Front Office appointment detail is axe-clean in dark mode.

Root causes:

- The broad Playwright route mock for /api/v1/appointments also matched detail
  and action requests. Because the most recently registered matching route took
  precedence, it returned collection data for the detail request. The
  appointment capability map was unavailable and the check-in action did not
  render.
- The appointment detail back link and heading used the non-adaptive
  text-brand-deep token, causing dark-mode contrast failures.
- After those corrections, focused verification exposed an additional genuine
  contrast defect in the shared destructive button. White text on the
  #f87171 error background produced a 2.76:1 contrast ratio instead of the
  required 4.5:1 ratio.

Corrections:

- The broad appointments collection route now falls through for detail and
  action endpoint paths.
- The appointment detail back link and heading now use the adaptive
  text-heading token.
- The shared destructive button now uses bg-red-700 with white text and
  bg-red-800 on hover.
- Playwright timeout values were not increased.
- Accessibility assertions were not weakened.
- No browser test was skipped.

Focused local verification after the complete remediation:

- npm run e2e -- tests/e2e/appointments.spec.ts --workers=1: passed
- npm run typecheck: passed
- npm run lint: completed successfully
- npm run build: passed

Linux PR CI remains the authoritative final Playwright and Docker evidence.
Governance evidence must not be created until all five required PR #26 checks
pass on the remediation commit.
## Solo-Maintainer Review Exception - PR #26

An independent second reviewer was unavailable because the repository currently
has one eligible maintainer. The product owner authorized a PR-specific
governance exception instead of fabricating approval.

Evidence:

- PR: #26
- original implementation commit:
  e62da205de0e452b82dcd91d21b6cf88ba60afdd
- CI remediation commit:
  ce04c73445e61dd590e80e91771f0ddce9394335
- failed initial CI run:
  28372954922
- successful replacement initial CI run:
  28374669729 28372954922
- CI/Backend: passed
- CI/Frontend: passed
- CI/Docker: passed
- CI/Security: passed
- CI/E2E - Playwright: passed
- GitHub reviewDecision: intentionally blank
- governance record:
  docs/governance/solo-maintainer-review-exception-pr-26.md

This exception applies only to PR #26 and is not independent reviewer approval.
