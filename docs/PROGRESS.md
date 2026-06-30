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

### Pre-feature remediation (Plan §79) — gate §5.4 **CLOSED and effective** (gate-closure PR #20 merged `7ac20a5`)
| Phase | Title | Status | Register item |
|---|---|---|---|
| V | As-built verification | ✅ `verified_complete` — PR #12, commit `c58b64a` (CI Backend/Frontend/Docker/Security all SUCCESS; solo-maintainer governance exception, reviewDecision blank) | REM-V-001, REM-DOC-001 |
| R1 | Dependency & runtime security (Laravel 12.60+, PHP 8.3, advisory removal, CR/LF) | ✅ `verified_complete` — PR #13, commit `8fe575f` (CI passed; solo-maintainer governance exception, reviewDecision blank) | REM-DEP-001 |
| R2 | Core audit completeness + chain verifier + masked read | ✅ `verified_complete` — PR #14, commit `1df759e` (CI Backend/Frontend/Security/Docker passed; solo-maintainer governance exception, reviewDecision blank) | REM-AUD-001 |
| R3 | Privileged MFA + step-up | ✅ `verified_complete` — PR #15, commit `c0402b2` (CI Backend/Frontend/Security/Docker passed; solo-maintainer governance exception, reviewDecision blank) | REM-MFA-001 |
| R4 | Idempotency & replay protection | ✅ `verified_complete` — PR #16, commit `1288f48` (CI Backend/Frontend/Security/Docker passed; solo-maintainer governance exception, reviewDecision blank) | REM-IDEMP-001 |
| R5 | Tenant/branch schema hardening (`merchant_id` on branch tables) | ✅ `verified_complete` — PR #17, commit `66aaead` (CI Backend/Frontend/Security passed; CI/Docker reran past an external Buildx/Docker Hub timeout with no code change; solo-maintainer governance exception, reviewDecision blank) | REM-TEN-001 |
| R6 | Session & authorization revocation (per-request freshness) | ✅ `verified_complete` — PR #18, commit `57ae8db` (CI Backend/Frontend/Docker/Security all SUCCESS; solo-maintainer governance exception, reviewDecision blank) | REM-SESS-001 |
| R7 | Production probes, CI isolation, env parity, ADR-009 | ✅ `verified_complete` — PR #19, commit `4f0d4f3` (CI Backend/Frontend/Docker/Security all SUCCESS; solo-maintainer governance exception, reviewDecision blank) | REM-OPS-001 |

> **Pre-feature remediation gate (§5.4): CLOSED and effective** — the gate-closure
> PR #20 merged into `main` (merge commit `7ac20a5`, 2026-06-23; CI Backend/
> Frontend/Docker/Security all SUCCESS; reviewDecision intentionally blank under
> the solo-maintainer governance exception — not an independent approval).
> V and R1–R7 are `verified_complete`; all nine PRE_FEATURE_REMEDIATION items are
> `verified_complete`. **Phase 10 (API Foundation) is `verified_complete`** (PR #21,
> `4f761ff`); **Phase 10F (File & Media Foundation) is `verified_complete`** (PR #22,
> merge `9b493e6`); **Phase 11 (UI Layout & Role Navigation) is `verified_complete`**
> (PR #23 MERGED 2026-06-28, final pre-merge head `44cebdf`, merge commit `d098f37`, CI run
> `28314638091` — five required checks SUCCESS; reviewDecision blank under the solo-maintainer
> governance exception — not an independent approval).
> See `docs/remediation/pre-feature-completion-report.md`,
> `docs/proof/pre-feature-remediation-gate-closure.md`, and
> `docs/governance/solo-maintainer-pre-feature-gate-closure-exception.md`.

