# Phase 16B — Walk-Ins and Queues — Proof

**Branch:** `phase-16b-walk-ins-queues` · **Base commit:** `404fed9` (verified
Phase 16A merge, PR #26). **Status:** `local_complete` (in progress) — this file
records the controlling decisions, conflict resolutions, and the gate evidence as
each slice is verified. This is **not** `ci_passed`, `merged`, or
`verified_complete`: CI is authoritative for the Linux browser/Docker/gitleaks
gates; local Windows Playwright is not claimed as a pass (Phase 15B/16A precedent).

Times are branch business time in `Africa/Nairobi`; timestamps are UTC. Frontend
visibility is UX only — the API (policies + `EnsureBranchScope` +
`EnsurePermission` + the state machine + scheduling/capacity gates) is the security
boundary. Tests run against PostgreSQL 16 (never SQLite).

---

## Phase 16A lifecycle reconciliation (done first)

Phase 16A shipped as **PR #26**, MERGED into `main`:

- Original implementation commit `e62da20`; initial CI run `28372954922`
  **failed** on E2E — Playwright (157 passed / 3 failed): the broad
  `/api/v1/appointments` collection Playwright mock also matched the appointment
  detail and action requests, so `AppointmentDetail` received a collection-shaped
  response and the check-in capability did not render (this also failed the
  invalid-transition browser test), and `AppointmentDetail` used a non-adaptive
  brand-deep text token on dark surfaces, producing a genuine axe color-contrast
  failure.
- CI remediation commit `ce04c73` let the appointment detail/action requests fall
  through to their dedicated mocks and used the adaptive heading/text token on the
  dark surfaces, preserving the axe gate, browser timeout, retries, and business
  behaviour (not classified as an external flake).
- Successful replacement initial run `28374669729`; final governance/PR head
  `794ff85`; final successful CI run `28378639377` (Backend, Frontend, Docker,
  Security, E2E all SUCCESS); squash merge commit `404fed9`.
- `reviewDecision` remained blank under the documented PR-specific solo-maintainer
  governance exception (`docs/governance/solo-maintainer-review-exception-pr-26.md`)
  — **not** independent reviewer approval. Local and remote Phase 16A branches were
  deleted after merge.

Records updated: `docs/PROGRESS.md`, `docs/CHANGELOG.md`,
`docs/proof/phase-16a.md`, `docs/traceability/servana-requirements.csv`
(`SRV-APPT-001` → `verified_complete`), and the remediation register
`last_updated` note (no new remediation item created for a feature-phase merge).
**Phase 16A → `verified_complete`.** **REM-PERM-001 remains open (Phase 19).**

---

## Controlling decisions (recorded before migrations)

1. **Phase ownership.** 16B owns `walk_ins`, `queue_entries`, the
   `checked_in → queued` appointment conversion, the Queue Entry machine, queue
   position integrity, and the walk-in/queue frontend. 16B **may** implement the
   `in_service` and `completed` queue states (they are part of the Queue Entry
   machine) but creates **no** `service_sessions` row, invoice, commission preview,
   or invoice trigger and **no** placeholder `service_sessions` table. 16C later
   extends start/complete transactionally to create/start/complete exactly one
   service session.
2. **Queue Entry state set (authoritative).** Exactly `waiting, assigned, called,
   in_service, completed, transferred, cancelled, no_show` with the exact
   transition table in `docs/architecture/state-machines/queue-entry.md`. Invalid
   transitions → `422 invalid_state_transition`; no generic status endpoint.
3. **Appointment conversion.** `checked_in → queued` added by forward-only expand
   (status added to the enum, DB CHECK, Resource, generated contract). Conversion
   requires `checked_in`, same merchant+branch, creates exactly one queue entry,
   links it, sets the appointment to `queued`, in one transaction under row +
   advisory locks; `UNIQUE (queue_entries.appointment_id)` guarantees one entry per
   appointment (repeat → `409 queue_conversion_exists`). No service session, no
   duplicate client/appointment/walk-in/service.
4. **Walk-in atomicity.** Creating a walk-in atomically creates/attaches a
   branch-scoped client (via the existing Phase 15A client action — no duplicated
   creation/encryption/blind-index/duplicate-detection/masking), references the
   active service, records the assignment intent + optional preferred request,
   creates the walk-in + queue entry + initial position + estimate snapshot + audit
   events. Any failure leaves zero new client/walk-in/queue rows and zero success
   audit events. `walk_ins.client_id` is nullable in schema but no **active** queue
   entry exists without a valid branch-scoped client (no anonymous entries).
5. **Role ownership (PART B corrected Front Office / Branch Manager).**
   - **Front Office** owns operational queue work via `queue.view/create/assign/
     transfer/reorder` + `preferred_personnel.select`. Because the canonical
     catalogue has no separate call/cancel/no-show/start/complete key, those
     lifecycle actions are enforced through `queue.assign` (no invented keys).
   - **Branch Manager** gets branch-scoped **read-only** queue visibility via
     `branch.dashboard.view`, and queue **configuration** (open/close, capacity,
     default assignment mode) via the existing `branch.profile.manage` +
     `day.open_close` — **no** operational entry mutation. The superseded
     operational grants `queue.operate` / `queue.transfer_entries` are **not**
     retained or reactivated for Branch Manager. A direct BM mutation against a
     Front Office queue endpoint is rejected by the backend and recorded through the
     existing unauthorized-attempt audit mechanism.
   - **Personnel** gets `personnel.my_queue.view` (own-scope only — entries
     assigned to the authenticated `staff_profile_id`); no branch-wide view, no
     mutation, no contact export.
   - Merchant Administrator, HR, Finance, Audit get no default queue mutation
     authority; Super Administrator gets no merchant queue-operation route.
6. **Legacy permission reconciliation.** `queue.operate` → removed; replaced by
   `queue.view/create/assign/reorder` (Front Office). `queue.transfer_entries` →
   removed; replaced by `queue.transfer` (Front Office). `queue.configure` → removed
   as an operational super-permission (repository inspection: it is **not** a
   distinct canonical key in the active v3 catalogue beyond the legacy Phase 8
   baseline); its former configuration behaviour is represented by
   `branch.profile.manage` + `day.open_close`. Activated: `queue.view/create/assign/
   transfer/reorder` + `preferred_personnel.select` (Front Office),
   `personnel.my_queue.view` (Personnel own-scope). **REM-PERM-001 stays open**
   (Phase 19 owns full machine-readable matrix closure and per-key parity).
7. **Queue config anchor.** No `queue_configurations` table — three fields added to
   `branch_day_records` (`queue_is_open`, `queue_capacity`,
   `queue_default_assignment_mode`). `effective_queue_open = (day open) AND
   queue_is_open`. Conflicts: `branch_day_not_open`, `queue_closed`,
   `queue_capacity_reached`.
8. **Assignment modes.** Exactly `next_available`, `manual`, `preferred_personnel`.
   Preferred never bypasses HR eligibility/availability; a preferred request may
   stay `waiting`; overriding requires a non-empty reason (audited). 16B records the
   preference + history only — **no** preferred-personnel fee (20A) / invoice
   snapshot (17).
9. **Deterministic next-available selection.** One `NextAvailablePersonnelSelector`
   ordering: (1) lowest count of active assigned/called/in-service queue work; (2)
   earliest last queue assignment; (3) staff-profile ULID as the stable final
   tie-break. Reuses the Phase 15B eligibility/availability services (no
   duplication). Personnel revalidated on assign/transfer/call/start.
10. **Estimated wait.** One `QueueWaitEstimator` with the mandated deterministic
    baseline (see the data dictionary); labelled "Estimate"; zero eligible
    personnel → safe unavailable estimate (no division by zero); manual override
    requires a reason and never overwrites the calculated value (both retained;
    audited). Recalculated after every relevant mutation.

## Conflict resolution (authority hierarchy applied)

- **§13.7 schema-summary vs §25.2/§37 + §80 roadmap (queue states).** The §13.7
  summary is less specific than the §25.2 Queue Entry machine and the §37 Walk-Ins
  & Queues product behaviour. Controlling source: **Scope §37 + Plan §25.2/§80** —
  the eight-state authoritative set is used (not any simplified older list). No
  material business rule remained unresolved.
- **Operational queue keys for Branch Manager (legacy §10.3 baseline vs PART B).**
  The legacy Phase 8 registry granted Branch Manager `queue.operate`/
  `queue.transfer_entries`/`queue.configure`. Controlling source: **Scope PART B
  corrected Front Office/Branch Manager ownership** + Plan §19 canonical catalogue —
  Branch Manager is read-only + config only; operational keys belong to Front
  Office. Recorded here; registry reconciled accordingly.

---

## Quality gate results

_(Filled as each slice is verified; commands + pass/fail/skip counts + initial
failures + root causes + reruns recorded per gate.)_

| Gate | Command | Result |
|---|---|---|
| migrate:fresh (schema) | `docker compose exec app php artisan migrate:fresh` | ✅ all 4 Phase-16B migrations applied on PG16 (`add_queue_fields_to_branch_day_records`, `add_queued_status_to_appointments`, `create_walk_ins_table`, `create_queue_entries_table`) |
| queue_entries constraints | `psql \d queue_entries` | ✅ 13 CHECK constraints (source-XOR, status, assignment-mode, position>0, status↔timestamp coherence, transfer-meta, wait-override pairing); partial-unique `(branch_id,position) WHERE status IN (waiting,assigned,called)`; per-source UNIQUE on `walk_in_id`/`appointment_id` |
| migrate:fresh --seed | `docker compose exec app php artisan migrate:fresh --seed` | ✅ PermissionSeeder green with reconciled registry |
| permission reconciliation | `psql permissions / role_permission_assignments` | ✅ 7 new keys present (`queue.view/create/assign/transfer/reorder`, `preferred_personnel.select`, `personnel.my_queue.view`); 3 legacy keys (`queue.operate/configure/transfer_entries`) **removed**; Branch Manager holds **zero** `queue.*`; Front Office holds all 6 operational keys; Personnel holds `personnel.my_queue.view` |
| queue test group | `php artisan test --group=queue` | ✅ **62 passed** (259 assertions) — schema 11, state-machine 6, position/concurrency/selector 6, capacity/closure 9, assignment 6, estimate 5, audit 6, API 17 |
| full backend suite | `php artisan test` | ✅ **759 passed**, 4 skipped, 0 failed (after permission-matrix fixture + OpenAPI/TS regen) |
| permission matrix (§10.3) | `PermissionMatrixTest` | ✅ independent fixture reconciled (BM loses legacy queue keys; FO gains queue.*; Personnel gains my_queue.view); DB==registry green |
| route-security contract | `RouteSecurityContractTest` | ✅ 15 queue routes classified `branch_mutation`; 4 bodiless (call/start/complete/no-show) in the reviewed VALIDATION_EXEMPT allowlist |
| audit-event coverage | `AuditEventCoverageTest` | ✅ 13 new queue events typed |
| Larastan L8 | `composer stan` | ✅ No errors |
| Pint | `composer pint -- --test` | ✅ clean |
| OpenAPI gen + determinism | `composer api:openapi` | ✅ 91 production routes (+15 queue); OpenApiContractTest green |
| TS gen + parity | `npm run api:types` + OpenApiTypeParityTest | ✅ regenerated; parity green |
| OpenAPI determinism | `composer api:openapi` ×2 | ✅ byte-identical (91 routes) |
| vue-tsc | `npm run typecheck` | ✅ clean |
| ESLint | `npm run lint` | ✅ 0 errors (37 pre-existing warnings elsewhere) |
| Vitest | `npm run test` | ✅ **171 passed** (38 files; +QueueBoard 4, WalkInCreate 1, QueueConfiguration 2, MyQueue 2; reconciled RoleNavigation; regenerated nav + inventory snapshots) |
| SPA build | `npm run build` | ✅ built |
| Phase 16B Playwright | `tests/e2e/queue.spec.ts` | authored (FO board/walk-in/lifecycle/invalid-transition/reorder/closed, BM read-only + config, Personnel own-scope, 360/768/1280, light+dark axe) — **Linux CI authoritative**; local Windows not claimed |
| screen specs + inventory | `node scripts/generate-screen-specs.mjs` | ✅ 6 new §27.1 specs; inventory.json + regenerated inventory.yaml/nav YAML |
| composer audit | `composer audit` | ✅ no advisories |
| npm audit (high) | `npm audit --audit-level=high` | ✅ 0 high/critical (2 moderate, below gate) |
| gitleaks · Docker images | — | **CI-authoritative** |

## Skipped work and exact owning phases

```text
service_sessions / queue↔session coupling / duplicate-active-session / session
  notes+cancellation / commission preview / preferred-personnel execution   → 16C / 20A
preferred-personnel fee rule                                                → 20A
preferred-personnel invoice fee snapshot / invoice creation                 → 17
payments / receipts / refunds / cash-up / period locks                      → 18A/18B
full permission-matrix closure (REM-PERM-001) + flagged events + audit dash → 19
billing mutation gate / compensation / payouts                              → 20A–20H
queue-update notification delivery / report materialized queue KPIs         → 21N
personnel SMS                                                               → 21S
cross-domain/fuzzy search                                                   → 22
release-wide security/accessibility audit                                   → 23
performance/load optimization                                               → 24
deployment                                                                  → 25
```

No service-session/invoice/payment/fee/notification subsystem, no fake fee/session
row, and no dead navigation/get-started link is introduced.

## Pending PR / CI / review / merge

None opened (branch pushed only; no PR until authorized). CI is authoritative for
the Linux browser/Docker/gitleaks gates. `reviewDecision` will reflect the
documented solo-maintainer governance exception — not independent approval.
