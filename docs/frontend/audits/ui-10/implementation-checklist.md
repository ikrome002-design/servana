# Phase UI-10 — Branch experience checklist

Persistent session-resume marker for `phase-ui-10-branch-experience`. Authority: UI/UX plan §§2,
6–7, 9, 11–13, 15, 17–21, Phase UI-10 and Appendix A §7; Development Plan §§3.1, 9, 14–25,
33–49, 66–70 and 80.2. External Gate W, 20D-W and 21N remain closed.

## Session state

- Branch/base: `phase-ui-10-branch-experience` / `84b7f803ea06124772eb87252552bb54e2a41416`; no checkpoint commit.
- UI-09 reconciliation: `verified_complete`; PR #59, final head `59d10333…`, final run
  `31618625302`, governance comment `5269937398`, squash `84b7f803…`, equal tree `3077b246…`,
  zero reviews and no independent approval.
- Starting process constraint: an existing Vite development server listens on 127.0.0.1:5173;
  no Pest, Playwright, Composer, Docker build or test worker was active.
- Baselines: 160 authenticated pages; Branch 18; permissions 169 total / 134 active / 35 planned;
  OpenAPI 337 operations; Gate W closed.
- Dirty paths after predecessor reconciliation: 8, all UI-09 lifecycle/PROGRESS/CHANGELOG/
  traceability records. No unrelated path.

## Increment 1 — startup, predecessor reconciliation, readiness

- [x] Mandatory Git/process gate; clean synchronized `main` at UI-09 merge.
- [x] Live UI-09 PR/run/comments/reviews/branch/tree verification exactly once.
- [x] Create the authorized phase branch; no checkpoint commit.
- [x] Promote UI-09 proof, checklist, fifteen closures, PROGRESS, CHANGELOG and traceability.
- [x] Inspect the exact 18-row canonical Branch contract, runtime routes/pages/stores, API routes,
  policies and permission catalogue.
- [x] Stabilize `page-readiness-matrix.json`: A=4, B=1, C=5, D=5, E=3, F=0.
- [x] Mechanically derive target disposition: 15 implemented + 3 disabled_by_gate + 0
  removed_by_authority = 18; planned = 0.
- [x] No class-F ambiguity. No permission addition/activation is authorized or required.
- [x] Run focused lifecycle/traceability checks after the initial audit artifacts exist.

## Remaining increments

- [x] Branch shell and exact eight-group navigation.
- [x] Dashboard and observed/resumable Branch Get Started.
- [x] Branch Profile, combined Operating Calendar and Branch Day workspace.
- [x] Service Catalogue and Staff/Availability read-only workspace.
- [x] Queue and Appointments read views at canonical routes.
- [x] Invoices, Payment Records and Receipts read views; Cash-Up maker-only workflow.
- [x] Branch Audit; keep Reports, Subscription Payment and Notifications inert behind exact gates.
- [x] Own Account/Preferences.
- [x] Canonical activation/generation, focused backend/component/browser/security proof.
- [x] Creative review, responsive/a11y/theme matrices and targeted refinements.
- [x] Source freeze, final heavy-gate cycle, historical restoration, production-host proof and
  security/integrity scans.
- [x] Local-completion commit `c73d1be…`, push and exact non-draft PR #60.
- [x] Preserve initial exact-head run `31665290449`: Docker/Security/E2E success; Frontend/Backend
  failed only because the UI-06 current public-route projection was restored as frozen.
- [x] Push UI10-CI-001 correction, obtain replacement exact-head five-job CI, truthful governance,
  squash merge, branch cleanup and clean synchronized `main`.

## Current disposition and next action

- Implement: Dashboard, Get Started, Branch Profile, Operating Calendar, Branch Day, Service
  Catalogue, Staff/Availability, Queue, Appointments, Invoices, Payments, Receipts, Cash-Up, Branch
  Audit and Account.
- Disabled by gate: Branch Reports (21N report catalogue), Subscription Payment and Recovery
  (20D-W/External Gate W), Notifications (21N/20D-W dependency).
- OpenAPI delta: generated **337 → 341** operations: Dashboard/day workspace, invoice visibility,
  payment visibility and audit visibility.
- Permission delta: **0 added / 0 activated**. Existing active `branch.dashboard.view`,
  `branch.profile.manage`, `branch.calendar.manage`, `day.open_close`, catalogue keys,
  `branch.cash_up.submit`, `receipt.view` and `reports.view` only where their current scope permits.
- Skipped owners: UI-11 HR experience after UI-10 lifecycle; UI-16 formal cross-account visual
  baselines after UI-08…UI-15; Phase 25 deployment under separate authorization; 20D-W after Gate W;
  21N after 20D-W; `UI07-ENV-001` by scheduling/service-session test owner before Phase 25.
- Focused proof: PostgreSQL 4 tests / 57 assertions; typecheck green; focused Vitest 32/32;
  dedicated Playwright 52/52 (145.6s), seven widths + 200%, axe serious/critical 0.
- Visual refinement: Branch Day human-facing blocker labels, Account experience labels and exact
  viewport screenshot evidence; no invented metric or parallel design system.
- Final backend: Pint 1,892 clean; Larastan 1,448 / 0; PostgreSQL parallel **2,976 passed / 14
  skipped / 48,859 assertions**. Final frontend: ESLint 0 errors / 477 warnings; typecheck clean;
  Vitest 135 files / 1,371 tests; production build 1,277 modules.
- Whole-product browser: authoritative **1,325/1,325** in 46.8m. Preserve the first full-run
  1,314/1,322 stale-consumer RCA and replacement 1,324/1,325 cold-route RCA.
- Historical evidence: 54 exact predecessor files restored; 124 exact accidental UI-01 images
  removed; zero frozen-path drift. UI10-CI-001 then proved UI-06 `public-route-matrix.json` is a
  current projection; regenerate it and exclude only that path from frozen restoration.
- Production proof: PHP `56c853a1…`, Nginx `fd348f4d…`; Nginx syntax and 38/38 no-volume host proof
  green; disposable topology removed. Composer/npm 0; gitleaks no leaks; Git integrity green.
- Defects: UI10-DATA-001, UI10-NAV-001, UI10-AUTH-001, UI10-RESP-001, UI10-VIS-001,
  UI10-TEST-001…005 and UI10-CI-001 are `verified_complete`; the late traceability consumer
  correction passed 15/15 and replacement exact-head CI passed all five jobs.
- Lifecycle closure: PR #60 merged at `2026-08-13T05:04:49Z`; final source head
  `e5f2a6fb3201e8369333569618ed11a3d8c56ac0`; squash
  `d7988dca90ad8694dee4c0b066f2581155fcb40a`; source/merge tree
  `0a75deddaba345c9d501605fd96c0c4108d41708`; final run `31667335687` had Backend,
  Frontend, Docker, Security and E2E all successful. Governance comment `5276187980`; zero
  submitted reviews and blank review decision, therefore no independent approval. The local and
  remote source branch are absent. Reconciled live exactly once during UI-11 startup.