### Feature roadmap (Plan §80) — gate §5.4 closed; roadmap in progress
| Phase | Title | Status |
|---|---|---|
| 10 | API foundation (Corrections 10–12) | ✅ `verified_complete` — PR #21, commit `4f761ff` (CI Backend/Frontend/Docker/Security/E2E—Playwright all SUCCESS; solo-maintainer governance exception, reviewDecision blank) (REM-ROUTE-001, REM-MIG-001) |
| 10F | File & media foundation | ✅ `verified_complete` — PR #22, merge commit `9b493e6` (CI Backend/Frontend/Docker/Security/E2E—Playwright all SUCCESS; genuine ClamAV EICAR CI test passed without skipping; solo-maintainer governance exception, reviewDecision intentionally blank) (REM-FILE-001 `verified_complete`) |
| 11 | UI layout foundation & role navigation | ✅ `verified_complete` — PR #23 MERGED (base `main`), final pre-merge head `44cebdf`, merge commit `d098f37`; five required checks (Backend, Frontend, Docker, Security, E2E — Playwright) SUCCESS on CI run `28314638091`; reviewDecision blank (solo-maintainer governance exception, not independent review) → REM-SCR-001 promoted to `verified_complete` (Phase 11 substrate) |
| 15A | Services, catalogue, clients | ✅ `verified_complete` — PR #24 MERGED into `main` (merge commit `81a5866`, 2026-06-28; final PR head `1fcfa40`); CI run `28338582235` — five required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS; reviewDecision blank under the solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-24.md`) — not independent approval. REM-CAT-CLI-001 → `verified_complete`. See Phase 15A section. |
| 15B | Personnel availability | ✅ `verified_complete` — PR #25 MERGED into `main` (squash merge commit `02f4dc5`, 2026-06-29; original implementation `93f2e72`, CI remediation `4b75eb4`, final pre-merge governance head `050cca7`). CI run `28359652332` (head `050cca7`) — five required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS. reviewDecision blank under the documented PR-specific solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-25.md`) — not independent approval. **REM-PERM-001 remains open** (Phase 19). See Phase 15B section. |
| 16A | Appointments | ✅ `verified_complete` — PR #26 MERGED into `main` (squash merge commit `404fed9`, 2026-06-29; original implementation `e62da20`, CI remediation `ce04c73`, final pre-merge governance head `794ff85`). CI run `28378639377` (head `794ff85`) — five required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS. reviewDecision blank under the documented PR-specific solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-26.md`) — not independent approval. **REM-PERM-001 remains open** (Phase 19). See Phase 16A section. |
| 16B | Walk-ins & queues | ✅ `verified_complete` — PR #27 MERGED into `main` (squash merge commit `af79b56`, 2026-06-30; original implementation `6a9fbcc`, final head `6272f080`). Initial CI run `28420643751` FAILED Backend (8 failed/4 skipped/751 passed: `createWalkIn()` undefined — file-local Pest helper not reliable across independent parallel workers), corrected by moving the helper to `tests/Pest.php`; final CI run `28425875550` five required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS. reviewDecision blank under the documented solo-maintainer governance exception — not independent approval. **REM-PERM-001 remains open** (Phase 19). See Phase 16B section. |
| 16C | Service sessions | 🔄 `local_complete` (in progress) — `service_sessions` + 4-state Service Session machine + queue↔session coupling + duplicate-active protection + preferred-personnel execution + non-payable commission preview; branch `phase-16c-service-sessions`, base `af79b56` (verified Phase 16B merge, PR #27). See Phase 16C section. |
| 17 | Invoicing | ⬜ Not started |
| 18A / 18B | Payment recording / validation, receipts, refunds, cash-up, period locks | ⬜ Not started |
| 19 | Audit logging completion & flagged events | ⬜ Not started |
| 20A–20H | Plans/prices, subscriptions, promotions, M-Pesa, %-fee engine, compensation, payouts | ⬜ Not started |
| 21N / 21S | Queues/notifications/reports / personnel bulk SMS | ⬜ Not started |
| 22 | Search | ⬜ Not started |
| 23 | Security hardening + responsive/dark/a11y release audit + threat-model | ⬜ Not started |
| 24 | Performance optimization | ⬜ Not started |
| 25 | Deployment pipeline & production readiness | ⬜ Not started |

## Phase 16C — Service Sessions and Preferred Personnel (local_complete)

1. **Branch:** `phase-16c-service-sessions`. **2. Base:** `af79b56` (verified Phase 16B merge, PR #27).
3. **Lifecycle status:** 🔄 `local_complete` — full Phase 16C built; all local gates green. **Not** `ci_passed`/`merged`/`verified_complete` (no PR/CI). **Proof:** [docs/proof/phase-16c.md](proof/phase-16c.md).
4. **Proof / specs:** [phase-16c.md](proof/phase-16c.md); data dictionary [services-clients-scheduling.md](architecture/data-dictionary/services-clients-scheduling.md); state machine [service-session.md](architecture/state-machines/service-session.md).
5. **Phase 16B reconciliation:** PR #27 MERGED `af79b56` (impl `6a9fbcc`, final head `6272f080`); initial CI run `28420643751` FAILED Backend (`createWalkIn()` undefined — file-local Pest helper not reliable across parallel workers), corrected by moving the helper to `tests/Pest.php`; final CI run `28425875550` five required checks all SUCCESS; promoted to `verified_complete` across PROGRESS/CHANGELOG/proof-16b/traceability + register `last_updated` (no new remediation item); REM-PERM-001 kept open (Phase 19).
6. **Specification conflicts & resolutions:** (A) **session source** — no `appointment_id`; every session links via `queue_entry_id`; appointment provenance via `queue_entries.appointment_id` (authoritative appointment state machine defers `in_service`/`completed` to Queue Entry/Service Session; no direct appointment route). (B) **service identity** — immutable `service_id` snapshotted from the locked source queue entry (data dictionary authorises it; DB-safe consistency). (C) **cancellation coupling** — `in_progress → cancelled` defined + unit-tested, but the cancel action/route refuses a queue-linked in-progress session (`409 service_session_in_progress`) because the Queue Entry machine has no `in_service → cancelled`; workflow in-progress abort deferred to a future queue-machine extension. (D) **commission preview** — typed `CommissionPreviewResult` = `not_configured` (no compensation tables yet); never earned/payable/zero/ledger.
7. **Migration (1, forward-only):** `2026_06_30_000001_create_service_sessions_table` (in manifest).
8. **Table / final columns:** `service_sessions` (branch-owned, ULID) — `id, ulid, merchant_id, branch_id, queue_entry_id (nullable), client_id, service_id, staff_profile_id, status, started_at, completed_at, cancelled_at, cancellation_reason, notes, preferred_personnel_honored, created_by, created_at, updated_at`. **No** `appointment_id`.
9. **Constraints & indexes:** status CHECK (4 states); status↔timestamp coherence (started_at for in_progress/completed; completed_at iff completed; cancelled_at iff cancelled; cancellation_reason for cancelled; completed⇒started); **partial-unique** `(staff_profile_id) WHERE status IN (pending,in_progress)` (duplicate-active); `UNIQUE (queue_entry_id)` (one session per entry); `UNIQUE (ulid)`; `UNIQUE (id,merchant_id)` (Phase-17 FK target); indexes `(merchant_id,branch_id)`/`(branch_id,status)`/`(staff_profile_id,status)`/`client_id`; composite-merchant FKs to merchant_branches CASCADE + queue_entries/clients/services/staff_profiles RESTRICT.
10. **Session source model:** always queue-originated (`queue_entry_id`); client/service/branch/merchant derived from the locked source in-transaction.
11. **State set:** `pending, in_progress, completed, cancelled`.
12. **Transition table:** pending→in_progress|cancelled; in_progress→completed|cancelled. Invalid → 422 `invalid_state_transition` (`ServiceSessionStateMachine`); terminal immutable; no generic `PATCH status`.
13. **Queue/session coupling:** `StartQueueEntry` (called→in_service) atomically creates+starts one session (pending→in_progress); `CompleteQueueEntry` (in_service→completed) completes the session + yields the non-payable preview. Both lock position + entry, reuse `QueuePersonnelAssignmentValidator`→15B `PersonnelSchedulingValidator` (no duplication), and roll back queue+session+audit together on failure.
14. **Appointment/session coupling:** none added (Gate A); appointment provenance flows through the 16B `checked_in→queued` path; no appointment `in_service`/`completed` state, no direct route.
15. **Cancellation coupling:** `CancelServiceSession` — pending cancellation (reason required); queue-linked in-progress refused `409 service_session_in_progress` (Gate C deferral).
16. **Duplicate-active protection:** DB partial-unique (concurrency authority) + `DuplicateActiveSessionGuard` friendly pre-check; collision → `409 duplicate_active_service_session` (no SQLSTATE/constraint leak); `UNIQUE(queue_entry_id)` also blocks a duplicate per source.
17. **Service eligibility:** enforced at start via the reused queue assignment validator (active service-personnel eligibility for the performed service).
18. **Branch assignment:** enforced at start (active branch assignment) via the same reused validator.
19. **Preferred-personnel execution:** `PreferredPersonnelExecutionValidator` resolves honoured/overridden evidence (`preferred_personnel_honored`); never bypasses eligibility; override requires the reason already recorded at queue-assign; **no fee** calculated or stored.
20. **Preview contract:** `CommissionPreviewResult` (`preview_status` ∈ available/not_applicable/not_configured/unavailable; earned=false; payable=false; amount only with authoritative config — none yet → `not_configured`); never a ledger/rule/plan; frontend wording "Preview — not earned or payable".
21. **Permissions:** reconciled legacy `sessions.manage` → canonical `service_session.view/start/complete/cancel` (Front Office) + `personnel.my_sessions.view` (Personnel). Branch Manager session grant **removed** (no session authority). REM-PERM-001 stays open.
22. **Routes (5 new + 2 extended):** queue `start`/`complete` now also require `service_session.start`/`service_session.complete`; `GET service-sessions`, `GET service-sessions/{serviceSession}`, `POST service-sessions/{serviceSession}/cancel`, `PATCH service-sessions/{serviceSession}/notes`, `GET personnel/me/sessions`. Mutations `branch_mutation`; cancel/notes carry Form Requests.
23. **Audit events (3):** `service_session.started` (info), `service_session.completed` (info), `service_session.cancelled` (warning) — safe context only (session/queue-entry/client/service/personnel ULIDs, prev/new state, preferred-honoured flag, sanitised reason); no contact/secret/bigint; no success event on rollback.
24. **Branch closure:** `BranchClosureGuard::hasInProgressSessions` enforced — active (pending/in_progress) sessions block archival + day close; terminal don't; no cross-branch/tenant leak.
25. **Busy projection:** `PersonnelStateProjector` overlays derived `Busy` (new `PersonnelAvailabilityState::Busy`) when a personnel member has an in_progress session; cleared on completion; surfaced by the availability read; never stored, not toggle-overridable.
26. **Frontend screens:** Front Office `ServiceSessionList.vue` (list + cancel/notes dialogs + completion preview wording); Personnel mobile-first `MyServiceSessions.vue` (own-scope, no preview, no mutation); `serviceSessionStore`/`usePersonnelServiceSessionStore`; nav/router/inventory updated; queue board drives start/complete (session surfaced in the response).
27. **Backend test totals:** service-session group **56** (schema/duplicate-active 13, state machine 22, coupling 8, API/authz/isolation/own-scope 9, branch-closure/busy 7?, audit 4 — across 6 files: Schema/StateMachine/Coupling/Api/BranchClosure/Audit). Full backend parallel suite: see proof (all green locally).
28. **Frontend test totals:** Vitest 16C +12 (ServiceSessionList 4, MyServiceSessions 3, serviceSession util 5); full vitest **183 pass**; nav + inventory snapshots regenerated.
29. **Playwright totals:** `tests/e2e/service-session.spec.ts` (FO preview wording, light+dark axe, 360px no-overflow; Personnel own-scope) — Linux CI authoritative (local Windows not claimed).
30. **Quality gates:** Pint clean; Larastan L8 clean; OpenAPI deterministic (96 routes; byte-identical on regenerate); TS contract parity OK (81 paths, 96 ops); vue-tsc clean; ESLint 0 errors; vitest 183; production build OK; migrate verified on PostgreSQL 16; coverage/contract tests (RouteSecurity/TenantColumn/ModelTenancy/MigrationManifest/TenancyStatic) green.
31. **Initial failures:** (a) one 16B `QueueApiTest` lifecycle assertion asserted `service_sessions` table absent — now obsolete; (b) frontend SvSelect/SvTextarea required `id`; (c) inventory.yaml/role-navigation.yaml snapshots stale; (d) 4 Pint + 3 Larastan issues on new code; (e) masking test asserted last-four absent (last-four is intentionally shown).
32. **Root causes:** (a) 16C now implements the coupling the 16B test forbade; (b) accessibility id requirement; (c) snapshot fixtures regenerate from source; (d) style/type nits; (e) masked phone shows last four by design.
33. **Corrections:** (a) updated the 16B lifecycle test to assert the real 16C coupling + non-payable preview (no invoice table); (b) added `id` props; (c) regenerated snapshots; (d) `composer pint` + targeted Larastan fixes; (e) assert the mask glyph + no raw phone.
34. **Rerun results:** service-session group 56 pass; QueueApiTest 17 pass; coverage/contract green; vitest 183; Pint/Larastan/typecheck/eslint/build clean.
35. **Skipped work / owners:** invoices/invoice trigger/preferred-fee snapshot → 17; preferred-personnel fee rules/calculation → 20A; commission rules/plans → 20F; commission ledger/earned commission → 20G; payouts → 20H; payments/receipts → 18A/18B; audit dashboard + permission-matrix closure (REM-PERM-001) → 19; notifications/reports → 21N; personnel SMS → 21S; search → 22; release audits → 23; perf → 24; deploy → 25. Workflow-level in-progress session cancellation deferred pending an authoritative Queue Entry `in_service → (cancelled|aborted)` extension.
36. **Exact owning phases:** see item 35.
37. **Pending PR/CI/review/merge:** none opened (branch pushed only). CI authoritative for Linux browser/Docker/gitleaks.
38. **Residual risks:** local Windows Playwright not claimed (Linux CI authoritative); Gate C in-progress abort deferred (documented recommended product decision); busy projection applied at the availability read (the wait estimator still counts schedule-available personnel — acceptable for 16C; perf is Phase 24); preview is `not_configured` until Phase 20F lands compensation config.
39. **Phase 17 handoff:** invoicing reads completed `service_sessions` (and `invoice_items.service_session_id` references `service_sessions(id,merchant_id)`); the resolved preferred-personnel fee + commission ledger + earned/payable status are Phases 17/20A/20G after validated payment. **Phase 17: Not started.**

## Phase 16B — Walk-Ins and Queues (verified_complete)

1. **Branch:** `phase-16b-walk-ins-queues`. **2. Base commit:** `404fed9` (verified Phase 16A merge, PR #26).
3. **Lifecycle status:** ✅ `verified_complete` — PR **#27** `Phase 16B: Implement walk-ins and queues` MERGED into `main` (squash merge commit `af79b56`, 2026-06-30; original implementation `6a9fbcc`, final pre-merge head `6272f080`). Initial CI run `28420643751` (head `6a9fbcc`) **FAILED** Backend (8 failed / 4 skipped / 751 passed) — `Call to undefined function createWalkIn()`: the helper was defined file-locally in `QueueApiTest.php` and was therefore not reliably available to independent parallel Pest workers. Corrected by moving `createWalkIn()` to `tests/Pest.php` (shared helper; the file-local definition and the unused `QueueApiTest` import removed; parallel execution preserved). Final CI run `28425875550` (head `6272f080`) — five required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all **SUCCESS**. `reviewDecision` blank under the documented PR-specific solo-maintainer governance exception — **not** independent reviewer approval. **REM-PERM-001 remains open** (Phase 19). **Proof:** [docs/proof/phase-16b.md](proof/phase-16b.md).
4. **Proof link / specs:** [phase-16b.md](proof/phase-16b.md); data dictionary [services-clients-scheduling.md](architecture/data-dictionary/services-clients-scheduling.md); state machines [queue-entry.md](architecture/state-machines/queue-entry.md) + [appointment.md](architecture/state-machines/appointment.md).
5. **Phase 16A reconciliation:** PR #26 MERGED `404fed9` (impl `e62da20`, CI remediation `ce04c73`, final head `794ff85`, final CI run `28378639377` all five checks SUCCESS); promoted to `verified_complete` across PROGRESS/CHANGELOG/proof-16a/traceability/register; REM-PERM-001 kept open; no new remediation item.
6. **Controlling decisions:** (1) 16B owns `walk_ins`/`queue_entries`/conversion/Queue Entry machine/positions/frontend; `in_service`+`completed` are queue states only — **no** `service_sessions`/invoice/commission (16C/17). (2) Authoritative 8-state set (§25.2/§37 over §13.7). (3) `checked_in→queued` forward-only expand. (4) Walk-in atomicity reuses the 15A client action. (5) PART B role ownership — FO operates, BM read-only+config, Personnel own-scope. (6) Legacy queue keys reconciled. (7) Branch Day queue-config anchor (no `queue_configurations` table). (8) Three assignment modes; preferred never bypasses eligibility. (9) Deterministic selector. (10) Deterministic estimator labelled "Estimate". REM-PERM-001 stays open (Phase 19).
7. **Migrations (4, forward-only):** `..._add_queue_fields_to_branch_day_records`, `..._add_queued_status_to_appointments` (status + checked_in_at CHECK widen), `..._create_walk_ins_table`, `..._create_queue_entries_table`. In manifest.
8. **Tables:** `walk_ins` (branch-owned), `queue_entries` (branch-owned).
9. **Branch Day queue fields:** `queue_is_open` bool, `queue_capacity` int nullable (>0), `queue_default_assignment_mode` (next_available|manual); `effective_queue_open = (status=open) AND queue_is_open`.
10. **State set:** `waiting, assigned, called, in_service, completed, transferred, cancelled, no_show`.
11. **Transition table:** waiting→assigned|transferred|cancelled|no_show; assigned→called|transferred|cancelled|no_show; called→in_service|transferred|cancelled|no_show; in_service→completed; transferred→assigned|waiting. Invalid → 422 `invalid_state_transition` (`QueueEntryStateMachine`).
12. **Appointment queued expansion:** enum + DB CHECK + Resource + generated contract; `checked_in→queued` only; queued is non-reserving + terminal-for-appointment.
13. **Constraints:** 13 `queue_entries` CHECKs (source-XOR, status, mode, position>0, status↔timestamp coherence, cancellation/transfer reasons, wait-override pairing); per-source UNIQUE (`walk_in_id`/`appointment_id`); composite FKs (branch/walk-in/appointment/client/service + 4 staff-profile columns to `(…,merchant_id)`).
14. **Indexes:** `(merchant_id,branch_id)`/`(branch_id,status,position)`/`(branch_id,queued_at)`/`(client_id,queued_at)`/`(service_id,status)`/`(staff_profile_id,status,position)`/`appointment_id`/`walk_in_id`; `UNIQUE(ulid)`; `UNIQUE(id,merchant_id)`.
15. **Queue-source uniqueness:** one entry per walk-in + one per appointment (deterministic 409 `queue_conversion_exists`).
16. **Position locking:** one `pg_advisory_xact_lock(hashtextextended('queue:merchant:branch'))` per branch + partial-unique `(branch_id,position) WHERE status IN (waiting,assigned,called)` — single consistent mechanism.
17. **Capacity enforcement:** `QueueCapacityGuard` — branch active + Branch Day open + effective queue open + capacity under the advisory lock; codes `branch_day_not_open`/`queue_closed`/`queue_capacity_reached`; `capacity_below_active` (422) on config.
18. **Permissions:** removed legacy `queue.operate`/`queue.transfer_entries`/`queue.configure`; added FO `queue.view/create/assign/transfer/reorder` + `preferred_personnel.select`, Personnel `personnel.my_queue.view`. BM holds zero `queue.*`. REM-PERM-001 stays open.
19. **Routes (15):** `queue.configuration.show/update`, `queue.reorder` (before params), `queue.index/show`, `walk-ins.store`, `appointments.queue.store`, `queue.assign/call/start/complete/transfer/cancel/no-show`, `personnel.queue.index`. Mutations `branch_mutation`; call/start/complete/no-show in VALIDATION_EXEMPT.
20. **Policies:** `QueueEntryPolicy` (FO operate via queue.assign + queue.transfer + queue.reorder + selectPreferred; BM read via branch.dashboard.view); config via branch.profile.manage + day.open_close (controller).
21. **Role boundaries:** FO owns ops; BM read-only + config (no entry mutation, backend-rejected); Personnel own-scope read; Merchant Admin/HR/Finance/Audit no queue mutation; Super Admin no merchant queue route.
22. **Walk-in atomicity:** `CreateWalkInAndQueueEntry` — advisory lock → capacity → client (existing or 15A `CreateClient`) → walk-in → queue entry → assignment → estimate → audit; full rollback on any failure (zero rows, zero success events).
23. **Appointment conversion:** `ConvertAppointmentToQueue` — row + advisory lock; duplicate check before state machine; one entry; appointment→queued; commit/rollback together.
24. **Next-available selector:** `NextAvailablePersonnelSelector` — eligible+available+active+branch-assigned, not busy; ordered by load, then earliest last assignment, then staff ULID; reuses 15B services.
25. **Estimated wait:** `QueueWaitEstimator` — `ceil(Σ durations ahead / max(1, available eligible personnel))` + in-service remaining; zero personnel → safe finite; labelled "Estimate"; calculated + override retained; recalculated after every mutation.
26. **Audit events (13):** `queue.configuration.updated`, `walk_in.created`, `queue_entry.created/assigned/called/started/completed/transferred/reordered/cancelled/no_show/wait_estimate_overridden`, `appointment.queued`. Safe context only.
27. **Branch closure:** `BranchClosureGuard` blocks archival + day-close on active (waiting/assigned/called/in_service/transferred) queue entries; terminal don't block; no cross-branch/tenant leak.
28. **Front Office board:** `QueueBoard.vue` (status/position/masked client/estimate/mode/personnel + capability-gated assign-next/call/start/complete/no-show + keyboard move-up/down reorder) + `WalkInCreate.vue` wizard + `QueueEntryDetail.vue` (assign/transfer/call/start/complete/cancel/no-show dialogs); `queueStore`.
29. **Branch Manager surfaces:** `branch/QueueReadOnly.vue` (no operational controls) + `branch/QueueConfiguration.vue` (open/close, capacity, default mode).
30. **Personnel surface:** `personnel/MyQueue.vue` — own assigned entries only, read-only, masked client; `usePersonnelQueueStore`.
31. **Backend test totals:** queue group **62** (schema 11, state-machine 6, position/concurrency/selector 6, capacity/closure 9, assignment 6, estimate 5, audit 6, API 17); **full backend suite 759 pass / 0 fail / 4 skip**.
32. **Frontend test totals:** Vitest **171** (+ QueueBoard 4, WalkInCreate 1, QueueConfiguration 2, MyQueue 2; reconciled RoleNavigation; regenerated nav + inventory snapshots).
33. **Playwright totals:** `tests/e2e/queue.spec.ts` (FO board/walk-in/lifecycle/invalid/reorder/closed, BM read-only + config, Personnel own, 360/768/1280, light+dark axe) — Linux CI authoritative (local Windows not claimed).
34. **Initial failures:** (a) `queued` violated `appointments_checked_in_at_check`; (b) repeat conversion returned 422 not 409; (c) 8 Larastan + 2 Pint issues on new code; (d) PermissionMatrix §10.3 fixture stale; (e) OpenApiTypeParity stale; (f) RoleNavigation spec asserted Queue planned.
35. **Root causes:** (a) coherence CHECK omitted queued; (b) state-machine check ran before the duplicate-conversion check; (c) missing generics/return types/redundant null checks/raw-SQL interpolation; (d/e) reconciliation + regen lag; (f) Queue flipped planned→live.
36. **Corrections:** (a) widen the checked_in_at CHECK in the expand migration; (b) reorder duplicate-check before state machine; (c) Larastan/Pint fixes; (d) update the independent matrix fixture; (e) `npm run api:types`; (f) point the spec at a still-planned item (Service sessions).
37. **Rerun results:** queue group 62 pass; full backend 759 pass; PermissionMatrix/RouteSecurity/AuditCoverage/OpenApiContract/Parity green; Vitest 171 pass; build OK.
38. **Skipped work / owners:** service_sessions/queue↔session coupling/duplicate-active-session/commission preview/preferred execution → 16C/20A; preferred-personnel fee → 20A; invoice fee snapshot/invoice → 17; payments/receipts → 18; audit dashboard + REM-PERM-001 → 19; billing → 20A–20E; compensation → 20F–20H; notifications/SMS → 21N/21S; search → 22; release audits → 23; perf → 24; deploy → 25.
39. **PR/CI/review/merge:** PR #27 MERGED (squash `af79b56`); initial CI run `28420643751` FAILED Backend (parallel-helper `createWalkIn()` undefined), corrected by relocating the helper to `tests/Pest.php`; final CI run `28425875550` five required checks all SUCCESS; solo-maintainer governance exception (reviewDecision blank, not independent approval).
40. **Residual risks:** local Windows Playwright not claimed (Linux CI authoritative); day-close + archival now block on active queue entries (terminal-only flows unaffected, verified); estimator availability recomputation per recalc is acceptable for 16B (perf is Phase 24).
41. **Phase 16C handoff:** `called→in_service` will create/start exactly one `service_sessions` row; `in_service→completed` will complete it (then Phase 17 invoices); duplicate-active-session protection + commission preview + preferred-personnel execution/fee are 16C/20A. **Phase 16C: Not started.**

## Phase 16A — Appointments (verified_complete)

1. **Branch:** `phase-16a-appointments` (deleted local + remote after merge).
2. **Base commit:** `02f4dc5` (verified Phase 15B merge, PR #25).
3. **Lifecycle status:** ✅ `verified_complete` — PR #26 MERGED into `main`. Lifecycle evidence: original implementation commit `e62da20`; initial CI run `28372954922` **failed** (E2E — Playwright: 157 passed / 3 failed — broad `/api/v1/appointments` collection mock intercepted the detail/action requests so `AppointmentDetail` received collection-shaped data and the check-in capability did not render — which also failed the invalid-transition browser test — plus a genuine dark-mode axe color-contrast failure from a non-adaptive brand-deep text token on `AppointmentDetail`); CI remediation commit `ce04c73` (let detail/action requests fall through to their dedicated mocks; adaptive heading/text token on dark surfaces; axe gate, timeouts, retries and business behaviour preserved — not classified as a flake); successful replacement initial run `28374669729`; final governance/PR head `794ff85`; final successful CI run `28378639377` (Backend, Frontend, Docker, Security, E2E all SUCCESS); squash merge commit `404fed9`. reviewDecision remained blank under the documented PR-specific solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-26.md`) — **not** independent reviewer approval. **REM-PERM-001 remains open** (Phase 19). The full local implementation history below is preserved. **Proof:** [docs/proof/phase-16a.md](proof/phase-16a.md).
4. **Proof link:** [docs/proof/phase-16a.md](proof/phase-16a.md); data dictionary [services-clients-scheduling.md](architecture/data-dictionary/services-clients-scheduling.md#appointments-16a--branch-owned); state machine [appointment.md](architecture/state-machines/appointment.md).
5. **Authoritative decisions:** (1) seven Phase-16A states (§25.2/§80 control over §13.7's `queued`/`in_service`; those deferred to 16B/16C by expand-and-contract; `cancelled_with_reason` included); (2) two personnel columns `preferred_/assigned_personnel_staff_profile_id` + `starts_at`/`ends_at` (= §13.7 authoritative equivalents; no preferred-personnel fee in 16A); (3) ULID is the public reference (no numbering scheme invented); (4) no-show via `appointment.cancel` (no new key); (5) REM-PERM-001 stays open (Phase 19).
6. **Migration / table:** `2026_06_29_000002_create_appointments_table.php` → `appointments` (branch-owned, ulid); in manifest + `TenantOwnership` (BRANCH_OWNED + COMPOSITE_CONSISTENCY + MODELS=`branch`).
7. **Exact state set:** `scheduled, confirmed, checked_in, rescheduled, cancelled, cancelled_with_reason, no_show`.
8. **Transition table:** `scheduled→confirmed|cancelled`; `confirmed→checked_in|rescheduled|cancelled|no_show`; `checked_in→cancelled_with_reason`; `rescheduled→scheduled|confirmed`. Invalid → 422 `invalid_state_transition` (`AppointmentStateMachine`).
9. **Constraints / indexes:** CHECK status-set, `starts_at<ends_at`, timestamp↔status coherence (checked_in_at/no_show_at/cancelled_at), reason-required for cancelled_with_reason; composite FKs to branch (CASCADE) + client/service/both-personnel (RESTRICT) + created_by (SET NULL); indexes `(merchant_id,branch_id)`/`(branch_id,starts_at,status)`/`(client_id,starts_at)`/`(assigned_personnel,starts_at)`/`(preferred_personnel,starts_at)` + `UNIQUE(id,merchant_id)`.
10. **Conflict exclusion behaviour:** `appointments_personnel_no_overlap` GiST `EXCLUDE (assigned_personnel WITH =, tstzrange(starts_at,ends_at,'[)') WITH &&) WHERE assigned NOT NULL AND status IN (scheduled,confirmed,checked_in)` → 409 `appointment_schedule_conflict`; back-to-back allowed; different personnel allowed; terminal/unassigned free.
11. **Models / tenancy:** `Appointment` (BelongsToMerchant + BelongsToBranch), `AppointmentStatus` enum, `AppointmentFactory`, `AppointmentStateMachine`, `AppointmentBranchScheduleValidator`, `MapsScheduleConflict` concern; registered in `TenantOwnership`.
12. **Permission reconciliation:** legacy `appointments.manage` → canonical `appointment.view/create/reschedule/cancel/check_in/assign/transfer` (Front Office) + `personnel.my_appointments.view` (Personnel). Branch Manager: **none** of `appointment.*` (read via `branch.dashboard.view`). REM-PERM-001 stays open.
13. **Routes:** 9 `/api/v1/appointments` routes (index/store/show + assign/transfer/reschedule/cancel/check-in/no-show) + `/api/v1/personnel/me/appointments` (own scope). Mutations `branch_mutation` (Sanctum + ResolveTenantContext + EnsureBranchScope + EnsurePermission).
14. **Policies / role boundaries:** `AppointmentPolicy` — Front Office owns mutations; Branch Manager read-only (`branch.dashboard.view`); Personnel own-scope (controller-enforced own staff profile); HR/Admin/Finance/Audit/Super-Admin denied.
15. **Branch-calendar validator:** `AppointmentBranchScheduleValidator` (branch active + operating hours + calendar exceptions + no closed-period crossing + single business date; future-date validates appointment date; same-day check-in requires open Branch Day → 409 `branch_day_not_open`).
16. **Phase 15B validator integration:** every create-with-assignment/assign/transfer/assigned-reschedule invokes `PersonnelSchedulingValidator::ensure()`; no eligibility/availability duplication.
17. **Appointment actions:** `CreateAppointment, AssignAppointment, TransferAppointment, RescheduleAppointment, CancelAppointment, CheckInAppointment, MarkAppointmentNoShow` (authorize→lock→validate state→validate scheduling→write→one audit event).
18. **Audit events:** `appointment.created/assigned/transferred/rescheduled/checked_in/cancelled/no_show` (typed; safe context; sanitised reasons; no contact/blind-index/sequential id).
19. **Front Office screens:** `AppointmentList.vue`, `AppointmentCreate.vue`, `AppointmentDetail.vue` (capability-gated dialogs); `appointmentStore`; routes `front-office.appointments[.create|.detail]`.
20. **Branch Manager read-only surface:** `branch/AppointmentsReadOnly.vue` (route `branch.appointments`) — no create/assign/transfer/reschedule/cancel/check-in/no-show controls.
21. **Personnel own-scope surface:** `personnel/MyAppointments.vue` (route `personnel.appointments`) — own assigned appointments only, read-only, masked client.
22. **Navigation / get-started:** `front-office.appointments`/`branch.appointments`/`personnel.my-appointments` planned→live; get-started `book-an-appointment` deep-linked to the create screen; navigation + screen-inventory YAML regenerated (vitest snapshots); §27.1 specs generated; OpenAPI/TS regenerated.
23. **Backend test totals:** appointment group **62** (schema 12, state-machine 5, conflict 7, scheduling 11, API 16, branch-closure 7, audit 6) + reconciled `PersonnelSchedulingValidatorTest`; **full backend suite 695 pass / 0 fail / 4 skip**.
24. **Frontend test totals:** Vitest **162** (+ AppointmentList 3, AppointmentDetail 2, regenerated inventory/navigation snapshots).
25. **Playwright totals:** `tests/e2e/appointments.spec.ts` (FO list/create/conflict/check-in/invalid-transition/unauthorized, BM read-only, Personnel own, 360/768/1280, light+dark axe) — Linux CI authoritative (local Windows not claimed).
26. **Initial failures:** appointment timezone storage (reschedule landed 3h off); full-suite 2 failures (OpenAPI byte-current + TS parity, stale after new routes).
27. **Root causes:** Eloquent stores a Carbon's wall-clock without tz conversion (offset dropped); openapi.json/api.ts not regenerated after adding routes.
28. **Corrections:** normalize parsed start to UTC before storage in Create/RescheduleAppointment; `composer api:openapi` + `npm run api:types` (after refreshing the node_modules volume for `openapi-typescript`).
29. **Rerun results:** appointment group 62 pass; OpenApiContract/Parity 14 pass; full backend 695 pass.
30. **Skipped work / owners:** walk-ins/queues/appointment→queue/`queued` → 16B; sessions/`in_service`/preferred-personnel execution+fee → 16C/20A; invoicing → 17; payments/receipts → 18; audit dashboard + REM-PERM-001 → 19; billing → 20A–20E; compensation → 20F–20H; notifications/SMS → 21N/21S; search → 22; release audits → 23; perf → 24; deploy → 25.
31. **PR/CI/review/merge:** PR #26 MERGED `404fed9` (final CI run `28378639377` — five required checks all SUCCESS). CI authoritative for Linux browser/Docker/gitleaks.
32. **Residual risks:** local Windows Playwright not claimed (Linux CI authoritative); day-close now blocks on same-day active appointments (no-appointment day-close flows unaffected, verified).
33. **Phase 16B handoff (now in progress):** `checked_in→queued` extends the aggregate by expand-and-contract (add status to CHECK, queue-entry action, `queue_entries.appointment_id`); guard queue stub + live busy projection are 16B.
34. **Phase 16C handoff:** `checked_in→in_service` + `service_sessions` extend the aggregate; preferred-personnel execution+fee + commission preview are 16C/20A. **Phase 16B in progress; Phase 16C: Not started.**

## Phase 15B — Personnel Availability and Eligibility Completion (verified_complete)

- **Branch:** `phase-15b-personnel-availability` (deleted local + remote after merge) · **Base commit:** `81a5866` (Phase 15A merge, PR #24).
- **Lifecycle status:** ✅ `verified_complete` — PR #25 MERGED into `main`. Lifecycle evidence: original implementation commit `93f2e72`; initial CI run `28353377796` **failed** (Backend — Laravel Pint formatting violations in the Phase 15B scheduling tests; E2E — the HR personnel-availability screen failed the dark-mode axe contrast test); CI remediation commit `4b75eb4` (Pint-only scheduling-test formatting corrections + a precise dark-mode contrast correction in `PersonnelAvailability.vue`; no unrelated product capability added); successful pre-governance CI run `28358888303`; final governance/PR head `050cca7`; final successful CI run `28359652332` (Backend, Frontend, Docker, Security, E2E all SUCCESS); squash merge commit `02f4dc5`. reviewDecision remained blank under the documented PR-specific solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-25.md`) — **not** independent approval. **REM-PERM-001 remains open** (Phase 19 owns full permission-matrix closure). The full local implementation history below is preserved. **Proof:** [docs/proof/phase-15b.md](proof/phase-15b.md). **Data dictionary:** [services-clients-scheduling.md](architecture/data-dictionary/services-clients-scheduling.md#personnel_availability-15b--branch-owned).
- **Controlling decisions:** (1) `personnel_availability` is owned by **15B** (the specific §80 roadmap entry controls over the §13.7 `(16A)` label); `appointments` stay 16A. (2) The reusable `PersonnelSchedulingValidator` is built + directly tested; no production workflow invokes it yet (binding 16A handoff). (3) HR owns availability mutation (same-branch); Merchant Admin gets no default availability authority. (4) Branch Manager gets branch-scoped read-only via canonical `branch.dashboard.view`. (5) Canonical columns only — `change_reason` is a command/audit field, not a column; no operational-mode enum/`busy`/`no_show`. (6) `branch.dashboard.view` activated for Branch Manager (Plan §19 matrix) — **contributes to but does not close REM-PERM-001** (Phase 19).
- **Migration / table:** `2026_06_29_000001_create_personnel_availability_table.php` → `personnel_availability` (branch-owned, no ulid); in manifest + `TenantOwnership`.
- **Constraints / indexes:** CHECK `type IN (recurring,exception)`, polarity (recurring⇒weekday/no-date, exception⇒date/no-weekday), weekday 0–6, `start_time<end_time` (no cross-midnight); GiST `btree_gist` same-polarity exclusion (recurring + exception); composite FKs `(branch_id,merchant_id)→merchant_branches` CASCADE and `(staff_profile_id,merchant_id)→staff_profiles` RESTRICT; indexes `(merchant_id,branch_id)`/`(staff_profile_id,weekday)`/`(staff_profile_id,date)`.
- **Model / tenancy:** `PersonnelAvailability` (BelongsToMerchant + BelongsToBranch); `AvailabilityType` + `PersonnelAvailabilityState` enums; `PersonnelAvailabilityFactory`.
- **Permissions:** legacy `availability.manage` → canonical `personnel.availability.manage` (HR-only); `personnel.eligibility.manage` preserved (HR-only); `branch.dashboard.view` activated (Branch Manager, read). REM-PERM-001 stays open.
- **Routes (3):** `staff.availability.show` (HR + Branch Manager read), `staff.availability.update` (HR atomic replace, branch_mutation), `staff.availability.emergency-unavailable` (HR, branch_mutation).
- **Availability resolver:** `AvailabilityResolver` — exception beats recurring; unavailable beats available within a layer; half-open `[start,end)`; `Africa/Nairobi`; `currentState` = suspended|available|on_break|unavailable|offline (`suspended` from lifecycle; `busy` deferred). Single source — no duplication.
- **Scheduling validator:** `PersonnelSchedulingValidator::validate()/ensure()` — interval/merchant/branch/lifecycle/active-assignment/service-status/scope/eligibility/availability; typed `SchedulingDecision`; codes `invalid_schedule_window`/`personnel_inactive`/`personnel_wrong_branch`/`personnel_not_eligible`/`personnel_unavailable`/`service_inactive`; no id/existence leak. Directly tested with no appointment/queue/session record.
- **Audit events:** `personnel_availability.updated` (Notice, one per atomic replace) + `personnel_availability.emergency_unavailable` (Warning); safe context only; sanitised reason (Redactor masks phone/email); never returned by API.
- **HR screen:** `pages/hr/PersonnelAvailability.vue` (BranchLayout) — personnel selector, derived state, eligible-services + link to eligibility, weekly editor (split shifts), breaks, date exceptions, day off, emergency modal, required reason, unsaved-changes guard, validation summary, atomic save, loading/empty/error/no-permission/no-branch states, `Africa/Nairobi`.
- **Branch Manager read-only surface:** `pages/branch/PersonnelSchedule.vue` — current state, today's working intervals/breaks/temporary unavailability, weekly schedule, eligible services; **no** edit/save/emergency/eligibility/replacement controls (backend rejects BM mutation regardless).
- **Navigation / get-started:** `hr.availability` planned→live; new `branch.personnel-schedule` live; get-started `set-availability` deep-linked to `/hr/availability`; navigation fixture + screen inventory(.json/.yaml) + 2 §27.1 specs + OpenAPI/TS regenerated.
- **Tests & gate totals:** scheduling group **62** (schema 16, resolver 16, validator 11, API 18, + 1) ; reconciled auth (PermissionMatrix/AuthorityBoundaries) green; OpenApiContract/Parity green; full backend parallel suite green. Vitest **157** (+15: PersonnelAvailability 12, PersonnelSchedule 3). Playwright `personnel-availability.spec.ts` (HR edit/save, reload, emergency, unauthorized, BM read-only, 360/768/1280, light+dark axe) — Linux CI authoritative. Pint clean; Larastan L8 No errors; vue-tsc clean; ESLint 0 errors; SPA build OK; composer/npm audit + gitleaks (CI).
- **Initial failures → corrections:** Larastan (Exception::$code clash → `errorCode`; Carbon createFromFormat narrowing; controller list/array annotations); test helper merchant/branch alignment + `branchStaff` for active assignment; `validator()` global-helper collision → `schedulingValidator()`; Branch Manager read needed `branch.dashboard.view` (activated); same-tenant out-of-branch is **403** (route-binding removes BranchScope by design), not 404; OpenAPI/TS regen after new routes.
- **Skipped work / owners:** appointments/overlap/assign/transfer/no-show → 16A; branch-open gate → 16A; walk-ins/queues/active-inactive/busy → 16B/16C; Personnel self-toggle → owning 16 workflow; sessions → 16C; invoicing/payments → 17/18; audit dashboard + REM-PERM-001 closure → 19; billing/compensation → 20A–20H; notifications/SMS/reports → 21N/21S; search → 22; release audits → 23; perf → 24; deploy → 25.
- **PR / CI / merge:** PR #25 MERGED `02f4dc5` (final CI run `28359652332` SUCCESS). CI authoritative for Linux browser/Docker gates.
- **Phase 16A handoff:** every appointment create/assign/transfer/reschedule MUST invoke `PersonnelSchedulingValidator`; controllers must not duplicate eligibility/availability logic; 16A adds branch-open/calendar/conflict checks around the shared gate. **Phase 16A: `local_complete` (see Phase 16A section).**

## Phase 15A — Services, Catalogue, Clients (verified_complete)

- **Branch:** `phase-15a-services-catalogue-clients` · **Base commit:** `d098f37` (Phase 11 merge, PR #23). · **Foundation commit:** `73c7d26` · **Implementation commit:** `23aeed1` · **Final PR head:** `1fcfa40`.
- **Lifecycle status:** ✅ `verified_complete` — PR #24 MERGED into `main` (merge commit `81a5866`, 2026-06-28; final PR head `1fcfa40`). CI run `28338582235` (head `1fcfa40`) — five required checks (Backend — Pint/Larastan/Pest; Frontend — ESLint/vue-tsc/Vitest/build; Docker — build images; Security — gitleaks; E2E — Playwright) all SUCCESS. reviewDecision intentionally blank under the documented PR-specific solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-24.md`) — **not** an independent approval. REM-CAT-CLI-001 → `verified_complete`; **REM-PERM-001 remains open** (Phase 19 owns full permission-matrix closure). The full local implementation history (two-commit foundation+implementation, initial failures, corrections) is preserved below. **Proof:** [docs/proof/phase-15a.md](proof/phase-15a.md). **Data dictionary:** [services-clients-scheduling.md](architecture/data-dictionary/services-clients-scheduling.md).
- **Exact work completed:** 5 branch-owned migrations + 5 enums + 5 models + HMAC blind-index contact protection (foundation, commit `73c7d26`); canonical permission activation (registry/seed/TS, 7 reconciled auth tests); catalogue/eligibility/client/consent domain actions + policies + form requests + thin controllers + masked resources; 16 `/api/v1` routes (`branch_mutation`/read, EnsurePermission + EnsureBranchScope); branch/tenant-scoped name+phone client search (blind index, `front_office.search`); 12 typed audit events; Branch Manager catalogue + HR eligibility + Front Office client create/search/detail screens (Phase 11 shell) + Pinia stores; navigation flips + get-started deep links + 5 §27.1 screen specs + inventory regen; OpenAPI + TS regen.
- **Tables delivered:** `service_categories`, `services`, `service_personnel_eligibility`, `clients`, `client_consents`.
- **Routes delivered (16):** `service-categories.{index,store,update}`; `services.{index,show,store,update,archive}`; `services.eligibility.{index,store,destroy}`; `clients.{index,show,store,update}`; `clients.sms-consent.update`.
- **Permissions activated:** `service.view/create/update/archive` (Branch Manager); `personnel.eligibility.manage` (HR); `client.view/create/update` + `front_office.search` (Front Office).
- **Screens delivered:** `branch/ServiceCatalogue.vue`, `hr/ServiceEligibility.vue`, `front-office/{ClientList,ClientCreate,ClientDetail}.vue`.
- **Tests & gate results:** backend **573 passed / 4 skipped / 0 failed**; vitest **142 passed**; Playwright 15A **5 passed** (incl. masked-contact, duplicate-conflict, 360px no-overflow, axe 0 serious/critical); Pint clean; Larastan L8 clean; vue-tsc clean; ESLint 0 errors; SPA build OK; OpenAPI deterministic + TS parity OK; composer audit clean; npm audit 0 high/critical; gitleaks no leaks.
- **Failures encountered & corrected:** (a) migrations missed a `merchant_id`-leading index (added; foundation slice); (b) validation assertion used Laravel's default `errors` key not the custom `error.fields` envelope (fixed); (c) `RouteSecurityContractTest` flagged two bodiless mutations (added reasoned `VALIDATION_EXEMPT` entries); (d) consent `PUT` returned 201 on first create (forced stable 200 for the idempotent state-set); (e) Larastan/Pint cleanups on the new code.
- **Work skipped & exact owners:** billing-status mutation gate → Plan §22 / Phases 20A–20E (infra not built at 15A); full canonical `permission-matrix.yaml` + parity/per-key infra (REM-PERM-001) → Phase 19; `personnel_availability` + scheduling enforcement → 15B; `preferred_personnel_fee_rules` → 20A.
- **Controlling decisions:** (1) canonical §19.2/19.3 keys activated for their owners — **REM-PERM-001 not closed** (Phase 19 owns full closure); (2) HR (not Branch Manager) owns `personnel.eligibility.manage`; (3) `preferred_personnel_fee_minor` kept internal/non-editable.
- **PR/CI/merge (completed):** PR #24 MERGED into `main` (merge commit `81a5866`, 2026-06-28); CI run `28338582235` on head `1fcfa40` — five required checks all SUCCESS; solo-maintainer governance exception (reviewDecision blank — not independent approval).
- **Context for Phase 15B:** `service_personnel_eligibility` schema + HR management landed in 15A; `personnel_availability` + scheduling enforcement are **15B** (created on `phase-15b-personnel-availability`, base `81a5866`).

## Phase 11 — UI Layout Foundation & Role Navigation

- **Branch:** `phase-11-ui-layout-role-navigation` (based on merged Phase 10F `9b493e6`, PR #22).
- **Status:** ✅ `verified_complete` — PR #23 MERGED into `main` 2026-06-28; five required checks SUCCESS on CI run `28314638091` (final pre-merge head `44cebdf`).
- **Commits:** implementation `0482e10`; CI remediation `bb04d87` (Docker context + E2E routes); final pre-merge head `44cebdf`; **merge commit `d098f37`**.
- **Proof:** [docs/proof/phase-11.md](proof/phase-11.md) · **Screen inventory:** [inventory.json](frontend/screens/inventory.json)/[inventory.yaml](frontend/screens/inventory.yaml) · **Navigation fixture:** [role-navigation.yaml](frontend/navigation/role-navigation.yaml) · **Governance:** [solo-maintainer-review-exception-pr-23.md](governance/solo-maintainer-review-exception-pr-23.md) · **Register:** REM-SCR-001 (`verified_complete` — Phase 11 substrate; promoted on PR #23 merge `d098f37`).

### Phase 10F verified-complete correction
- Phase 10F → `verified_complete` (PR #22, merge `9b493e6`; five-gate CI incl. `E2E — Playwright` all SUCCESS; genuine ClamAV EICAR CI test passed without skipping; impl commit `431dde2` + ClamAV CI correction `c54016d` preserved). REM-FILE-001 → `verified_complete`. Stale `local_complete`/`pending PR #22` wording removed. The local Windows Playwright timeout was not claimed as a pass; Linux CI is the authoritative browser result. The governance exception is a solo-maintainer record, not independent approval.

### Roots / content / brand (authoritative locations)
- **Landing-page image root:** `public/assets/landing_page_images/{identity}/` (5–10 approved PNGs per role; mapped per role in the proof matrix).
- **Legal-document root:** `docs/legal/{terms_of_service|privacy_policy|data_policy}/{identity}_*.md` (rendered verbatim via `/legal/:role/:doc`; lazy per-document).
- **FAQ root:** `docs/support/faq/{identity}_faq.md` (rendered as an accessible `<details>` accordion).
- **Landing copy root:** `docs/landing_page/{identity}_landing_page_content.md` (hero parsed verbatim). *(Note: CLAUDE.md names a space-folder `docs/landing page`; the repository uses the underscore folder `docs/landing_page` — repository wins.)*
- **Brand Identity:** `docs/brand/Servana Brand Identity.md` (followed; ADR-009 contrast preserved — no white text on Savannah-Orange CTA; introduced an adaptive `--color-heading` token so headings/anchors stay AA in dark mode; darkened light `--color-text-muted` to `#4b5563` for AA on surface-alt).

### Navigation placement rule (enforced in `AppShell` via resolved role identity)
- **Super Administrator exception:** primary navigation lives in the **header** (collapses to an accessible disclosure on mobile); no primary sidebar.
- **All merchant roles:** primary navigation lives in a **desktop sidebar/rail + mobile drawer**; the header is utility-only (identity, merchant/branch context, theme, profile/logout, drawer trigger). No duplicate primary nav in both places. Proven by `RoleLayouts.spec.ts` + `role-navigation-keyboard.spec.ts`.

### Work completed
- **Canonical role mapping** `types/roles.ts` (backend role → content identity; no aliases) + `router/destinations.ts` role-aware post-login destinations.
- **Typed navigation registry** `navigation/roleNavigation.ts` + generated fixture `docs/frontend/navigation/role-navigation.yaml` (snapshot-enforced parity); live items → real routes, planned items → owner phase + no route.
- **Eight role layouts** delegate to `RoleShell` → `AppShell` (skip link, landmarks, current-route indication, focusable main, 44px targets, light/dark, drawer focus-return).
- **Eight live landing pages** (`RoleLandingScaffold`) — verbatim hero + approved images + FAQ + legal footer + live actions + get-started progress + truthful "coming soon" (no dead links).
- **Eight guided get-started pages** (`GetStartedChecklist`) with verbatim Scope §3.2 checklists + a mandatory, non-prefilled legal-acknowledgement step.
- **Persistence** `stores/getStartedStore.ts` — versioned localStorage keyed by user ULID + role identity; stores only item ids + completion/dismissal/acknowledgement + schema version (no tokens/permissions/contacts/secrets/paths/responses). Resumable; dismiss + reopen; isolated per user and role.
- **Legal** rendered routes `/legal/:role/:doc` (verbatim, lazy per-document) + `LegalAcknowledgement` (separate optional marketing consent; mandatory cannot be bypassed; correct role docs only).
- **State boundaries** via `SvStateBoundary` extensions: loading/empty/error/no-permission (PermissionGate)/no-branch/unsupported-role.
- **Routing** role entry/get-started routes per role; `Verify`/`MfaChallenge`/`MfaSetup`/`FirstTimeSetup` now route role-aware (landing); MFA ordering, pending-setup, active-merchant, suspension routing preserved.
- **Phase 10F lifecycle correction** applied across PROGRESS/CHANGELOG/proof/register/traceability.

### Screen specifications created
- `docs/frontend/screens/inventory.json` (source of truth) → `inventory.yaml` (generated, snapshot-enforced); **44 §27.1 spec files** under `docs/frontend/screens/{domain}/` for every implemented production route, all 16 Phase-11 landing/get-started screens, and 2 access-state screens; future screens listed `planned` with truthful owner phases and **no routes/components**. Coverage guard `screens/screenInventory.spec.ts` fails on missing specs, status/router conflicts, fake planned routes, missing owner phase, or duplicate keys/routes. Generator: `scripts/generate-screen-specs.mjs`.

### Tests and quality gates (all green locally)
- Vitest: **133 passed** (incl. `roleNavigation`, `roleEntryRoutes`, `getStartedStore`, `RoleNavigation`, `GetStartedChecklist`, `RoleLandingContent`, `RoleLayouts`, `screenInventory`).
- Playwright (chromium, Linux-authoritative; run locally here): `role-entry-surfaces` (8 roles land + persistence/dismiss/reopen + legal gate), `role-navigation-keyboard` (placement + drawer focus-return), `role-foundation-responsive` (**56** at 360/768/1280, no overflow), `role-foundation-accessibility` (**32** axe light+dark, no serious/critical).
- `npm run typecheck` clean · `npm run lint` 0 errors · `npm run build` OK.

### Work skipped / exact owning phase
```
service catalogue / clients -> 15A ;
service-personnel eligibility schema and HR management -> 15A ;
personnel availability and scheduling enforcement -> 15B ;
appointments -> 16A ; walk-ins & queues -> 16B ; service sessions -> 16C ;
invoicing -> 17 ; payments/receipts/refunds/cash-up/locks -> 18A/18B ;
audit log + flagged events -> 19 ; billing/plans/subscriptions/M-Pesa/%-fee -> 20A-20E ;
compensation/payouts/earnings -> 20F-20H ; reports/notifications -> 21N ; personnel SMS -> 21S ;
search -> 22 ; release-wide responsive/dark/a11y audit -> 23 ; performance (per-role content lazy-split) -> 24 ; deployment -> 25.
```

### CI remediation (PR #23, commit `bb04d87`)
- The first PR #23 CI run failed on **Docker — build images** and **E2E — Playwright** (Backend/Frontend/Security passed). Remediated by `bb04d87` ("fix: align Phase 11 Docker context and E2E routes"); no `resources/spa/src` product code or migrations changed.
- **Docker root cause:** `.dockerignore` excluded the whole `docs` directory from the Docker build context, so the SPA build (`vue-tsc && vite build`) could not resolve the Phase 11 `@docs` documentation imports — `screenInventory.spec.ts` → `@docs/frontend/screens/inventory.json`, plus `roleContent`/`legalContent` `@docs/**` markdown. **Fix:** removed the `docs` line from `.dockerignore` (`*.md` ignore retained).
- **Playwright root cause:** Phase 11 re-pathed role-entry routes (landing became each area's index; `branch.list` → `/branch/list`, `hr.staff` → `/hr/staff`; setup/login redirects → `*.landing`). Three **pre-existing** specs (`merchant-onboarding`, `branches-staff-invitations`, `auth-magic-link`) asserted the old routes/headings/redirects. **Fix:** updated those specs to the changed role-entry routes/selectors (no product code changed to satisfy tests).
- Full local regression after remediation: typecheck clean · vitest 133 · lint 0 errors · build OK · Playwright green (Linux CI). Detail in [docs/proof/phase-11.md](proof/phase-11.md).

### Merge / lifecycle finalization (complete)
- PR #23 is **MERGED** into `main` (2026-06-28): five required checks SUCCESS on the final CI run `28314638091` (final pre-merge head `44cebdf`); merge commit **`d098f37`**; reviewDecision blank under the solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-23.md`) — **not** an independent approval.
- REM-SCR-001 promoted to `verified_complete` on the PR #23 merge.

### Known risks
- The authenticated landing chunk (`roleContent`, ~134 KB gzip) bundles all roles' landing+FAQ markdown; legal docs are already lazy per-document. Per-role lazy content split is a Phase 24 performance item.
- Navigation labels for Branch Manager and Finance are verbatim from the Scope's explicit nav lists; the other six roles' labels are derived from each role's §4.x scope functionality + the §3.2 get-started table (no explicit per-role nav list exists in the Scope for them).
- Frontend visibility is UX only; backend authorization remains the security boundary (re-stated in code + the navigation fixture).

### Context required by Phase 15A
- Add live nav routes + flip the relevant `planned` items to `live` in `navigation/roleNavigation.ts` (and regenerate the fixture snapshot); add the screens to `inventory.json` as `implemented` and write their final §27.1 specs before implementing; deep-link the matching get-started items in `content/getStartedContent.ts`. Use `RoleLandingScaffold`/`AppShell` patterns; never place merchant-role primary nav in the header; never add a Super-Admin merchant-create item or any Personnel contact-export surface.

## Phase 10F — File & Media Foundation

- **Branch:** `phase-10f-file-media-foundation` (based on merged Phase 10 `4f761ff`, PR #21). Implementation commit `431dde2`; ClamAV CI correction `c54016d` (history preserved).
- **Status:** ✅ `verified_complete` — merged as **PR #22** (merge commit `9b493e6`, 2026-06-26). CI Backend/Frontend/Docker/Security/E2E—Playwright all SUCCESS; the genuine ClamAV EICAR CI test passed without skipping (the local Windows Playwright timeout was never claimed as a pass — Linux CI is the authoritative browser result). Solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-22.md`; reviewDecision intentionally blank — not independent approval).
- **Proof:** [docs/proof/phase-10f.md](proof/phase-10f.md) · **Data dictionary:** [files-and-media.md](architecture/data-dictionary/files-and-media.md) · **Register:** REM-FILE-001 (`verified_complete`).

### Phase 10 verified-complete correction
- Phase 10 → `verified_complete` (PR #21, `4f761ff`, five-job CI incl. `E2E — Playwright` all SUCCESS; governance exception, not independent approval; `a6b3e4c` determinism-fix history preserved). REM-ROUTE-001 + REM-MIG-001 → `verified_complete`. Stale `local_complete`/`pending PR #21` wording removed.

### Work completed
- **Schema & indexes:** `uploaded_files` + `file_scan_events` (exact §13.13 fields, 11-purpose CHECK, scan/lifecycle CHECKs, indexes `(merchant_id,purpose,lifecycle_status)`/`(branch_id,purpose)`/`sha256`/`(scan_status,created_at)`, `available⇒clean+final_path` CHECK; **no `download_count`**, **no global SHA-256 uniqueness**). Applied cleanly; in manifest + TenantOwnership (cross-cutting nullable-scope).
- **Purpose registry:** `FilePurpose`(11) + `FilePurposeRegistry`/`FilePurposeDefinition` + `config/files.php`. Active uploadable: `merchant_logo`, `profile_photo` (image-only). Generated-only deferred: finance_export/invoice_pdf/receipt_pdf/billing_invoice_pdf/earnings_statement/day_close_report/cash_up_report; dispute_evidence/audit_evidence enum-only. Existing permission keys only.
- **Pipeline:** `FileUploadPipeline` (authorize→reject dangerous/spoofed pre-storage→quarantine→streaming SHA-256→magic-byte MIME→202). `ClamAvScanner` INSTREAM + `FileScanner` contract. `ScanUploadedFile`/`FinalizeCleanFile` (image re-encode + EXIF strip, verify-before-delete, available-after-verified).
- **Routes & authorization:** `POST /files`, `GET /files/{id}`, `POST /files/{id}/download-link`, `GET /files/{id}/download` (signed+auth). `FileAccessService` rechecks tenant/branch/own-scope/permission/available/clean at issue AND download. `FileResource` (no paths/hash).
- **Jobs & schedules:** 5 jobs on `file-scanning`; hourly expiry + quarantine cleanup, daily orphan verify (report-only); dedicated `file-worker` in dev + prod compose.
- **Audit/redaction/boundary:** file `AuditEvent` cases; `Redactor` extended (signature/sha256/paths/filename/scanner payload); `FileStorageBoundaryTest` (deliberate violation demonstrated failing then removed). Billing-read-only seam (`FileGenerationPolicy`).
- **Frontend states:** `SvFileUpload.vue` (selecting/uploading/scanning/available/rejected/error; aria-live; 44px; light/dark; typed transport; no localStorage) + `useFileDownload`.

### Routes and authorization
- 4 file routes (ULID-bound, classified tenant_mutation for mutations; download requires `signed`+auth). Per-purpose authorization in the pipeline/access service (not a single route permission). Upload rate limiter `file-upload`.

### Commands passed / failed / rerun
```
php artisan migrate (file tables) ......... applied cleanly
php artisan test tests/Feature/Files ...... 52 passed (153 assertions)
  + ClamAvEicarIntegrationTest (REAL clamd) 3 passed
SvFileUpload.spec + useFileDownload.spec .. 6 passed (vitest single-worker)
composer pint ............................. clean (12 auto-fixed)
composer stan (L8) ........................ No errors (fixed: fread int<1,max>; fopen|false guard; migration raw-SQL concat → single literal)
composer api:openapi (x2) ................. deterministic; 47 routes (+4 files); api:contract:check OK (41 paths/47 ops)
storage-boundary deliberate violation ..... FAILED as expected, then removed → PASS
```

### Work skipped / owning phase
```
role nav/landing -> 11 ; service/client/personnel -> 15A/15B ; appointments/queues -> 16A-C ;
invoice/receipt gen -> 17-18 ; finance_exports table -> 18B/23 ; file/export audit dashboard+flags -> 19 ;
billing state machine -> 20A/20B ; M-Pesa files -> 20D ; earnings/report gen -> 20H/21N ;
sec-ops notifications -> 21N/25 ; prod infra -> 25.
```

### CI / review / merge (verified)
- Merged as PR #22 (merge commit `9b493e6`). The full five-gate CI (Backend/Frontend/Docker/Security/E2E—Playwright) passed with the clamav profile; the genuine ClamAV EICAR integration test passed without skipping. REM-FILE-001 → `verified_complete` on merge (solo-maintainer governance exception, reviewDecision intentionally blank — not independent approval).

### Known risks
- The EICAR integration test requires a reachable clamd (CI runs the clamav service). Billing-read-only is a seam (boolean) until Phases 20A/20B supply the real state. Image sanitisation is GD-based (png/jpeg/webp only).

### Context required by Phase 11
- The file domain is the only sanctioned home for private business files: feature phases call the file-domain service (never `Storage::put`/`temporaryUrl` directly — `FileStorageBoundaryTest` enforces this), reference `FilePurposeRegistry`, and use `SvFileUpload`/`useFileDownload` for UI. Generated-only purposes attach their generator in the owning phase.

## Phase 10 — API Foundation

- **Branch:** `phase-10-api-foundation` (based on merged `main` @ `7ac20a5`, PR #20 / gate closure).
- **Status:** ✅ `verified_complete` — merged as **PR #21** (merge commit `4f761ff`, 2026-06-24). CI Backend/Frontend/Docker/Security/E2E—Playwright all SUCCESS; solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-21.md`; reviewDecision blank — not independent approval).
- **Proof:** [docs/proof/phase-10.md](proof/phase-10.md) · **ADR:** [ADR-004](architecture/adr/0004-migration-strategy.md) · **Contract:** [docs/api/openapi.json](api/openapi.json).
- **Register:** REM-ROUTE-001 (`verified_complete`), REM-MIG-001 (`verified_complete`) — promoted on the PR #21 merge with green five-job CI.

### Work completed
- **Gate-closure lifecycle reconciled** to CLOSED/effective (PR #20 `7ac20a5`) across PROGRESS/CHANGELOG/completion-report/register/governance/closure-proof/traceability.
- **Route classification (REM-ROUTE-001):** extended the R4 `RouteClass`/`RouteClassification` seam — 8th class `liveness_readiness`, per-class required/forbidden middleware, `VALIDATION_EXEMPT` allowlist (12 bodiless mutations). Every production non-GET route declares exactly one class; health probes are `liveness_readiness`.
- **Security contract:** `RouteSecurityContractTest` + `ForbiddenRouteAbsenceTest`; `FinancialRouteIdempotencyCoverageTest` preserved.
- **Pagination/filter/sort substrate:** `App\Http\Api\ApiPagination` (default 25 / max 100 / over-limit 422 / allowlisted sort + stable tiebreaker); retrofitted `branches.index`, `staff.index`, `staff-invitations.index` with new index Form Requests.
- **Resource can-maps:** `HasCapabilities` concern applied to Branch/StaffProfile/StaffInvitation/AuditLog resources (policy-derived, booleans, ULID ids only).
- **OpenAPI + TS contract:** maintained **dedoc/scramble** (v0.13.28, declared in `composer.json` `require`) is the authoritative schema engine; a thin `App\Support\OpenApi\OpenApiGenerator` wrapper invokes it and applies determinism, full `/api/v1` paths, testing exclusion (`Scramble::routes()`), operationId=route name, health probes, security scheme, error envelope and the financial Idempotency-Key (`composer api:openapi` → `docs/api/openapi.json`, 43 ops / 37 paths, no test/future ops; `Scramble::ignoreDefaultRoutes()` keeps the docs UI out of the app). `npm run api:types` → `resources/spa/src/types/generated/api.ts` (openapi-typescript@7.4.4); `npm run api:contract:check` (wired into frontend CI).
- **Migration governance (REM-MIG-001):** ADR-004 + `docs/architecture/migrations/{README.md,manifest.yaml}` (all 33 migrations) + `MigrationManifestTest`. No shipped migration edited.
- **Linux CI Playwright gate (`ci: enforce Phase 10 Playwright gate`):** added an explicit, separate `E2E — Playwright` job to `.github/workflows/ci.yml` (ubuntu-latest, Node 20, `npm ci`, `npx playwright install --with-deps chromium`, `npm run build`, `npm run e2e`, `timeout-minutes: 20`, failure-only artifact upload of `playwright-report/` + `test-results/`). The local Windows Playwright run **stalled without a completed run** — **no passing local E2E result is claimed**; this Linux job is the **authoritative Phase 10 browser gate**. The four existing jobs (Backend, Frontend, Docker, Security) are preserved unchanged.
- **OpenAPI contract determinism (`fix: make OpenAPI contract deterministic in CI`):** PR #21's first CI run (GitHub Actions run `28093861353`) failed only in `Backend — Pint, Larastan, Pest` → `OpenApiContractTest:26` ("openapi.json is stale"); `E2E — Playwright`, Frontend, Docker and Security had already passed on that run. Root cause: dedoc/scramble infers types from the **live DB schema**, and `OpenApiContractTest` regenerated the document without `RefreshDatabase`, so a parallel CI worker whose DB was not yet migrated read an empty schema and emitted fallback types (ULID ids→integer, booleans/counters→string, nullability lost) that diverged from the (correct) committed artifact. Fix: `OpenApiContractTest` now `uses(RefreshDatabase::class)` (guaranteed migrated schema in serial/parallel/CI), and `GenerateOpenApiCommand` fails fast (exit 1, no write) if a core type-driving table is missing. Scramble stays authoritative; the stale-contract assertion is untouched; correct types preserved. Regeneration is byte-deterministic — `composer api:openapi` twice produced no diff and `docs/api/openapi.json` + `resources/spa/src/types/generated/api.ts` were already byte-current (no change).

### Current routes remediated
- Classified: 25 production mutations + 2 health probes + test-only step-up routes.
- Paginated: `GET /api/v1/branches`, `/api/v1/staff`, `/api/v1/staff-invitations`.
- Can-maps: branches, staff, staff-invitations, audit-logs resources.

### Tests & generation commands
```
php artisan test --filter=RouteSecurityContractTest|ForbiddenRouteAbsenceTest|FinancialRouteIdempotencyCoverageTest
php artisan test --filter=PaginationContractTest|FilterSortContractTest|ResourceCapabilityMapTest
php artisan test --filter=OpenApiContractTest|OpenApiTypeParityTest|MigrationManifestTest
composer api:openapi   # docs/api/openapi.json
npm run api:types      # resources/spa/src/types/generated/api.ts
npm run api:contract:check
```

### Work skipped (with exact owner phase)
```
files/media -> 10F ; role nav/landing -> 11 ; services/clients/personnel -> 15A/15B ;
appointments/queues -> 16A-16C ; invoices/payments -> 17-18 ; audit workflow -> 19 ;
billing/M-Pesa/payouts -> 20A-20H ; notifications/SMS/reports -> 21N/21S ; search -> 22 ;
a11y/security audit -> 23 ; performance -> 24 ; deploy -> 25 ;
full per-table dict entries for audit_logs/permissions/roles -> 19 ;
platform_mutation / provider_webhook_mutation real routes -> owning Phase 20 subphases.
```

### CI / review / merge (completed)
- **PR #21 merged** to `main` (merge commit `4f761ff`, 2026-06-24). PR #21's first CI run (GitHub Actions `28093861353`) failed only in `Backend — Pint, Larastan, Pest` (`OpenApiContractTest:26`, openapi.json stale); the other four jobs — `E2E — Playwright`, Frontend, Docker, Security — passed. The determinism fix `a6b3e4c` (`fix: make OpenAPI contract deterministic in CI`) corrected the root cause; the subsequent complete run passed **all five jobs**.
- REM-ROUTE-001 and REM-MIG-001 are now `verified_complete` (promoted on merge; solo-maintainer governance exception `docs/governance/solo-maintainer-review-exception-pr-21.md` — not an independent approval).
- **Local E2E note (history):** the local Windows Playwright run stalled without a completed run; no passing *local* E2E result was claimed — the authoritative Linux `E2E — Playwright` CI job passed.

### Parallel-suite + maintained-generator corrections
- **Parallel failure → fix (`1d25224`):** the OpenAPI helpers `committedSpec()`/`specOperationIds()` lived in `OpenApiContractTest.php`, so a parallel worker running `OpenApiTypeParityTest.php` hit an undefined function. Moved them to `tests/Pest.php` (always autoloaded). Full parallel suite: **485 passed / 4 skipped / 2102 assertions / 4 processes**.
- **Maintained generator (`phase-10: adopt maintained OpenAPI generator`):** replaced the interim custom route-derived generator with **dedoc/scramble** as the authoritative engine (compatibility proven via `--dry-run`: v0.13.28 on L12.62/PHP8.3, no advisories); the wrapper is now thin.

### Known risks
- OpenAPI response schemas are now Scramble-inferred from Resources/Form Requests; component schemas may evolve as resources stabilise in feature phases (regeneration is deterministic and CI-guarded).
- `openapi-typescript` adds 2 **moderate** (dev-only) advisories via `@redocly/openapi-core` — below the `--audit-level=high` gate.

### Context required by Phase 10F
- The route classification registry, pagination substrate, can-map concern, OpenAPI generator and migration manifest are now the substrate every feature phase inherits — Phase 10F's file routes must declare a class, paginate any listing via `ApiPagination`, expose can-maps, appear in the regenerated `openapi.json`, and add their migrations to `manifest.yaml`.

## Gate closure — Pre-feature remediation (§5.4)

- **Branch:** `docs/pre-feature-remediation-gate-closure` (based on merged `main` @ `4f0d4f3`, PR #19 / R7). Documentation/evidence only — no product code.
- **Gate decision:** **CLOSED and effective** — gate-closure PR #20 merged into `main` (merge commit `7ac20a5`). Next phase: **Phase 10** (started).
- **Work completed:** finalized R7/REM-OPS-001 to `verified_complete` (PR #19, `4f0d4f3`); normalized REM-V-001 to `verified_complete`; set register `meta.pre_feature_gate_closed: true`; finalized the completion report (gate CLOSED + full §5.4 criteria matrix); authored the gate-closure governance exception; regenerated PROGRESS/CHANGELOG; updated traceability; wrote the gate-closure proof.
- **Evidence reviewed:** PR #12–#19 merge commits + CI conclusions (Backend/Frontend/Docker/Security SUCCESS); proofs `phase-v.md`…`phase-r7.md`; ADR-001/002/003/008/009; migration proofs (R2–R5); per-PR governance exceptions pr-13…pr-19. All nine PRE_FEATURE_REMEDIATION items `verified_complete`; no unresolved blocker.
- **Documents changed:** `docs/remediation/register.yaml`, `docs/remediation/pre-feature-completion-report.md`, `docs/governance/solo-maintainer-pre-feature-gate-closure-exception.md`, `docs/PROGRESS.md`, `docs/CHANGELOG.md`, `docs/traceability/servana-requirements.csv`, `docs/proof/pre-feature-remediation-gate-closure.md`.
- **Work skipped (with owning phase):**
  ```
  API contract / pagination / OpenAPI    -> Phase 10
  file and media foundation              -> Phase 10F
  role navigation and landing surfaces   -> Phase 11
  feature business domains               -> owning Section 80 phases
  release-wide accessibility audit       -> Phase 23
  deployment / backup / alerting         -> Phase 25
  ```
  Reason skipped: this is a documentation/evidence reconciliation task; all feature
  work is owned by its Section 80 phase and gated by §5.4a obligations.
- **CI/review/merge (completed):** gate-closure PR #20 merged `7ac20a5` (2026-06-23 04:44Z) with CI Backend/Frontend/Docker/Security all SUCCESS; reviewDecision blank under the solo-maintainer governance exception (not an independent approval). Closure is effective.
- **Known risks:** none introduced (no product code changed); the §5.4 closure is a documentation decision backed by already-green PR #19 CI and the R7 proof. Residual technical risks remain as recorded in each phase proof (e.g. R7 S3 live-probe scope, `PGCONNECT_TIMEOUT` env-level bound).
- **Next-phase context:** Phase 10 (API Foundation) has started on branch `phase-10-api-foundation`. Phase 10 inherits strict config-driven readiness (do not re-couple `/health` liveness to dependencies) and the per-run/process test namespace (never FLUSHDB).

## Phase R7 — Production probes, CI isolation, environment parity

- **Branch:** `phase-r7-production-probes-ci-parity` (based on merged `main` @ `57ae8db`, PR #18 / R6).
- **Status:** ✅ `verified_complete` — merged as PR #19 (squash `4f0d4f3`, 2026-06-23). CI Backend/Frontend/Docker/Security all SUCCESS; solo-maintainer governance exception (reviewDecision intentionally blank — not independent approval).
- **Proof:** [docs/proof/phase-r7.md](proof/phase-r7.md) · **ADR:** [ADR-009](architecture/adr/0009-brand-contrast-tokens.md) · **Report:** [pre-feature-completion-report.md](remediation/pre-feature-completion-report.md).
- **Register:** REM-OPS-001 (`verified_complete`).

### Work completed
- **Probe behaviour:** `/health` is dependency-free liveness (200 even when every
  dependency is down; no versions/hosts/secrets). `/health/deep` is strict
  readiness — 200 only when every REQUIRED production dependency is healthy, 503
  on any required failure, with safe names+statuses only and bounded per-probe
  timeouts. `HealthController` is now config-driven (`config/servana.php health`).
- **Required production dependencies:** `database`, `redis`, `cache`, `s3`
  (derived from `docker-compose.prod.yml` — managed PostgreSQL, Redis, S3; Redis
  backs cache + queue). `meilisearch` stays OPTIONAL until Phase 22; Mailpit
  (local-only) is never a readiness dependency. Production strictness
  (`require_configured`) fails an unconfigured required dependency so production
  cannot silently treat a managed dependency as optional.
- **Healthcheck wiring:** prod `nginx` healthcheck → `/health/deep` (traffic
  eligibility); the app container keeps `php -v` liveness. `PGCONNECT_TIMEOUT`
  bounds PG connect time; Redis (`timeout`) and S3 (`http.connect_timeout`) bounded.
- **Test-isolation strategy:** cache/session/queue already use array/sync drivers
  (in-memory, per process — no shared store, no FLUSHDB). Added a unique Redis +
  cache **namespace per run + parallel process** in `tests/bootstrap.php`
  (`servana_test_{runId}_{token}_`), so direct Redis usage and the CI shared Redis
  are isolated; two namespaces use identical logical keys without collision.
- **Runtime-version parity:** PHP 8.3, Node 20, Composer 2 pinned across the app
  image, SPA/nginx build image, dev tooling, CI and machine-readable metadata
  (`package.json` engines + `.nvmrc`). `RuntimeParityTest` fails on drift.
- **ADR-009:** brand contrast decision recorded with measured ratios — dark Brand
  Deep text on the Savannah-Orange CTA (≈ 4.92:1, AA) because white-on-orange
  (≈ 2.80:1) fails AA. `BrandContrastTokenTest` guards the committed tokens.

### Commands — passed / failed / rerun
```
PASS  health suite (Liveness/Readiness/ReadinessDependencyFailure/Redaction/
      ProductionReadinessConfiguration) — 18
PASS  isolation suite (RedisPrefix/Cache/RateLimit/ParallelTest) + RuntimeParity +
      BrandContrastToken
FAIL→PASS RedisPrefixIsolationTest: first cut changed the prefix via config()+purge
      (RedisManager caches its config, so the prefix did not reconnect) → rewrote
      to raw phpredis OPT_PREFIX clients; rerun green.
PASS  R6 regression (RevocationMiddlewareOrder/MidSessionSuspension/Authorization
      Freshness/SessionRevocation/MfaMiddlewareOrder/CrossTenant/CrossBranch)
<full backend serial + 3× parallel, pint/stan/validate/audit/gitleaks, frontend,
 docker images, e2e — recorded in docs/proof/phase-r7.md>
```

### Work skipped / deferred (with exact owning phase)
```
- Full OpenAPI / route contract                              -> Phase 10
- File/media pipeline                                        -> Phase 10F
- Release-wide responsive/dark/a11y redesign + axe sweep     -> Phase 23
- Deployment, backups, alerting, restore exercises           -> Phase 25
- Horizon/queue observability                                -> Phase 21N/25
- Feature-domain business routes/tables                      -> owning feature phases
```

### Known risks
- The S3 readiness probe does a live round-trip only when a custom endpoint is set
  (MinIO/dev); for managed AWS S3 (no endpoint) it reports configured-disk
  readiness without a network call. Acceptable for R7; a deeper live S3 check can
  be added when file storage lands (Phase 10F).
- `PGCONNECT_TIMEOUT` bounds PG connect at the libpq/env level (the Laravel pgsql
  DSN builder has no `connect_timeout` key); documented in ADR/proof.

### Pre-feature gate status — CLOSED and effective (gate-closure PR #20 merged)
- `docs/remediation/pre-feature-completion-report.md` records **gate status:
  CLOSED**. V + R1–R7 are `verified_complete` (R7 = PR #19, `4f0d4f3`, CI
  Backend/Frontend/Docker/Security all SUCCESS, governance exception). The §5.4
  gate closure is **effective**: the gate-closure PR #20 merged into `main`
  (merge commit `7ac20a5`, 2026-06-23; CI all SUCCESS). **Phase 10 has started.**

### Context required before Phase 10
- Readiness is strict and config-driven; Phase 10's route/OpenAPI work must not
  re-introduce dependency-coupling into `/health` (liveness stays dependency-free).
- The per-run/per-process test namespace exists; new Redis-backed tests should rely
  on it (never FLUSHDB).

## Phase R6 — Session & authorization revocation

- **Branch:** `phase-r6-session-authorization-revocation` (based on merged `main` @ `66aaead`, PR #17 / R5).
- **Status:** ✅ `verified_complete` — merged as PR #18 (squash `57ae8db`, 2026-06-22). CI Backend/Frontend/Docker/Security all SUCCESS; solo-maintainer governance exception (reviewDecision intentionally blank — not independent approval).
- **Proof:** [docs/proof/phase-r6.md](proof/phase-r6.md).
- **Register:** REM-SESS-001 (`verified_complete`).

### Work completed
- **Central revocation service** `app/Domain/Auth/Services/AccessRevocationService.php`
  (`revokeForUser` / `revokeForMembership` / `revokeForMerchant`) — idempotent,
  transactional; revokes DB sessions + Sanctum personal-access tokens +
  unconsumed Magic Links + applicable pending invitations; returns a secret-free
  `RevocationSummary` (counts only).
- **Per-request active-principal gate** `app/Http/Middleware/EnsureActivePrincipal.php`
  — pinned after auth and before MFA/tenant context (bootstrap priority + the
  authenticated route groups). Rejects a suspended/deactivated merchant OR
  platform user 401 and tears its session down.
- **Lifecycle integration:** `StaffLifecycleService` suspend/deactivate delegate
  to the central service (adds token revocation) and record the secret-free
  revocation counts on the existing membership audit event. Logout invalidates
  unconsumed Magic Links; a new Magic Link supersedes prior unconsumed links.
- **Per-request freshness (verified, no new cache):** membership, role, branch
  ids and permissions are re-resolved from the DB every request; a role/branch/
  permission change takes effect on the next request. No persistent authorization
  cache exists to invalidate.
- **Frontend (UX only):** loop-safe central 401 handler clears auth state and
  returns to login on a mid-session revocation.

### Revocation surfaces implemented
```
sessions (database)            — deleteSessions(user ids)
personal_access_tokens         — revokeTokens(user ids)  [no issuance surface; defence in depth]
magic_login_tokens             — invalidateUnconsumedForEmail
staff_invitations (pending)    — revoke (membership-scoped or merchant-wide)
authorization cache            — none persistent (documented no-op seam)
```

### Middleware & lifecycle actions changed
```
bootstrap/app.php              — EnsureActivePrincipal pinned auth → (here) → MFA → tenant
routes/api.php                 — EnsureActivePrincipal added to authenticated + mfa + probe groups
StaffLifecycleService          — suspend/deactivate → AccessRevocationService
MagicLinkController::logout     — invalidate unconsumed Magic Links
RequestMagicLink                — invalidate previous unconsumed links on issue
resources/spa/src/{services/apiClient,main}.ts — central 401 → clear + redirect
```

### Work skipped / deferred (with exact owning phase)
```
- Redis/cache/rate-limit prefix isolation                     -> R7 (REM-OPS-001)
- Liveness/readiness split + environment parity               -> R7 (REM-OPS-001)
- ADR-009 brand contrast decision                             -> R7
- Full route contract / OpenAPI                               -> Phase 10
- Future-domain (finance/queue/M-Pesa/...) revocation hooks   -> each owning feature phase
- Release-wide browser/security hardening                     -> Phase 23
```
Reason skipped: each is owned by a later phase per Plan §§79–80; mixing it into
R6 would exceed the Correction-7 scope.

### Known risks
- Mid-session "deleted real DB session → 401" is proven via the active-principal
  gate (real login + status revoked) and the physical session-row deletion; an
  in-process HTTP cookie re-read after deletion is masked by Laravel's singleton
  session Store retaining in-memory attributes (a test-harness artifact, not a
  product defect — documented in the proof).
- Merchant-level suspension has no HTTP action yet (Super-Admin governance is a
  later phase); `revokeForMerchant` + `EnsureMerchantActive` cover it and are
  tested at the service level.

### Commands — passed / failed / skipped
```
PASS  composer pint --test (after autofix)   PASS  composer stan (L8)
PASS  php artisan test  (409 passed, 4 skipped)   PASS  targeted R6 filters (47)
PASS  audit:verify-chain (no chains to verify on the empty dev table)
PASS  composer validate --strict   PASS  composer audit --locked (0)
PASS  npm run lint (0 errors)   PASS  npm run typecheck   PASS  npm run test (77)
PASS  npm run build   PASS  npm audit --audit-level=high (0)   PASS  gitleaks (0)
PASS  npm run test (vitest 79, +2 new 401 loop-guard tests)
PASS  docker build php.Dockerfile --target dev   PASS  docker build nginx --target prod
FLAKY npm run e2e — env timeouts on Windows: 23/30 (concurrent), 29/30 (isolated);
      the failing test passed on re-run while a different one flaked. R6 ships no
      UI flow; interceptor provably inert for the stubbed endpoints. Phase 23 owns
      the release a11y/e2e gate.
```

### Context required by R7
- R6 documents that NO persistent authorization cache exists; R7 owns Redis/
  cache/rate-limit prefix isolation and must not assume R6 added one.
- `EnsureActivePrincipal` ordering (auth → active-principal → MFA → tenant) must
  be preserved by any R7 middleware change.

## Phase R5 — Tenant & branch schema hardening

- **Branch:** `phase-r5-tenant-branch-schema-hardening` (based on merged `main` @ `1288f48`, PR #16 / R4).
- **Status:** ✅ `verified_complete` — merged as PR #17 (squash `66aaead`). CI Backend/Frontend/Security passed; the initial CI/Docker job failed on an external Buildx/Docker Hub timeout and a rerun passed with no product-code or Dockerfile change; solo-maintainer governance exception recorded (reviewDecision intentionally blank, not independent approval).
- **Proof:** [docs/proof/phase-r5.md](proof/phase-r5.md) · **ADR:** [ADR-002](architecture/adr/0002-tenancy-enforcement-model.md) · **Data dictionary:** [branches-and-staff.md](architecture/data-dictionary/branches-and-staff.md).
- **Register:** REM-TEN-001 (`verified_complete`).

### Work completed
- **Ownership inventory / central registry:** `app/Domain/Tenancy/TenantOwnership.php`
  classifies every existing base table (branch_owned / tenant_owned / exempt-with-
  reason), driving the coverage tests. No undocumented table is permitted.
- **Tables changed (forward-only, expand→backfill→constrain):**
  - +`merchant_id` (NN, indexed, FKs) on **5 branch-owned** tables —
    `branch_user_assignments`, `branch_operating_hours`, `branch_calendar_exceptions`,
    `branch_day_records`, `branch_cash_ups`;
  - +`merchant_id` on **2 tenant-owned** tables — `staff_history`,
    `merchant_user_permission_overrides`;
  - +`UNIQUE (id, merchant_id)` on **3 parents** — `merchant_branches`,
    `staff_profiles`, `merchant_users` (composite-FK targets).
- **Rows backfilled:** `merchant_id` derived from the parent branch/profile/
  membership (parameterized cursor; fail-safe on orphans). Post-`migrate:fresh
  --seed`: **0** null `merchant_id` rows across affected tables.
- **Constraints/indexes added:** per table — `merchant_id → merchants` FK
  (RESTRICT); **composite consistency FK** `(fk, merchant_id) → parent(id,
  merchant_id)` (CASCADE) so a row's merchant can never disagree with its parent;
  index beginning with `merchant_id`. Existing `branch_id` CASCADE FK retained.
- **Models/scopes updated:** `BelongsToMerchant` added to all 7 owned models
  (`+BelongsToBranch` on the 4 branch models). `BranchUserAssignment` uses
  `BelongsToMerchant` **only** — it is the branch-assignment authority that
  resolves `TenantContext::branchIds`, so `BranchScope` there would be circular
  (documented in the registry). Creation sites set `merchant_id` from the
  branch/parent (`AcceptStaffInvitation` runs without context → explicit).
- **Coverage:** `TenantColumnCoverageTest` (live PostgreSQL schema),
  `ModelTenancyTraitCoverageTest`, `RouteBindingTenantSafetyTest`, plus the
  retained `TenancyStaticAnalysisTest`. The 404 cross-tenant / 403 cross-branch
  contract is unchanged.

### Work skipped / deferred (with exact owning phase)
```
- Session/token/Magic-Link/invitation/cache revocation + per-request
  membership/role freshness                                  -> R6 (REM-SESS-001)
- Readiness / environment parity                              -> R7 (REM-OPS-001)
- Migration manifest + full route-classification/OpenAPI      -> Phase 10
- Future tenant/branch tables' ownership columns              -> each owning phase
- Invoice/payment/queue/personnel isolation rows (tables N/A)  -> Phases 16-19
- Cash-up workflow behaviour (only ownership columns hardened) -> Phase 18B
```

### Commands — passed / failed / skipped
```
PASSED:
  php artisan migrate:status ................ all R5 migrations Ran
  php artisan migrate:fresh --seed .......... DONE; 0 null merchant_id rows
  php artisan test .......................... 370 passed, 4 skipped
  php artisan test --parallel ............... pass (4 processes)
  php artisan audit:verify-chain ............ OK (no chains on fresh DB)
  composer validate --strict / pint / stan L8 clean
  composer audit / npm audit / gitleaks ..... clean
  npm run lint (0 err) / typecheck / test (77) / build  ..... pass
  npm run e2e ............................... 30 passed
  docker build php(dev) / nginx(prod) ....... exit 0
FAILED then fixed (recorded, not erased):
  Adding BelongsToBranch to BranchUserAssignment made BranchScope circular ->
    12 auth/branch/HR tests failed. Fixed: BelongsToMerchant only (authority
    table), documented BranchScope exemption; rerun green.
  Pest toContain is variadic -> a failure-message 2nd arg became a needle;
    removed the messages.
SKIPPED:
  4 pre-existing skipped backend tests (feature-phase isolation rows N/A).
  e2e: known auth-magic-link flake + one webServer-startup timeout (port
    contended by a concurrent docker build); clean rerun 30/30.
```

### Known risks / residual
- The composite FK assumes every branch-owned row has a resolvable parent; truly
  orphaned legacy data fails the backfill safely (operator must resolve).
- `merchant_id` auto-fill relies on `TenantContext` on authenticated routes; the
  composite FK is the fail-closed backstop on any context/branch disagreement.

### Context for R6 (session & authorization revocation)
- R5 added no per-request revocation. R6 must add active-membership/active-role
  re-checks every authenticated request and verify session/token/Magic-Link/
  invitation/cache invalidation. The `merchant_id`/`branch_id` columns + scopes
  R5 added are the structural substrate R6's freshness checks build on; the
  documented 404 cross-tenant / 403 cross-branch posture is unchanged and must
  remain so.

## Phase R4 — Idempotency & replay protection

- **Branch:** `phase-r4-idempotency-replay-protection` → merged as **PR #16**, commit `1288f48`.
- **Status:** ✅ `verified_complete` — PR #16 merged; CI Backend/Frontend/Security/Docker passed; solo-maintainer governance exception recorded ([docs/governance/solo-maintainer-review-exception-pr-16.md](governance/solo-maintainer-review-exception-pr-16.md)); `reviewDecision` intentionally blank (NOT an independent approval).
- **Proof:** [docs/proof/phase-r4.md](proof/phase-r4.md) · **ADR:** [ADR-003](architecture/adr/0003-idempotency-and-replay-protection.md) · **Data dictionary:** [core-identity-and-tenancy.md](architecture/data-dictionary/core-identity-and-tenancy.md).
- **Register:** REM-IDEMP-001 (`verified_complete`).

### Work completed
- **Schema (forward-only, §13.5 corrected):** `idempotency_keys` —
  `UNIQUE(idempotency_scope, key_hash)` (the concurrency boundary); indexes
  `(state, lock_expires_at)` + `(expires_at)`; `state` CHECK
  (processing/completed/failed); `key_hash` = SHA-256(raw key); `response_body_
  encrypted` (`encrypted:array`); FKs actor `SET NULL`, merchant/branch `RESTRICT`.
  Data-dictionary entry authored before the migration (§13.2).
- **Deterministic scope + request hash:** `IdempotencyScopeResolver`
  (`merchant:{ulid}:user:{ulid}` / `platform:user:{ulid}` / `webhook:{provider}:
  {env}`); `CanonicalRequestHasher` (method + route + normalized path params +
  content type + recursively key-sorted body; JSON key order irrelevant).
- **Middleware** `EnsureIdempotentRequest` (§24.4): require key 16–255; first claim
  `INSERT ON CONFLICT DO NOTHING`; existing-row resolution under `SELECT … FOR
  UPDATE`; completed→replay, different request→409
  `idempotency_key_reused_with_different_request`, active lock→409
  `request_in_progress`+Retry-After, expired/failed→reclaim+retry; missing/malformed
  key→422 `idempotency_key_required`/`invalid_idempotency_key`.
- **Replay safety:** `ReplayResponseSanitizer` allowlists `content-type` only;
  never cookies/auth/xsrf/session/CSP/signed-URL/server/debug; body encrypted at
  rest; replay tagged `Idempotent-Replay: true`; no key hash / row id exposed.
- **Retention:** `idempotency:prune` (bounded; never deletes an active lock;
  standard ≥72h, retriable ≥30d) scheduled daily; config in `.env.example`.
- **Provider dedupe seam:** `ProviderReplayGuard` (generic; no M-Pesa) —
  first/duplicate/payload-mismatch by `webhook:{provider}:{env}` scope.
- **Classification seam:** `RouteClass` + `RouteClassification` (`route_class`
  default); `FinancialRouteIdempotencyCoverageTest` fails on any unprotected
  `financial_mutation` route. Middleware pinned LAST in `bootstrap/app.php`
  priority (Plan §9.4 step 16).

### Work skipped / deferred (with exact owning phase)
```
- Full route-classification / OpenAPI contract  -> Phase 10 (reuses route_class).
- Real invoice/payment/refund route attachment   -> Phases 17-18.
- M-Pesa callback/inbox/receipt dedupe attachment -> Phase 20D (ADR-006).
- Billing/payout/compensation route attachment    -> owning Phase 20 subphases.
- Tenant-schema remediation -> R5; session/authz revocation -> R6; readiness -> R7.
- No production financial/M-Pesa routes created (truthfully empty); the reusable
  control is proven by a testing-only harness.
```

### Commands — passed / failed / skipped
```
PASSED:
  php artisan migrate:fresh --seed ................................ ok
  php artisan test (full backend) ........... 351 passed, 4 skipped
  php artisan test --parallel ............... pass (4 processes)
  Idempotency + coverage (10 suites) ........ 41 tests pass
  php artisan audit:verify-chain ............ OK (no chains on fresh DB)
  composer validate --strict ................ valid
  composer pint -- --test ................... clean
  composer stan (Larastan L8) ............... no errors
  composer audit --locked ................... 0 advisories
  npm run lint .............. 0 errors (28 pre-existing warnings)
  npm run typecheck ......................... clean
  npm run test (vitest) ..................... 77 passed
  npm run build ............................. built
  npm run e2e (playwright) .................. 30 passed
  npm audit --audit-level=high .............. 0 vulnerabilities
  gitleaks detect --no-git --redact ......... no leaks
  docker build php.Dockerfile  --target dev . exit 0
  docker build nginx.Dockerfile --target prod exit 0
FAILED then fixed (recorded, not erased):
  IdempotencyConcurrencyTest used DatabaseTruncation -> committed rows leaked
    into later RefreshDatabase tests (prune counts off). Converted to
    RefreshDatabase + a same-connection unique-constraint contention proof.
  retryAfter() returned float (Carbon 3 diffInSeconds) -> TypeError; (int) ceil().
  Two test-assertion bugs (replay Set-Cookie from framework session; "boom" in
    route_name) -> assert secret VALUES / exception detail instead. Impl correct.
  Pint (imports/strict-types), Larastan (nullable row, raw-SQL concat, untyped
    arrays) -> fixed. gitleaks (2 high-entropy test keys) -> renamed + allow.
SKIPPED:
  4 pre-existing skipped backend tests (unchanged by R4).
  e2e auth-magic-link check-email flake: first run 27/3, rerun 30/30 (documented).
```

### Known risks / residual
- Crash after the effect but before completion re-executes on recovery; exactly-once
  across a crash additionally relies on the owning phase's ledger-level unique
  constraints (ADR-003 limitation).
- Lock TTL (30s default) too short for a very slow provider call surfaces as a
  spurious `request_in_progress`; tune `IDEMPOTENCY_LOCK_TTL_SECONDS`.
- True OS-parallel contention is enforced by the PG unique constraint + `FOR
  UPDATE`; the harness exercises them deterministically (no process forking).

### Context for R5 (tenant/branch schema hardening)
- `idempotency_keys` carries nullable `merchant_id`/`branch_id` as **forensic**
  columns (not a `BelongsToMerchant` model — platform/webhook scopes have no
  merchant); isolation is via the scope being part of the unique key. R5's
  `TenantColumnCoverageTest` should treat it as cross-cutting infrastructure, not a
  tenant-owned business table.
- Financial routes built later must carry BOTH tenant/branch controls AND
  `EnsureIdempotentRequest` (order per §9.4: auth → MFA → tenant/branch/permission
  → step-up → validation → idempotency+transaction).

## Phase R3 — Privileged MFA & step-up

- **Branch:** `phase-r3-privileged-mfa-step-up` → merged as **PR #15**, commit `c0402b2`.
- **Status:** ✅ `verified_complete` — PR #15 merged; CI Backend/Frontend/Security/Docker passed; solo-maintainer governance exception recorded ([docs/governance/solo-maintainer-review-exception-pr-15.md](governance/solo-maintainer-review-exception-pr-15.md)); `reviewDecision` intentionally blank (NOT an independent approval).
- **Proof:** [docs/proof/phase-r3.md](proof/phase-r3.md) · **Data dictionary:** [core-identity-and-tenancy.md](architecture/data-dictionary/core-identity-and-tenancy.md).
- **Register:** REM-MFA-001 (`verified_complete`).

### Work completed
- **Schema (forward-only):** `mfa_credentials` (encrypted TOTP secret via Laravel
  `encrypted` cast; `UNIQUE(user_id,type)` = one authenticator per user; type
  `CHECK('totp')`; `last_used_timestep` replay guard; `user_id` FK RESTRICT) and
  `mfa_recovery_codes` (char(64) SHA-256 `code_hash`; `used_at` single-use;
  `UNIQUE(code_hash)`; index `(user_id, used_at)`). Data-dictionary entry authored
  before the migrations (Plan §13.2).
- **TOTP:** `pragmarx/google2fa` v8.0.3 (RFC 6238, constant-time `hash_equals`).
  `TotpProvider` generates CSPRNG secrets + the otpauth URI and verifies with
  `verifyKeyNewer`, persisting the matched time-step so a code cannot be replayed.
- **Mandatory-role resolution:** `MfaRequirementResolver` resolves Super
  Administrator (`is_platform_staff`) + active `merchant_admin`/`finance`
  memberships **without** `TenantContext` (checked before tenant resolution).
- **Middleware:** `EnsurePrivilegedMfa` pinned in `bootstrap/app.php` priority
  **between auth and `ResolveTenantContext`**; allowlists only the
  status/enroll/confirm/challenge/recovery-challenge + `/me` + logout routes while
  MFA is incomplete; emits `mfa_enrollment_required` / `mfa_challenge_required`.
  `RequireFreshMfa` + `StepUpAction` enum gate a *fresh* step-up for the seven
  designated business actions (+ recovery-code regeneration); window is
  `servana.mfa.step_up_window_minutes` (env, default 5).
- **Magic Link handoff:** login never asserts MFA; the assertion (`mfa_verified_at`)
  lives only in the server session, set on challenge/confirm with session-id
  regeneration, and cleared on logout.
- **API:** real `/api/v1/auth/mfa` endpoints (status/enroll/confirm/challenge/
  recovery-challenge/recovery-codes) replace the `mfa_not_enabled` placeholder;
  Form Request + rate limiters (`mfa-confirm`, `mfa-challenge`).
- **Audit:** 8 MFA cases added to the canonical `AuditEvent`; recorded via
  `AuditRecorder` with no secrets/codes/session ids; `audit:verify-chain` passes.
- **Frontend:** minimal `MfaSetup.vue` + `MfaChallenge.vue`, `authStore` MFA state
  + actions, and a UX-only router guard. No secret/recovery code in web storage.

### Work skipped / deferred (with exact owning phase)
```
- Step-up attachment to the real business routes (the routes do not exist yet;
  R3 ships the reusable RequireFreshMfa control + a test-only harness):
    billing configuration        -> Phase 20A
    refund finalization          -> Phase 18B
    period reopen                -> Phase 18B
    payout approval / mark-paid   -> Phase 20H
    M-Pesa reconciliation resolve -> Phase 20D
    backdated compensation change -> Phase 20F/20G
  Each owning phase MUST attach `RequireFreshMfa::class.':'.StepUpAction::<case>`.
- WebAuthn/passkeys and SMS/email OTP -> later security enhancement (unless
  separately authorized).
- Administrator-driven MFA reset/recovery (and any "disable MFA" endpoint) ->
  future defined security/account-recovery phase (intentionally NOT built).
- Complete per-request session/membership revocation -> R6 (REM-SESS-001).
- Idempotency on MFA mutations -> not required (no financial effect); R4 owns the
  financial idempotency store.
```

### Commands — passed / failed / skipped
```
PASSED:
  docker compose exec app php artisan migrate (mfa tables) ........... ok
  php artisan test (full backend) ............... 311 passed, 4 skipped
  php artisan test --filter Mfa* (8 suites) ......... 43 MFA tests pass
  php artisan audit:verify-chain ..................... exit 0
  composer pint --test .............................. clean (4 auto-fixed)
  composer stan (Larastan L8) ....................... no errors
  composer validate --strict ........................ valid
  composer audit --locked ........................... 0 advisories
  npm run lint ...................... 0 errors (28 pre-existing warnings)
  npm run typecheck (vue-tsc) ....................... clean
  npm run test (vitest) ............................. 77 passed
  npm run build ..................................... built
  npm run e2e (playwright) .......................... 30 passed
  npm audit --audit-level=high ...................... 0 vulnerabilities
  gitleaks detect --no-git --redact ................. no leaks
  docker build php.Dockerfile --target dev .......... exit 0
  docker build nginx.Dockerfile --target prod ....... exit 0
FAILED then fixed (recorded, not erased):
  MfaChallengeTest replay test — first run accepted a replayed code
    (verifyKeyNewer returns boolean true when oldTimestamp is null, so the
    stored time-step was 1). Fixed in TotpProvider (pass 0 for first verify);
    rerun green. A flake never erases the original failure.
  Pint — 4 style issues (import ordering) auto-fixed; Larastan — 1 nullable
    arg in AuthenticatedUserResource, fixed with an explicit `User` bind.
SKIPPED:
  4 pre-existing skipped backend tests (unchanged by R3).
```

### Known risks / residual
- TOTP acceptance window is ±1 step (±30s) for clock drift; replay is blocked
  independently by `last_used_timestep`, so a code is single-use within its window.
- No administrator MFA reset path yet — a user who loses both authenticator and
  recovery codes needs the future account-recovery phase (documented deferral).
- `actingAs(..., 'sanctum')` in the test base now provisions a confirmed MFA
  session for mandatory roles (R3 changed the precondition for privileged routes);
  MFA-state tests opt out via `withoutMfaSession()`/`statefulMfa()`.
- Timestamps use `timestamp(0)` (no tz), consistent with sibling as-built tables;
  the project-wide tz reconciliation is not owned by R3.

### Context for R4 (idempotency & replay protection)
- The MFA assertion lives in the **server session** (`mfa_verified_at`), not a
  token; R4's idempotency store is independent. Designated financial routes built
  later must carry BOTH `RequireFreshMfa` (step-up) and the R4 idempotency
  middleware — order: auth → EnsurePrivilegedMfa → tenant/branch/permission →
  step-up → validation → idempotency+transaction (Plan §9.4).
- `StepUpAction` is the central registry; R4/feature phases attach it to real
  routes. `EnsurePrivilegedMfa` runs before tenant context — keep that ordering.

## Phase R2 — Core audit completeness

- **Branch:** `phase-r2-core-audit-completeness` → merged as **PR #14**, commit `1df759e`.
- **Status:** ✅ `verified_complete` — PR #14 merged; CI Backend/Frontend/Security/Docker passed; solo-maintainer governance exception recorded ([docs/governance/solo-maintainer-review-exception-pr-14.md](governance/solo-maintainer-review-exception-pr-14.md)); `reviewDecision` intentionally blank (NOT an independent approval).
- **Proof:** [docs/proof/phase-r2.md](proof/phase-r2.md) · **ADR:** [ADR-008](architecture/adr/0008-audit-immutability-and-chain.md).
- **Register:** REM-AUD-001 (`verified_complete`).

### Work completed
- **Canonical typed catalogue:** `AuditEvent` enum (one snake_case name per
  action, central `severity()`); existing strings preserved. No free-form event
  strings in transitions.
- **AuthEventLogger replaced + deleted** (with `AuthEvent`): auth now audits to
  `audit_logs` via `AuthAuditLogger`→`AuditRecorder` (masked email, null actor;
  no token/session stored). No runtime reference remains.
- **Core event coverage** wired in actions/services: auth (request/denied/failed/
  success/logout), invitation (created/resent/revoked/accepted), membership
  (created/activated/suspended/deactivated), branch_assignment (granted/revoked),
  branch lifecycle (created/profile_updated/archived/operating_hours_updated/
  day_opened/closed/reopened), permission overrides (created/updated/revoked +
  denials), unauthorized_access. Recorded in-transaction with actor/merchant/
  branch/target/severity and old/new values for sensitive transitions.
- **Per-merchant + platform hash chains** via shared `AuditChainHasher` + pg
  advisory lock; `branch_id` added (forward-only expand migration) and hashed.
- **Verifier** `audit:verify-chain` (per-merchant + platform; tamper/forgery
  detection; no mutation; safe output; `--merchant`/`--platform` filters).
- **Masked read API:** `GET /api/v1/audit-logs(+/{id})`, `/api/v1/platform/
  audit-logs(+/{id})` — paginated, allowlisted filters/sort, `AuditValueMasker`
  server-side, `AuditLogPolicy` (read-only; branch/platform scope; foreign 404).
  Reused `audit.view_full` / `platform.audit.view` (no registry change).
- **ADR-008** + 7 Audit feature tests (30 tests). Updated 2 existing tests for
  the new recorder API / audit-to-DB move (not weakened).

### Work skipped / deferred (with owning phase)
```
- Item: Full audit coverage (financial/billing/M-Pesa/compensation/SMS/file/
  export) + flagged-event workflow (audit_flagged_events) + exceptional
  reason-gated unmasking. Owner: Phase 19.
- Item: Standalone role-change event (no endpoint yet; role captured in
  membership.created/invitation context). Owner: HR phase (15B+) / Phase 19.
- Item: Calendar-exception change events (no endpoint yet). Owner: owning
  branch/scheduling phase.
- Item: Chain-failure alerting + scheduled verification. Owner: Phase 25.
- Item: Audit dashboard/frontend. Owner: Phase 11/19.
- Item: Audit export / signed delivery. Owner: Phase 19/23.
- Item: audit_flagged_events table — NOT created (no R2 need). Owner: Phase 19.
```

### Pending CI / review / merge
- Push branch; confirm CI green; obtain PR review (or a truthful PR-specific
  governance exception); merge; then flip REM-AUD-001 → `verified_complete`.

### Known risks
- R2 redefines the chain as per-merchant + platform (Phase 8 was a single global
  chain) and adds `branch_id` to the hash — safe because no production audit rows
  exist (no deployment); `migrate:fresh` rebuilds cleanly.
- Operating-hours audit is emitted from the controller (no domain action exists
  for the weekly upsert) — inside the same transaction; a future action should
  absorb it.
- Only CORE domains are covered; financial/billing/etc. emit no audit until their
  owning phases — do not assume full coverage before Phase 19.

### Commands passed
- Container: `pint -- --test` (271), `stan` (L8, 192, 0), `php artisan test`
  **268/4** (serial+parallel), disposable `migrate:fresh --seed` (27 + seeder),
  `audit:verify-chain` (exit 0), 7 Audit filters green.
- Host: `npm run lint` (0 err/28 warn), `typecheck` (0), `test` (72), `build`,
  `npm audit` (0), `gitleaks` (clean), both `docker build` images.

### Commands failed
- `npm run e2e` first run: 1 failed / 26 passed (known `auth-magic-link` flake);
  rerun **27 passed**. Recorded in proof §7; not erased; R2 changes no frontend.
- During development: 4 initial test failures (transaction-poison on trigger,
  old recorder signature, unseeded override key, log-vs-DB assertion) — all root-
  caused and fixed; see proof §6.

### Commands skipped
- `make up`/`make fresh`/`make test` — stack already healthy; container commands
  run directly against a disposable DB to protect dev data.

### Context for R3 (Privileged MFA + step-up)
- R2 leaves the audit seam complete for CORE domains: any new privileged action
  in R3 should emit an `AuditEvent` (add a case + record in the transition). MFA
  enrollment/step-up events will be new `AuditEvent` cases. No `mfa_*` tables
  exist yet (REM-MFA-001). The pre-feature gate (§5.4) remains open.

## Phase R1 — Dependency & runtime security

- **Branch:** `phase-r1-dependency-runtime-security` → **PR #13 merged into `main`** (merge commit `8fe575f`).
- **Status:** ✅ `verified_complete`. CI Backend/Frontend/Security/Docker passed.
  Review: a documented **solo-maintainer governance exception** (PR #13;
  `reviewDecision` intentionally blank) — see
  [solo-maintainer-review-exception-pr-13.md](governance/solo-maintainer-review-exception-pr-13.md);
  **not** an independent reviewer approval.
- **Proof:** [docs/proof/phase-r1.md](proof/phase-r1.md) · **ADR:** [ADR-001](architecture/adr/0001-framework-upgrade.md) · **Notes:** [laravel-12-upgrade.md](operations/laravel-12-upgrade.md).
- **Register:** REM-DEP-001 → `verified_complete`.

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
- None. PR #13 merged into `main` (`8fe575f`); CI passed; REM-DEP-001 is
  `verified_complete` under the documented solo-maintainer exception. R2 in progress.

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
