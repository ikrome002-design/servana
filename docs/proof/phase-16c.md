# Phase 16C — Service Sessions and Preferred Personnel — Proof

**Branch:** `phase-16c-service-sessions` · **Base commit:** `af79b56` (verified
Phase 16B merge, PR #27). **Status:** `verified_complete` — PR **#28** MERGED into
`main` (squash merge `ffe37cc`, 2026-06-30; implementation `1d2aee5`; remediations
`81506da` + `ac5751a`; final governance head `79746bb`; final CI run `28449140384`
all five required checks SUCCESS; reviewDecision blank under the solo-maintainer
governance exception — not independent approval; see the PR #28 section below).
This file records the controlling decisions, the four specification-gate
resolutions, and the gate evidence as each slice was verified. CI is authoritative
for the Linux browser/Docker/gitleaks gates; local Windows Playwright was not
claimed as a pass (Phase 15B/16A/16B precedent). Tests run against PostgreSQL 16
(never SQLite).

Times are branch business time in `Africa/Nairobi`; timestamps are UTC. Frontend
visibility is UX only — the API (policies + `EnsureBranchScope` +
`EnsurePermission` + the state machines + scheduling/eligibility gates) is the
security boundary.

---

## Git integrity & environment (verified at start)

- Branch `phase-16c-service-sessions` created from `af79b56`; HEAD = origin/main =
  `af79b56`; divergence `0 0`; working tree clean; `git fsck --full` clean.
- Damaged repo `…/Servana-damaged-git-2026-06-30` exists, preserved, **not** in use.
- Docker stack healthy; PHP 8.3.31; Laravel 12.62.0; PostgreSQL 16; Redis 7; all
  prior migrations Ran.

## Phase 16B lifecycle reconciliation (done first)

Phase 16B shipped as **PR #27**, MERGED into `main` (squash merge `af79b56`,
2026-06-30; original implementation `6a9fbcc`, final head `6272f080`). The initial
CI run `28420643751` (head `6a9fbcc`) **FAILED** Backend — 8 failed / 4 skipped /
751 passed — with `Call to undefined function createWalkIn()`: the helper was
defined file-locally inside `QueueApiTest.php` and was therefore not reliably
available to the independent parallel Pest workers. The targeted correction moved
`createWalkIn()` into `tests/Pest.php`, removed the file-local definition and the
unused `QueueApiTest` import, and preserved parallel execution. The final CI run
`28425875550` (head `6272f080`) reported five required checks — Backend, Frontend,
Docker, Security, E2E — Playwright — all SUCCESS. `reviewDecision` blank under the
documented solo-maintainer governance exception (not independent approval).

Records reconciled: `docs/PROGRESS.md` (summary table + Phase 16B section),
`docs/CHANGELOG.md`, `docs/proof/phase-16b.md` (header), `docs/traceability/servana-requirements.csv`
(`SRV-QUEUE-001` → `verified_complete`), and `docs/remediation/register.yaml`
`last_updated` (stale lifecycle note only — no new remediation item for an ordinary
feature-phase CI correction). **Phase 16B → `verified_complete`. REM-PERM-001
remains open (Phase 19).**

---

## Specification-gate resolutions (controlling sources)

### Gate A — direct appointment session source → RESOLVED (no `appointment_id`)

**Competing requirements.** The Plan §80 roadmap mentions an appointment
`checked_in → in_service`; the canonical §13.7 `service_sessions` summary names only
`queue_entry_id nullable` (no `appointment_id`).

**Controlling source (repository state-machine spec + canonical schema summary).**
`docs/architecture/state-machines/appointment.md` (lines 38–41, 113–117) explicitly
states `in_service`/`completed` are **not** added to the appointment aggregate —
"Queue Entry / Service Session own those" — and `confirmed → checked_in` creates
"NONE (no queue entry — 16B; no service session — 16C)". A checked-in appointment
reaches a session through the already-shipped 16B `checked_in → queued` transition,
which creates exactly one `queue_entries` row with `appointment_id` set; the queue
lifecycle (`called → in_service`) then creates the session.

**Decision.** Every service session links via `queue_entry_id`. Appointment
provenance is preserved through `queue_entries.appointment_id`. **No** `appointment_id`
column on `service_sessions`; **no** direct appointment → session route; **no**
appointment `in_service`/`completed` state added; **no** silently-created queue
entry for a direct appointment (the existing `checked_in → queued` workflow already
owns that). Migration impact: one nullable `queue_entry_id` FK (+ composite-merchant
FK + `UNIQUE`), no appointment linkage column.

### Gate B — service identity → RESOLVED (snapshot `service_id` from the source)

The canonical summary does not name `service_id`, but the data dictionary is
authorised to determine "service identity" and Phase 16C requires eligibility "per
service item." The queue entry already carries `service_id`. **Decision:** add an
immutable `service_id` to `service_sessions`, snapshotted from the **locked** source
queue entry inside the start transaction, with a `(service_id, merchant_id)→services`
composite FK. This gives DB-safe merchant consistency, unambiguous eligibility
validation, and clean audit ULIDs without a speculative service-item collection
(the product supports one selected service per queue entry/appointment).

### Gate C — session cancellation vs. queue source state → RESOLVED (conservative; deferred in-progress abort)

The Service Session machine permits `in_progress → cancelled`; the Queue Entry
machine defines no `in_service → cancelled`. **Decision:** the four-state machine
defines and unit-tests `in_progress → cancelled`, but the 16C cancel action/route
permits cancellation only where it does not strand a queue entry (effectively
`pending`, plus any future `queue_entry_id IS NULL` direct path). Exposing
in-progress cancellation for a queue-linked session would strand the queue, mark it
completed (wrong for an aborted service), or require an undocumented queue
transition — all forbidden. **Workflow-level in-progress abort is explicitly
deferred** pending an authoritative Queue Entry `in_service → (cancelled|aborted)`
extension. **Recommended product decision:** a future scheduling correction adds a
queue `in_service → aborted` (or `→ cancelled`) transition so Front Office can abort
an in-progress service while keeping both aggregates coherent; until then the only
exit from an in-progress service is completion. No queue transition invented.

### Gate D — commission-preview data source → RESOLVED (typed non-payable preview)

No `commission_rules`/compensation-plan/`commission_ledger` tables exist (Phases
20F/20G). **Decision:** completion returns a typed `CommissionPreviewResult` =
`preview_status: unavailable, reason: compensation_not_configured, earned: false,
payable: false`. "Not configured" is never a zero amount; salary-only personnel are
`not_applicable`. No preview writes any ledger/rule/plan. Frontend wording: **"Preview
— not earned or payable."** Only validated payment (later workflow) creates earned
commission.

---

## Implementation evidence (accumulated per slice)

### Schema (PostgreSQL 16)

`php artisan migrate` applied `2026_06_30_000001_create_service_sessions_table` in
2s; `\d service_sessions` confirms the four-state CHECK, the status/timestamp
coherence CHECKs, the partial-unique `service_sessions_active_staff_unique` (WHERE
status IN pending/in_progress), `UNIQUE (queue_entry_id)`, `UNIQUE (id, merchant_id)`,
and all five composite-merchant FKs (merchant_branches CASCADE; queue_entries/clients/
services/staff_profiles RESTRICT). `migrate:fresh --seed` is green on PostgreSQL 16
with the PermissionSeeder picking up the new canonical keys.

### Backend tests (PostgreSQL 16)

- `tests/Feature/ServiceSession/*` — **56 passed** (Schema 13, StateMachine 22 via
  valid/invalid transition data providers + terminal immutability + envelope,
  Coupling 8, Api/authz/isolation/own-scope/masking 10, BranchClosure/busy 5, Audit 4).
- `QueueApiTest` — **17 passed** (the 16B lifecycle test now asserts the real 16C
  coupling: start creates+starts the session, complete completes both aggregates and
  returns the non-payable preview, and no `invoices` table exists).
- Full parallel Pest suite — **812 passed / 7 skipped / 0 failed** (3717 assertions).
- Coverage/contract/boundary: `RouteSecurityContractTest`, `TenantColumnCoverageTest`,
  `ModelTenancyTraitCoverageTest`, `MigrationManifestTest`, `TenancyStaticAnalysisTest`,
  `OpenApiContractTest`, `OpenApiTypeParityTest`, `AuthorityBoundariesTest`,
  `ForbiddenRouteAbsenceTest` (no SA merchant-create, no Personnel contact-export),
  `PermissionMatrixTest` — all green.

### Frontend

- Vitest — full suite **183 passed**; 16C +12 (`ServiceSessionList` 4,
  `MyServiceSessions` 3, `serviceSession` util 5). Nav + inventory snapshots
  regenerated; the FO preview spec asserts the "Preview — not earned or payable"
  wording and that "not configured" carries no monetary amount.
- `vue-tsc --noEmit` clean; `eslint .` **0 errors** (37 pre-existing warnings in
  unrelated DashboardStub files); production `vite build` OK.
- Playwright `tests/e2e/service-session.spec.ts` (FO preview wording, light+dark axe
  serious/critical = 0, 360px no-overflow; Personnel own-scope) — **Linux CI is the
  authoritative browser gate; local Windows Playwright is not claimed** (15B/16A/16B
  precedent).

### Contracts & security

- OpenAPI: `servana:openapi` wrote **96 production routes**; regenerated twice →
  **byte-identical** (deterministic). TS contract regenerated; `api:contract:check`
  **OK — 81 paths, 96 operations**.
- `composer pint --test` clean (4 issues auto-fixed on new code); Larastan level 8
  **No errors** (3 issues fixed on new code).
- `composer audit` clean; `npm audit --audit-level=high` no high/critical (2 moderate,
  pre-existing); `gitleaks detect` **no leaks** (38 commits scanned);
  `docker compose config` valid.

### Local environmental limitations

Local Windows Playwright is not claimed as a pass (Linux CI authoritative). All other
gates ran to completion locally with the results above.

## Solo-Maintainer Review Exception — PR #28

An independent second reviewer was unavailable because the repository currently
has one eligible maintainer. The product owner authorized a PR-specific
governance exception instead of fabricating approval.

Evidence:

- PR: #28
- verified implementation head: ac5751aa7a643438118a23c2d5817a04eef9ad8a
- CI/Backend: passed
- CI/Frontend: passed
- CI/Docker: passed
- CI/Security: passed
- CI/E2E — Playwright: passed
- GitHub reviewDecision: intentionally blank
- governance record:
  docs/governance/solo-maintainer-review-exception-pr-28.md

The completion preview remains explicitly not earned and not payable. No
commission ledger, invoice, payment or receipt record is created by Phase 16C.

The queue-linked in-progress abort workflow remains deferred pending an
authoritative Queue Entry state-machine extension.

This exception applies only to PR #28 and is not independent reviewer approval.
