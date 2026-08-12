# Phase UI-09 — Merchant Administrator experience checklist

This file is the persistent continuation marker for the product-owner directive. A checked item is
complete only when its evidence is committed in this branch. Plan authority: UI/UX plan §6, §7,
§9, §11–§13, §17–§21 and §25 (UI-09); ADR-016…ADR-025; Plan §9, §10.2, §10.3,
§22, §24.1 and §80. External Gate W and Phase 21N remain closed.

## Increment 1 — startup, predecessor reconciliation, readiness

- [x] Safety gate: no overlapping heavy project process; worktree initially clean.
- [x] Fetched `origin`; verified local `main` = `origin/main` = `b435f4840649687bef3d54e61424d88047a10d4b`.
- [x] Live-verified PR #58: merged, final head `2fdf8784`, merge `b435f484`, equal tree
  `60a1085a`, final CI `31403883471` five jobs successful, governance comment `5242660629`,
  zero submitted reviews (not independent approval), source branch deleted local and remote.
- [x] Created `phase-ui-09-merchant-administrator-experience` from verified `main`.
- [x] Promoted UI-08 and its fourteen merged closures to `verified_complete`.
- [x] Verified the canonical Merchant Administrator contract contains exactly 23 rows.
- [x] Inspected routes, components, stores, APIs, active/planned permissions and external gates.
- [x] Classified all 23 pages A–E in `page-readiness-matrix.json`; no class-F ambiguity exists.
- [x] Mechanically derived target: 15 `implemented` + 8 `disabled_by_gate` + 0
  `removed_by_authority` = 23; `planned` = 0.
- [x] Permission baseline: 169 total / 134 active / 35 planned; UI-09 adds no permission key.

## Increment 2 — Merchant shell and navigation

- [x] Persistent desktop sidebar at 1025 px and above.
- [x] Collapsible tablet rail at 768–1024 px.
- [x] Focus-trapped mobile drawer at 767 px and below, with Escape/backdrop close and focus return.
- [x] Six groups in binding order, exact gated reasons, contextual children absent from primary nav.
- [x] Merchant context, account switch, breadcrumbs/page header, active route and fixed footer.
- [x] Super Administrator header-shell regression tests green.

## Increment 3 — owner entry journey

- [x] `/setup` complete and transactional, including real plan/price selection and review.
- [x] `/dashboard` renders a truthful tenant-scoped ownership read model.
- [x] `/get-started` derives observed completion without granting operational mutations.
- [x] `/merchant/profile` verified and integrated at its canonical identity.

## Increment 4 — branches

- [x] `/branches` owner list/create flow, entitlement truth and no operational takeover.
- [x] `/branches/:branchUlid` owner-safe oversight; foreign ULID 404 proof.
- [x] Initial Branch Manager/HR invitation presentation reconciled.
- [x] Close `UI07-GUARD-002` Merchant Administrator presentation only after browser proof.

## Increment 5 — staff

- [x] `/staff` uses a narrow tenant-wide lifecycle projection under the existing active
  `branches.manage_users_lifecycle` authority.
- [x] No phone/client PII, no personnel contact export, no HR `staff.view` widening.
- [x] Only Branch Manager/HR invitations and authorized lifecycle actions are offered.

## Increment 6 — subscription and billing

- [x] `/subscription`, `/subscription/plan`, `/subscription/invoices` canonicalized.
- [x] `/subscription/invoices/:invoiceUlid` renders the existing scoped invoice read/download.
- [x] Payment Attempts and Billing Recovery remain inert behind exact External Gate W reason.

## Increment 7 — reports

- [x] All five report entries remain visible and inert behind exact External Gate W reason.
- [x] No route, component, zero metric or export is fabricated.

## Increment 8 — compensation and approvals

- [x] `/compensation` canonical summary.
- [x] `/compensation/payout-approvals` is owner approval only and requires fresh step-up to mutate.
- [x] `/finance/period-reopen-approvals` canonical owner exception workflow.

## Increment 9 — utility

- [x] Notifications remains inert behind External Gate W.
- [x] `/account` reconciles own identity, sessions, MFA and theme preference.
- [x] Shared `/help` remains outside the 23-page count.

## Increment 10 — focused proof and activation

- [x] Focused UI-09 component/backend/browser suites green.
- [x] Seven-width responsive matrix and 200% zoom prove no horizontal overflow.
- [x] Axe serious/critical = 0 in representative light/dark states.
- [x] Canonical map activated once; generated projections refreshed once.
- [x] Final 15/8/0/0 disposition, global 160, seven-account semantic no-diff.

## Increment 11 — production image / canonical host

- [x] Rebuilt only invalidated final images: PHP production
  `sha256:90eeff392d1420c75606a4afd79d10143b76a9729e24dd81d825076b7d0162cb` and Nginx/static
  `sha256:247251061fa4b80c0bd8783af53f393e3628c1fc21318bf4c3473d2ad0f3ac4d`.
- [x] Nginx syntax, `servana.ke`, canonical routes, wrong-account hosts and no source maps proved.
- [x] Disposable proof resources removed.

## Increment 12 — final gates, evidence, one commit, push

- [x] Composer validate, Pint, Larastan level 8, successful serial gate and corrected parallel backend suite.
- [x] ESLint, vue-tsc, Vitest, production build, focused UI-09 and full Playwright.
- [x] Image/canonical-host, audits, dependency scans, gitleaks, `git diff --check`, `git fsck --full`.
- [x] Deliberate creative/product-design review, targeted refinements and regenerated visual evidence.
- [x] Final proof, PROGRESS, CHANGELOG and traceability updated for truthful `local_complete` state.
- [x] One local-completion changeset: `ui-09: implement merchant administrator experience` (this
  commit; no checkpoint commit preceded it).
- [x] Initial changeset pushed and PR #59 opened against `main` at `0142d0a`.
- [x] Initial CI `31614642395` investigated: Frontend and Backend independently named the same stale
  UI-06 generated route projection; no second backend defect was hidden in the aggregate.
- [x] `UI09-CI-001` corrects only that generated consumer and records focused regression proof.
- [ ] Push the corrective commit, obtain five-job exact-head CI, record governance, squash merge and
  synchronize clean `main` (no UI-10, backend Phase 25 or Gate-W activation).
