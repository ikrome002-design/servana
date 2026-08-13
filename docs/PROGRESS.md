# Servana — Build Progress

Tracks the **active v4 roadmap (Plan §§79–80)**: Phase V (as-built verification)
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

## Active v4 roadmap

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
| 16C | Service sessions | ✅ `verified_complete` — PR #28 MERGED into `main` (squash merge commit `ffe37cc`, 2026-06-30; implementation commit `1d2aee5`, remediation commits `81506da` + `ac5751a`, final pre-merge governance head `79746bb`). Initial CI run `28445709595` FAILED E2E (ambiguous Playwright "My sessions" text locators resolved to multiple elements — accessibility + own-scope browser cases failed with NO backend/business-rule/accessibility-gate relaxation), remediated `81506da` (disambiguate session headings); second CI run `28446579933` FAILED E2E (Personnel read-only assertion counted every page button rather than the session workflow controls — global layout controls caused a false failure), remediated `ac5751a` (scope the controls assertion); corrected CI run `28448569188` (head `ac5751a`) SUCCESS; final CI run `28449140384` (head `79746bb`) — five required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS. reviewDecision blank under the documented PR-specific solo-maintainer governance exception — not independent approval. **REM-PERM-001 remains open** (Phase 19). See Phase 16C section. |
| 17 | Invoicing | ✅ `verified_complete` — PR **#29** `Phase 17: Implement invoicing` MERGED into `main` (squash merge commit `6557469`, 2026-07-01; implementation commit `c0fdd83`, governance commit / final PR head `3c4e309`). Initial CI run `28516753439` (head `c0fdd83`) all five required checks (Backend, Frontend, Docker, Security, E2E — Playwright) SUCCESS; final CI run `28517236474` (head `3c4e309`) — same five required checks SUCCESS. `reviewDecision` blank under the documented PR-specific solo-maintainer governance exception — **not** independent reviewer approval. **REM-PERM-001 remains open** (Phase 19). See Phase 17 section. |
| 18A | Merchant-client payment recording (Correction 18) | ✅ `verified_complete` — PR **#30** `Phase 18A: Implement payment recording` MERGED into `main` (squash merge commit `4a489d0` = `4a489d04156aec8348eda9a968f830da31668c87`, 2026-07-02; implementation commit `baa3678`, local-completion documentation commit `24ae7e8`, CI-correction commit `aef8d51`, governance / final PR head `0e36641`). Initial CI run `28574550657` (head `aef8d51`'s predecessor) FAILED (Backend Pint style issue in `PaymentRecordingGroupResource.php`; E2E body-copy assertion), corrected without weakening any behavior/assertion/role boundary; corrected-head CI run `28575564965` SUCCESS (Docker failed once on the same head and passed on rerun without a product-code change); final governance-head CI run `28576226830` — five required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS. `reviewDecision` blank under the documented PR-specific solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-30.md`) — **not** independent reviewer approval. **REM-PAY-001 remains open** (spans 18A+18B; closes when 18B merges). **REM-PERM-001 remains open** (Phase 19). See Phase 18A section. |
| 18B | Payment validation, receipts, refunds, cash-up, period locks | ✅ `verified_complete` — PR **#31** `Phase 18B: Implement validation receipts and finance controls` MERGED into `main` (merge commit `64bd0a117dcdc819a8baf4b9bec3c3eb09635edc`; implementation `ed07c8b`, CI-correction `a0d4dede7ce62e5dbcb7a27467b15ba592ccf6d3`, governance `a8f988b68872eb3e352bc7f70dbb362bfb320cf3`). CI: initial run `28694148176` FAILED, corrected-head `28695121157` SUCCESS, final governance-head `28695314469` SUCCESS. `reviewDecision` blank under the documented PR-specific solo-maintainer exception (`docs/governance/solo-maintainer-review-exception-pr-31.md`) — **not** independent reviewer approval. **REM-PAY-001** closed `verified_complete` on this merge. **REM-PERM-001** stays open (Phase 19). Merge-time local gates (see `docs/proof/phase-18b.md` §Quality gates): backend serial 1065 pass / 7 skip / 5175 assertions + parallel 1065 pass / 7 skip / 5175 assertions (PG16); Pint 877 clean + Larastan L8 no-errors (667); OpenAPI 152 ops / 130 paths + TS + contract OK; frontend Vitest 222 / 54 files, ESLint 0 errors, vue-tsc clean, build OK; Playwright four 18B specs 29/0 + full e2e 227/0 (360/768/1280 + light/dark + keyboard + axe serious/critical = 0); composer audit no advisories, npm audit high-gate exit 0 (2 moderate js-yaml below gate), gitleaks no leaks; php dev + nginx prod images build. Defects fixed pre-merge: DEF-18B-003 (360px branch-cash-up overflow → responsive method cards) + DEF-18B-004 (cash-up component-test clock-coupling). All 11 slices implemented; full slice detail in `docs/proof/phase-18b.md`. |
| 19 | Audit logging completion & flagged events | ✅ `verified_complete` — PR **#32** `Phase 19: Complete audit logging and flagged events` MERGED into `main` (merge commit `7ef259e28f51fc9bba24a16ef3945ff61ddef4ce`, merged at `2026-07-05T11:48:45Z`; head branch `phase-19-audit-flagged-events`, base `main`; final PR head `d6455f3`). CI run `28736716360`: five required checks all **SUCCESS**. `reviewDecision` blank under solo-maintainer governance exception — **not** independent approval. Local + remote Phase 19 branches deleted. **REM-PERM-001** and **REM-AUDEXP-001** → `verified_complete`. See Phase 19 section. |
| v4 adoption | Servana v4 plan-adoption architecture change (Plan §1.3) | ✅ `verified_complete` — **PR #34** "docs: update Servana software development plan" MERGED into `main` (merge commit `85bd3e570db1436586d3d1ead17ab6b1701538d5`, merged `2026-07-10T07:52:27Z`; head `docs/update-servana-development-plan`, base `main`). Five required CI checks all SUCCESS; `reviewDecision` blank under the solo-maintainer governance exception — **not** independent approval. Gate A (Phase 20A entry) satisfied. |
| 20A | Plan catalogue, prices, entitlements, billing settings, preferred-personnel fee rules (platform) | ✅ `verified_complete` — **PR #35** "Phase 20A: Implement billing catalogue settings and fee rules" MERGED into `main` (squash merge `6813690ef5fa9f7d782532b49e2bca43c2afc112`, impl head `a31cd00…`, final PR head `56a81bd…`, merged `2026-07-11T07:56:09Z`); five-gate CI (Backend/Frontend/Docker/Security/E2E—Playwright) all SUCCESS; `reviewDecision` blank under the documented solo-maintainer governance exception — **not** independent approval. (Reconciled from `local_complete` during Phase 20B Increment 1.) Increments 1–7 COMPLETE + green; branch `phase-20a-billing-catalogue-settings` off `85bd3e5`. **Frontend (Increment 5):** single `platform-billing-settings` screen (tabbed: settings/plans/prices/entitlements/preferred-fee), 5 Pinia stores, Branch-Manager read-only fee card in `branch.services`, nav/inventory/§27.1-spec reconciled, contract-truth nullability fix. **E2E (Increment 6):** `phase-20a-billing.spec.ts` 17/17 + full e2e 269 (axe 0 light/dark, 360/768/1280, keyboard). **Gates (Increment 7):** backend serial 1164/7-skip + parallel 1164/7; Pint 1040 clean; Larastan L8 clean; OpenAPI 188 routes/157 paths/188 ops + TS + permissions + contract OK; Vitest 279; composer audit clean; npm audit 2 moderate (below gate); gitleaks clean; php-dev + nginx-prod build. Single completion commit pending; then push + STOP (no PR/merge, no 20B). Older detail: Docker blocker RESOLVED. Increments 1–3 COMPLETE + green on PG16 (specs; 6 migrations; enums; models/factories; legacy backfill @ `DATE '2026-07-10'`; resolvers + resolver swap). **Increment 4 runtime layer done + green** (13 AuditEvent cases; 2 state machines + BillingStateException + BillingOverlapException; BillingStateMachineTest 36; **12 actions**; **6 policies** registered; **8 Form Requests**; **6 masked Resources**; **7 controllers**; `ResolvePlatformContext` middleware + `PlatformServiceLocator`). Larastan clean; RouteSecurityContract/AuditMutationCoverage/permission guards green with the unwired layer (37 + 89 targeted). **Increment 4 COMPLETE + green** (atomic flip done): 21 platform routes + 1 branch read wired (ResolvePlatformContext group; platform_mutation forbids ResolveTenantContext — solved); AuditMutationCoverage (12 routes); StepUpAction::BillingConfiguration moved to live; PermissionRegistry +9/−3; matrix flip (9 active, 3 legacy deleted); active 87→93, legacy 17→14, planned 86→77; OpenAPI 157 paths/188 ops + TS + permissions regenerated; `Phase20APlatformApiTest` 13 tests (context/plans/prices/overlap/fee-rule lifecycle/step-up/branch-read-masked). **Auth 192 pass; RouteSecurityContract/AuditMutationCoverage/OpenApi green; billing+phase20a 96 pass; Pint clean; Larastan clean.** **Increments 5 (frontend platform-billing-settings), 6 (E2E/a11y), 7 (full gates + single commit) pending.** **Branch-rule correction recorded:** `preferred_personnel_fee.view_branch_rule` = branch_manager/branch/read-only/no-MFA/no-step-up/info (not super-admin). See Phase 20A section + proof. |
| 20B | Subscription lifecycle & subscription invoices | ✅ `verified_complete` — **PR #36** "Phase 20B: Implement subscription lifecycle and invoices" MERGED into `main` (squash merge `3dd528a2779a44d13b9fe105ac9ee49e688e84c6`, implementation head `6790081bace7efb2a659ec8254e6eda53d3d5935`, governance/final PR head `4a998dc6e4c0f8259c8d6c179c076f8b8496aec9`, merged `2026-07-12T06:57:28Z`); CI initial `29183137798` + final `29183286205` — five required jobs (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS; `reviewDecision` blank under the documented solo-maintainer governance exception — **not** independent approval; local + remote branches deleted. (Reconciled from `local_complete` during Phase 20C Increment 1.) See Phase 20B section. |
| 20C | Promotions & free-period offers (platform) | ✅ `verified_complete` — **PR #37** "Phase 20C: Implement promotions and free periods" MERGED into `main` (squash merge `735f419bf72fdd9be3f95c4507e8925c1ed0859e`, implementation commit `782c97313ea988d2263e35d44c325d2c7ccb25ec`, governance/final PR head `efe0f74afe23fa8f3d3acfdd363c1328520cade8`, merged `2026-07-12T11:50:45Z`); CI initial `29191160816` (head `782c973…`) + final `29191381748` (head `efe0f74…`) — five required jobs (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS; `reviewDecision` blank under the documented PR-specific solo-maintainer governance exception — **not** independent approval; local + remote branches deleted. (Reconciled from `local_complete` during Phase 20E Increment 1.) See Phase 20C section + `docs/proof/phase-20c.md`. |
| 20E | Percentage platform-fee engine (financial; Corrections 2/4/8) | ✅ `verified_complete` — **PR #38** "Phase 20E: Implement percentage platform fee engine" MERGED into `main` (merge commit `c0881993ae0c59536013c9b84e182e5000fa1e11`, implementation commit `f6e208a90513bf5ca1c219c456b263ea0d111c5c`, governance / final PR head `24d1cad60539fe40596125240391c48a1b821246`, merged `2026-07-14T06:19:43Z`); final CI run `29310753740` — five required jobs (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS; `reviewDecision` **blank** under the documented solo-maintainer governance exception — **not** independent reviewer approval; local + remote `phase-20e-percentage-platform-fees` branches deleted. (Reconciled from `local_complete` during Phase 20F Increment 1.) See Phase 20E section + `docs/proof/phase-20e.md`. |
| 20D-W | Wallet-orchestrated billing collections | ⛔ Blocked — **External Gate W CLOSED** (§80.2). `docs/integrations/wallet/gate-w-evidence.md`, `docs/integrations/wallet/`, and `docs/integrations/` are absent: no Wallet Servana Collections Slice evidence, sandbox service-account credentials, pinned Wallet OpenAPI hash, passing contract suite, sandbox STK/C2B transcript, signed-webhook transcript, reconciliation transcript, or explicit PASS. Re-evaluate when Gate W opens; 20D-W is **not** started. |
| 20F | Compensation plan setup & commission rules (HR; Correction 19) | ✅ `verified_complete` — **PR #39** "Phase 20F: Implement compensation plan setup" MERGED into `main` (squash merge `f4bc664b7ba77476f9db01dcb0ec1a526dc20538`, implementation commit `a42e13e66413a27020a07180d1fb7a8b7cda2f27`, merged `2026-07-17T12:11:44Z`); because #39 landed as a **squash** merge, `a42e13e…` is not an ancestor of `main` — the squash commit `f4bc664` carries the work, and `origin/main` == `f4bc664`. Branch was off `c088199…` (= the Phase 20E PR #38 merge). (Reconciled from `local_complete` on the `hardening/resource-contracts-and-accessibility-tokens` branch, per the established convention that the next branch reconciles the previous phase.) The two **deferred follow-ups** recorded below are now discharged by that hardening branch — see `docs/proof/post-20f-deferred-hardening.md`. **Increments 1–6 COMPLETE + green. Increment 6 full local gates:** composer validate OK; Pint 1334 PASS; Larastan L8 no errors (1029 files); **full backend serial 1469 passed / 7 skipped / 0 failed / 8644 assertions** and **`--parallel` identical 1469/7/0**; ESLint 0 errors; vue-tsc clean; Vitest 404/84 files; build PASS; **full Playwright 364 passed**; axe serious/critical = 0 (list + all three dialogs, light + dark); OpenAPI **207 paths / 248 operations**, `api:contract:check` OK, `permission-types --check` up to date, **generator determinism proven by identical hashes across baseline + two regeneration passes**; composer audit clean; npm audit 2 moderate (exit 0, below gate); gitleaks no leaks; Docker dev app + prod app + prod nginx all built. **Disposable PG16 proof re-run:** `servana_p20f_i6_proof` on PostgreSQL 16.14 — 99 migrations, 3 Phase 20F migrations, 3/3 tables, 1 EXCLUDE, 4 triggers, 0/0/0 rows after seed, **0 forbidden 20G/20H tables**, database dropped, dev DB untouched. Independently eligible: depends only on merged HR/staff substrate (Phase 8/15B) and the merged Phase 20A preferred-personnel-fee substrate; **not** blocked by Gate W. Configuration only — no ledgers, no earning, no payouts. **Increments 1–5 COMPLETE + green.** **Increment 5 (frontend):** exactly one screen — **HR Compensation** (`hr.compensation` → `/hr/compensation`, `BranchLayout`, HR, branch-scoped, `compensation.plan.view`) with `compensationStore` (Pinia, typed from the **generated** `api.ts`; one named transition per verb, **no generic status setter and no supersede action**; local state written only from server responses; `$reset` + `branchIds` watch clear stale branch data; no authoritative money computed client-side); nav `planned → live` + inventory `planned → implemented` in their **source** files with `role-navigation.yaml`, `inventory.yaml` and the §27.1 spec regenerated by their own generators/tests (no generated file hand-edited); `requiresPermission`'s first caller in the SPA. **Contract-truth fix (DEF-20F-015):** `vue-tsc` proved the OpenAPI generator infers nullability from an explicit `=== null ? null :` ternary but **not** through `?->`, so seven genuinely-nullable Phase 20F resource fields were published non-nullable; corrected with **no runtime change** (JSON byte-identical), counts unchanged **207/248**. The same Phase 20E `PlatformFeeConfigurationResource` drift is recorded **out of scope**. **Accessibility (DEF-20F-019, serious/WCAG 2 AA 1.4.3):** `--color-brand-deep` and `--color-warning` are deliberately not dark-overridden, so HR Compensation's headings/badges and the shared `SvModal` title rendered at 1.07–2.14:1; all fixed (the `draft` badge correctly keeps `bg-cream text-brand-deep`), the axe coverage gap that hid it closed (all five badges + all three dialogs, both themes), and the shared `SvModal` change proven regression-free by the **full** Playwright suite. **Deferred follow-ups (product-owner decision — NOT Phase 20F work, NOT blockers, each needs its own authorized branch/PR after 20F local completion):** (1) **repo-wide nullable Resource/OpenAPI truth sweep** — a read-only Increment 6 audit measured **92 nullable Resource expressions across 29 Resources**, all declared `"type": "string"` + `required: true` while the server can emit `null`; correcting them changes `api.ts` for unrelated SPA consumers and likely cascades into `vue-tsc` failures elsewhere, so **0 of 92 were fixed here** — `PlatformFeeConfigurationResource` explicitly included, since fixing only it would still fold a Phase 20E change into the Phase 20F commit while leaving 88 wrong; (2) **repo-wide dark-mode heading/warning-badge contrast sweep** — ~109 `text-brand-deep` vs ~115 `text-heading` under `pages/**` plus `StaffList.vue` badges; a blanket replace would be wrong because `text-brand-deep` is correct on orange/cream backgrounds, so it needs per-screen judgement and dedicated axe coverage. **Not built (enforced by tests, not asserted):** Merchant-Administrator compensation summary (20H, planned, no route), Branch-Manager compensation configuration, Personnel earnings statement, Finance liability screen, commission/salary ledger UI, payout UI, Wallet/provider UI. Gates: ESLint **0 errors**, vue-tsc **clean**, Vitest **352 → 404** (+52), `api:contract:check` **OK 207/248**, build PASS; backend reruns after the Resource change green (`CompensationPlanApiTest` 50, `CommissionRuleApiTest` 36, `OpenApiContractTest` 9). **Increment 6 (full local gates + single completion commit + push) pending. No commit, no push, no PR.** See Phase 20F section + `docs/proof/phase-20f.md`. |
| Post-20F hardening | Resource contract truth + accessibility tokens (deferred 20F follow-ups) | ✅ `verified_complete` — **PR #40** "Hardening: Fix resource contracts and accessibility tokens" MERGED (implementation commit `cdcb83fc89d89b2139063ce0c099ec1a84ee7748`; governance/final head `53a595bba86e94521279a2c9258bc349a54bfcfc`; merge commit `57dce1031ce10c37977540a0e63b1491d444b877` == `origin/main`; merged `2026-07-17T14:43:17Z`; required CI Backend/Frontend/Docker/Security/E2E all SUCCESS — initial run `29588324838`, final run `29588846573`; `reviewDecision` blank under the documented solo-maintainer governance exception, not independent approval; local + remote hardening branches deleted). Branch off `f4bc664…` (= the Phase 20F PR #39 squash merge). Not a feature phase — no migrations, no new permissions, no new routes, no 20D-W/20G/20H work. **(A) Nullable Resource/OpenAPI truth sweep:** audit re-run (124 non-comment `?->` lines across 38 of 56 Resources; 110 field assignments published non-null); **104 confirmed defects fixed across 34 Resource files**, classified against each model's `@property` docblock (attributes) or the FK column's nullability (relations) — **20 deliberately kept** where null is unreachable (non-null FKs; `QueueEntry`'s `(string)` cast). Arithmetic: 124 − 20 = 104; a post-fix audit re-run reports **0** remaining genuine defects. Includes the Phase 20E `PlatformFeeConfigurationResource` drift 20F left out of scope. **Runtime JSON byte-identical** (`?->` ≡ `=== null ? null :`); counts unchanged **207 paths / 248 operations**; determinism proven by 3 byte-identical passes. New generator finding: a `fn (): ?string` closure return hint does **not** create nullability — the ternary must be inside the closure. Truthful types exposed exactly **3** `vue-tsc` errors, each a real latent bug (`platformFee.ts` would render a literal "null"; `PlatformFeeConfigSection` prefill could silently propose a different fee behaviour) — fixed with real null handling, no `!`/`as`/null→`''`. **(B) Dark-mode contrast sweep:** 128 `text-brand-deep` reviewed → **105 changed** to `text-heading` on adaptive surfaces, **23 kept** (16 on non-adaptive orange/cream per ADR-009, 3 comments, 4 already `dark:text-text`); 4 heuristic "probably cream" cases were read individually and all 4 proved failures (the cream was a sibling icon/badge). `text-warning` exists **twice repo-wide**, both in `StaffList.vue` → `bg-warning/15 text-text`. `StaffList` had **no e2e coverage at all** — new `tests/e2e/hardening-accessibility.spec.ts` renders all 4 membership badges in light+dark, axe serious/critical = 0, with a **negative control proving the spec fails on the pre-fix classes**. Gates: composer validate OK; Pint PASS (1334); Larastan L8 no errors; `OpenApiContractTest` 9, `RouteSecurityContractTest` 10, `NoDirectProviderIntegrationTest` 6 (all real selections); **full backend serial 1469 passed / 7 skipped / 0 failed / 8644 assertions** and **`--parallel` identical 1469/7/0**; `api:contract:check` OK 207/248; `permission-types --check` up to date; ESLint **0 errors / 138 warnings = the `origin/main` baseline** (no new warnings); vue-tsc clean; Vitest 404/84; build PASS; **full Playwright 368 passed** (= 20F's 364 + exactly the 4 new hardening tests); composer audit clean; npm audit 2 moderate (exit 0); gitleaks no leaks; Docker dev app + prod app + prod nginx built. Two environment/load flakes recorded honestly (a determinism pass run concurrently with the DB-mutating suite; a Playwright webServer 120s timeout under concurrent Docker builds) — both re-run clean in isolation, no code changed for either. See `docs/proof/post-20f-deferred-hardening.md`. |
| 20G | Salary accrual & commission processing (financial) | ✅ `verified_complete` — **PR #41** "Phase 20G: Implement salary and commission ledgers" MERGED into `main` (squash merge `dcdbfb69f338f1cbdf13c0a0b507ef600cfe7f14` == `origin/main`, implementation commit `51ebb5dd0c44c858c7afadd828dea5891da17fa0`, final pre-squash PR head `20260e850c465ef2517f356e0c1fbb984fe2a6ed`, merged `2026-07-20T11:54:59Z`; base `main`); five required CI checks (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS; `reviewDecision` **blank** under the documented solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-41.md`) — **not** independent reviewer approval; local + remote `phase-20g-salary-commission-ledgers` branches deleted. (Reconciled from `local_complete pending PR` during Phase 20H Increment 1, per the established convention that the next branch reconciles the previous phase.) Branch was off `57dce10…` (PR #40 merge); Increments 1–7 complete + green. Specification-first; G1–G12 resolved (two product-owner decisions taken: Actual/Actual salary proration; build `commission_rule_services` substrate). **Increments 1–4 COMPLETE + green.** Increment 4 (Finance-source integration + reversal semantics): a Plan-vs-continuation-prompt conflict on partial-refund reversal was surfaced and resolved **in favour of the Plan** (product-owner ruling 2026-07-18) — reversal is the **exact negative of the original, one per original** (proportional-partial-recompute withdrawn; **no schema change**). Because there is no immutable item-level refund attribution, the consumer reverses a validation event's earned rows **only once the whole validated allocation is refunded**; a partial refund is a valid no-effect consumed event; over-refund fails closed. Invoice void does not invalidate the validated allocation → no commission reversal (proven); pre-validation reject/correction earn nothing. Tests: CommissionRefundReversal 10, CommissionReversalSeam 2; two Phase 20F guards reconciled (zero-rows). Compensation group **362 passed**; Payments+Refunds 98; NoDirectProvider+Invoice+20G schema/enum/isolation 95; manifest+tenancy 15; Pint clean; Larastan L8 0 errors. **Increment 5 COMPLETE + green:** activated Finance keys `compensation.liability.view` + `compensation.adjustment.create` (planned→active; active 110→112, planned 58→56; legacy retirement none; Finance-only; parity across matrix/registry/DB/permissions.ts/phase8-matrix); new `StepUpAction::CompensationAdjustmentCreate`; `CompensationLiabilityReadModel` (per-currency net liabilities, no cross-currency sum); route family `compensation/*` (masked reads + `POST compensation/adjustments` financial mutation with fresh step-up + Idempotency-Key + high-severity `compensation.adjustment.created` audit; append-only standalone `manual`; branch server-derived); 3 policies, 3 requests, 2 masked resources, 2 controllers; OpenAPI **211/253** (deterministic), api.ts + permissions.ts regen (`--check` green), `api:contract:check` OK, vue-tsc clean; report defs in `docs/reporting/report-catalogue.md`. Tests: Phase20GCompensationApiTest 13; auth 196; route-security/idempotency/audit/OpenAPI 30; full compensation group **375**. **Increment 6 COMPLETE + green (frontend):** ONE new Finance screen — **Compensation liabilities** (`finance.liabilities` → `/finance/liabilities`, `FinanceLayout`, Finance, `compensation.liability.view`) reusing the reserved nav slot (`planned → live`); consumes the **existing** Increment-5 generated contract only — **no backend/route/permission/contract change** (`OpenApiContractTest` re-run: `openapi.json` byte-current). `compensationLiabilityStore` (Pinia, generated types; summary/entries/adjustments/detail + create; filters send only declared keys; 403→forbidden; **Idempotency-Key reuse-on-retry / re-mint-on-change / retire-on-success**; server-authoritative money only). `CompensationLiabilities.vue` (per-currency summary never combined; filters; paginated entry + adjustment lists + detail modals; capability-gated Record-adjustment dialog with direction-selector→signed `amount_minor` float-free parser, "not a payment" preview, safe step-up/period-lock/validation/forbidden states, focus restore + status announce). Nav/inventory flipped in source with `role-navigation.yaml`/`inventory.yaml`/§27.1 spec regenerated by their own tests/generator. **Selected-services HR UX = State C (§8): the §9.1 commission-rule API/Resource/OpenAPI contract for `selected_service_ulids` was never wired (substrate exists) → STOPPED + reported per §8; no client-only list; smallest correction tracked as a follow-up.** Minor generated-contract note: `CompensationAdjustmentResource.has_source` typed `string` (bool mis-inference) — not consumed by the surface; fix rides the State-C regen. Gates: ESLint 0 errors, vue-tsc clean, Vitest **404→428** (+24: store 12, component 12), build PASS, affected Playwright `phase-20g.spec.ts` **18 passed** (axe serious/critical=0 page+dialog light+dark; 360/768/1280; 200% zoom; keyboard). Backend contract reruns serial (Phase20GCompensationApiTest 13; permission parity; RouteSecurity; idempotency; audit mutation+severity; OpenAPI byte-current; NoDirectProvider) **55 passed / 0 failed**. **Increment 6A COMPLETE + green (selected-services contract + HR UX closure; product-owner authorized to close State C before 20G local completion):** wired `selected_service_ulids` into the HR commission-rule draft API (Store/Update requests; controller resolves ULIDs inside acting merchant+branch → foreign/cross-branch 404, archived 422; Create/UpdateCommissionRuleDraft persist/replace `CommissionRuleService` memberships transactionally, draft-only; `CommissionRule::selectedServices()` relationship; `CommissionRuleResource` returns `selected_service_ulids: string[]` + `selected_services:[{ulid,name}]`; `has_source` cast to boolean). **Product-owner service-options decision (Option 1):** new narrow read-only `GET /api/v1/commission-rule-service-options` gated by existing `compensation.plan.view` (NOT service.view — HR can't hold it; verified in-container), returning the acting branch's ACTIVE services as `{ulid,name}` only; thin `CommissionRuleServiceOptionController` + `CompensationSelectableServiceResource`. HR `Compensation.vue` gains a branch-scoped checkbox multi-select (options from the endpoint, never `/services`; ≥1 required; removable chips; server hydration; clears stale on applies_to change; non-draft read-only line) via `compensationStore.fetchServiceOptions`. **Observed (NOT changed):** the Phase-15A HR Service Eligibility page calls `/services` though HR lacks service.view — recorded as a separate pre-existing mismatch, no widening. OpenAPI **211/253 → 212/254** (+1 route; deterministic 2×); `permissions.ts` unchanged; `api:contract:check` OK. Tests: backend affected serial **198 passed / 0 failed** (CommissionRuleSelectedServices 17; CommissionRuleServiceOptions 7; CommissionRuleApi 36; CommissionEarning; Phase20GSchema/Enum/TenantIsolation; Phase20GCompensationApi 13; CompensationPlanApi; OpenApiContract byte-current; RouteSecurity; idempotency; audit mutation+severity; permission parity) + manifest/tenancy **15**; Pint 1398 clean; Larastan L8 0. Frontend: ESLint 0 errors, vue-tsc clean, **Vitest 435/87** (+7 Compensation.selectedServices), build PASS, **full Playwright 397 passed** (+11 HR selected-services; axe 0 light+dark; 360/768/1280; 200% zoom; keyboard; proof `/services` never called). No new permission; no service.view grant to HR; no new screen; no migration. **No stage, no commit, no push, no PR.** **Increment 7 COMPLETE + green:** full local acceptance battery — composer validate; Pint 1398 clean; Larastan L8 0; **full backend serial 1578 passed/0 failed/7 skipped** and **`--parallel` identical 1578/0/7 (4 procs)**; ESLint 0 errors; vue-tsc clean; Vitest 435/87; build PASS; inventory/nav snapshot parity; **full Playwright 397 passed** (axe 0 light+dark); OpenAPI 212/254 deterministic (openapi.json/api.ts/permissions.ts byte-identical 2×); permission-types --check + api:contract:check green; composer audit clean; npm audit 2 moderate (below gate); gitleaks no leaks; Docker dev app + prod app + prod nginx built. **Disposable PG16 proof:** `servana_p20g_i7_proof_*` on PostgreSQL 16.14 — 104 migrations from zero, 87 tables, 4/4 Phase 20G tables + suspension_salary_policy, append-only/immutability/selected-services-membership triggers, idempotency uniques, composite-consistency FKs, **0 forbidden payout/earnings tables**, database dropped, dev DB untouched. One fix during Inc7: `ServiceSessionCouplingTest.php:111` stale cross-phase guard (`commission_ledger` table-absence → zero-rows; the same reconciliation Inc4 applied to two compensation guards) — test-only, no product change. Scope-purity audit clean (no forbidden category; no service.view widening; no 20H/20D-W/Wallet/payout/earnings runtime). See the Phase 20G section below + `docs/proof/phase-20g.md`. |
| 20H | Payout runs & earnings (financial) | ✅ `verified_complete` — **PR #43** "Phase 20H: Implement payout runs and earnings" MERGED into `main` (squash merge `6047835b3a388fff5cc92a13370963635700f5e3` == `origin/main`; implementation commit `309057c2f29e492bbc2602714d9c7e52ea1014b4`; CI test-fix commit `16c368a96dbd3d53a5bb7fda8a3b39e55ac46b92`; governance / final PR head `9824e463273ffb6d8b089c6cef683b165cdc8c25`; base `main`; merged `2026-07-22T04:27:01Z`). Final CI run **29890786464** on head `9824e46…`, event `pull_request`, conclusion `success` — five required jobs all SUCCESS (Backend — Pint, Larastan, Pest; Frontend — ESLint, vue-tsc, Vitest, build; Docker — build images; Security — gitleaks; E2E — Playwright). `reviewDecision` **blank** under the documented PR-specific solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-43.md`) — **not** independent reviewer approval. Local **and** remote `phase-20h-payout-runs-earnings` branches deleted. **CI repair was test-only:** the initial PR #43 Backend run failed on two stale hand-maintained permission expectations — `tests/Feature/Auth/PermissionMatrixTest.php` (`expectedMatrix()` missing the 16 grants activated by 20H) and `tests/Feature/Auth/PermissionDatabaseProjectionTest.php:38` (used `payout_run.mark_paid`, which 20H made active, as its "planned key" fixture; swapped for the still-planned `personnel.my_sms.send`, Phase 21S). No implementation permission truth was changed. (Reconciled from `in_progress` during Phase 21R-A Increment 1, per the established convention that the next branch reconciles the previous phase.) Branch was off `1879110…` (PR #42 dependency remediation), itself off `dcdbfb6…` (Phase 20G PR #41 merge); Gate W **CLOSED** → 20H was the next executable phase. **Increment 1 (verification + reconciliation + specification) in progress:** PR #41 verified MERGED (five CI checks SUCCESS; solo-maintainer exception, not independent approval); Phase 20G reconciled `local_complete → verified_complete`; H1–H18 decision table written (`docs/proof/phase-20h.md`). **Key resolutions:** H6 high-value threshold = existing `merchant_subscriptions.high_value_payout_threshold_minor` (Phase 20A; snapshot source, no new substrate, nothing hardcoded); tables `personnel_payout_runs`/`personnel_payout_items`/`earnings_queries` (Plan §13.12) + expand FKs adding `payout_item_id → personnel_payout_items` to the three 20G ledgers; schema-completion decision D-H3-1 adds `currency` to runs/items (single-currency run) to honour the no-cross-currency invariant; ledger claim at **submit/freeze** (D-H3-2); 16 planned Phase 20H permission keys → activate in Inc 5 (active 113→129, planned 56→40); earnings statements reuse existing 10F `earnings_statement` file purpose (no schema change); `earnings_query.respond` = **Finance** per matrix (D-H12-1). **No blocking conflict; no product-owner decision required.** No migration/commit/push/PR yet. See Phase 20H section + `docs/proof/phase-20h.md`. |
| 21R-A | Citrus Refer & Earn — referral capture, outbox, signed delivery | ✅ `verified_complete` — **PR #44** "Phase 21R-A: Implement referral capture and R&E outbox" MERGED into `main` (**merge commit `b5a8733616a4603996e18695db31528299cdf8d7`** == `origin/main`, merged `2026-07-22T10:17:57Z`; **GitHub merge-commit strategy, not squash**; base before 21R-A `6047835b3a388fff5cc92a13370963635700f5e3`; implementation commit `a9ee4445d56be29217c9db146d585228bf3f27ed`; CI-stabilization / schema-test patch + final PR head `7b7cdb342ffa37df09ac91a030d8417746266710`). Final CI run **`29909918754`** (`event=pull_request`, `conclusion=success`) — five required checks **Backend — Pint, Larastan, Pest** / **Frontend — ESLint, vue-tsc, Vitest, build** / **Docker — build images** / **Security — gitleaks** / **E2E — Playwright** all SUCCESS. `reviewDecision` **blank** under the solo-maintainer governance exception — **not** independent approval; governance evidence posted as a PR comment after green CI: <https://github.com/ikrome002-design/servana/pull/44#issuecomment-5044610118>. Branch cleanup: remote `phase-21r-a-referral-capture-outbox` deleted by the merge (0 refs); stale local branch deleted with `git branch -d` (was `7b7cdb3`, reported merged). Reconciled during Phase 21S Increment 1. `REM-RE-002` (no R&E sandbox credentials / algorithm / product code — fixture-verified only) stays **open** and must close before Phase 25. See the Phase 21R-A section + `docs/proof/phase-21r-a.md`. |
| 21R-B | R&E subscription events, qualification engine, inbound reconciliation | ⛔ Blocked — entry criteria require 21R-A **and** 20D-W (payment received/cleared sources), and 20D-W is blocked by Gate W. Not started. |
| 21N | Queues / notifications / scheduled reports | ⛔ Blocked — Plan §80.1 dependency `(17,18,20D-W) → 21N`; 20D-W is blocked by Gate W. Not started. |
| 21S | Personnel bulk SMS to personally served clients | ✅ `verified_complete` — **PR #45** "Phase 21S: Implement personnel bulk SMS" MERGED into `main` (merge commit `d8a7a15603c22e41354e570f4d2735935468d973` == `origin/main`; implementation commit `9d2c547a4a8e8af76a80bc138ae0b608e448dfe7`; CI-fix commit `34a5921ca5b2f4502e20172c10ed472d7d416954`; final PR head / empty PR-ref resync commit `dc48d095529757dd1282ad5a8659e8e087cbc2a8`; base `main`; merged `2026-07-23T09:13:10Z`). Final CI run **29992575586** on head `dc48d09…`, event `pull_request`, conclusion `success` — five required jobs all SUCCESS (Backend — Pint, Larastan, Pest; Frontend — ESLint, vue-tsc, Vitest, build; Docker — build images; Security — gitleaks; E2E — Playwright). `reviewDecision` **blank** under the PR-specific solo-maintainer governance exception recorded at [PR #45 comment 5056479540](https://github.com/ikrome002-design/servana/pull/45#issuecomment-5056479540) — **not** independent reviewer approval. Local **and** remote `phase-21s-personnel-bulk-sms` branches deleted. **REM-SMS-001** closed `verified_complete` on the merge; **REM-SMS-002** remains open (deferred live SMS provider/callback verification, must close before Phase 25). (Reconciled from `local_complete` on the `phase-22-search` branch, per the established convention that the next branch reconciles the previous phase.) Branch was off `b5a8733…` (PR #44 merge commit). Executable because Plan §80.1 lists `16C + 15A(consent) → 21S` and both are `verified_complete` with live `client_consents` + `service_sessions` substrate; Gate W remains CLOSED so 20D-W / 21R-B / 21N stay blocked. Closes **REM-SMS-001** (final closure on merge); opens **REM-SMS-002** (deferred live-provider verification, before Phase 25). Final local gates: composer validate OK; Pint 1611 clean; Larastan L8 0 errors (1257); **full backend serial 2006 passed / 7 skipped / 0 failed / 12414 assertions** and **`--parallel` identical 2006/7/0 (4 procs)**; disposable PG16.14 proof `servana_p21s_proof_*` (118 migrations from zero, 97 tables, 4/4 SMS tables, phone_encrypted nullable, 5 triggers, 0 forbidden tables, dropped, dev DB untouched); OpenAPI **242 paths / 288 operations** deterministic (openapi.json/api.ts/permissions.ts byte-identical 2×), `permission-types --check` + `api:contract:check` green; ESLint 0 errors / 138 baseline warnings; vue-tsc clean; Vitest 501/501; build OK; Playwright 21S 21/21 + full **453 passed / 0 failed** (one unrelated appointments load-flake reran clean, isolated 13/13); npm audit 0 vulnerabilities; composer audit no advisories; gitleaks no leaks; Docker dev app + prod app + prod nginx built. Closure-session fixes: F1 Pint CRLF/style on two 21S test files; F2 stale committed OpenAPI still carried `phone_encrypted` after the Form Requests moved denylist→allowlist (regenerated, counts unchanged 242/288); F3 Playwright load-flake (no code change). **Not** `verified_complete`/`ci_passed`/`merged` — no PR exists. See the Phase 21S section + `docs/proof/phase-21s.md`. |
| 22 | Search | ✅ `verified_complete` — **PR #47** "Phase 22: Implement scoped search" MERGED into `main` (squash-merge commit `d010ec50f412dfe97ee1c412362e16bf263c2a4d` == `origin/main`; single squash parent `1e1b0fd3c9ed76a50e9d47adf1cea0c0222c1408` = the REM-DEP-002 merge; final PR head `8dbb2740c9603a75392a32139270f518eb789839`; original implementation commit `edff8c059671b551eec1e6f9617ea3ae6add0d7b` preserved in the refreshed history; merged 2026-07-26T20:39:50Z by ikrome002-design). Final CI run **`30218560304`** — five required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS. Governance: PR-specific solo-maintainer exception, comment id `5085264996` (<https://github.com/ikrome002-design/servana/pull/47#issuecomment-5085264996>); `reviewDecision` blank — **not** independent reviewer approval; submitted reviews `0`. Local **and** remote `phase-22-search` branches deleted. Reconciled from live Git/GitHub evidence on the `phase-23-release-hardening-audit` branch, per the convention that the next phase reconciles the previous one. |
| 23 | Security hardening + responsive/dark/a11y release audit + threat-model | ✅ `verified_complete` — **PR #48** "Phase 23: Complete release hardening and audits" MERGED into `main` (squash merge `13f54a4df54a46abb2928783373383a87ba301d2` == `origin/main`; squash parent `d010ec50f412dfe97ee1c412362e16bf263c2a4d` = the Phase 22 PR #47 merge; final PR head `ee2dc2b48d50ff156f8034552d9965bbb4186967`; head branch `phase-23-release-hardening-audit`, base `main`; merged `2026-07-27T19:18:34Z`). Final CI run **`30296509464`** on head `ee2dc2b…`, conclusion `success` — five required checks all SUCCESS (Backend — Pint, Larastan, Pest; Frontend — ESLint, vue-tsc, Vitest, build; Docker — build images; Security — gitleaks; E2E — Playwright). Governance: PR-specific solo-maintainer exception, comment id **`5095716132`** (present exactly once; names the final head and final CI run and explicitly claims no independent reviewer approval); `reviewDecision` **blank**; submitted reviews **0** — **not** independent approval. Local **and** remote `phase-23-release-hardening-audit` branches deleted; `git fsck --full` exit 0 (dangling objects only). **REM-SCR-002** and **REM-TRACE-001** promoted `local_complete → verified_complete` on this merge, together with the three Phase 23 traceability rows (`SRV-SEC-001`, `SRV-MERCHANT-PROFILE-001`, `SRV-BRANCH-CALENDAR-001`). **REM-PERM-002** and **REM-EXP-001** remain **open and unchanged**. (Reconciled from live Git/GitHub evidence on the `phase-24-performance-optimization` branch, per the convention that the next branch reconciles the previous phase.) See [phase-23.md](proof/phase-23.md). |
| 24 | Performance optimization | ✅ `verified_complete` — **PR #49** "Phase 24: Optimize performance and scalability" MERGED into `main` (squash merge `db3827be40194c4a3905679e5d182f014113641b` == `origin/main`; squash parent `13f54a4df54a46abb2928783373383a87ba301d2` = the Phase 23 PR #48 merge; final PR head `46bed762f3e9afadce920ba9376bf6bc6f9b6e5e`; head branch `phase-24-performance-optimization`, base `main`; merged `2026-07-28T08:19:47Z` by ikrome002-design). Final CI run **`30340905747`** on head `46bed76…`, conclusion `success` — five required checks all SUCCESS (Backend — Pint, Larastan, Pest; Frontend — ESLint, vue-tsc, Vitest, build; Docker — build images; Security — gitleaks; E2E — Playwright); the required check set was **not** weakened. Governance: PR-specific solo-maintainer exception comment recorded on the PR (names the final head and CI run and explicitly claims no independent reviewer approval); `reviewDecision` **blank**; submitted reviews **0** — **not** independent approval. Local **and** remote `phase-24-performance-optimization` branches deleted; `git fsck --full` exit 0 (dangling objects only). Traceability rows `SRV-OPS-003/004/005` promoted `local_complete → verified_complete`, and the stale **`SRV-PERF-001`** row promoted `deferred_future_phase → verified_complete` — it had been deferring to a phase that already delivered it. Phase 24 opened **no** remediation item. (Reconciled from live Git/GitHub evidence on the `plan/role-ui-ux-subdomains` branch, per the convention that the next branch reconciles the previous phase.) See the Phase 24 section below and [phase-24.md](proof/phase-24.md). |
| 25 | Deployment pipeline & production readiness | ⬜ **Not started** — the only remaining backend phase; needs its own product-owner authorization. Not started in the Phase UI-00 session. |

## Corrective UI/UX programme (UI-00 … UI-17)

A product-owner-directed remediation programme for the frontend, run **independently of the backend
roadmap above**. It exists because the browser experience was jumbled, role-confused and dark-first
while the repository records said the work was complete.

### Programme authority

| Item | Value |
|---|---|
| **Canonical UI/UX plan** | `Servana_Role_Specific_UI_UX_Subdomain_Software_Development_Plan.md` (repository root) · `sha256:2cea4ddded905b634a2f5a9d7739ba421b227304ba681dbee5b710e5782e7903` |
| **Canonical navigation map** | `docs/frontend/navigation/servana-user-account-navigation-maps.md` (generated verbatim from plan Appendix A) · `sha256:1f69a7bb4b1d059ad0b2d51095637639d121a83a494365dbe48f2cd87e4c6d37` |
| **Relationship to the backend plan** | `Servana Software Development Plan.md` remains authoritative for architecture, business behaviour, security, tenancy, financial invariants, integrations, data integrity and backend phase ownership. The UI/UX plan binds **UI-00 … UI-17 only** and never overrides those rules. |
| **Starting baseline** | `db3827b` — the Phase 24 PR #49 merge |
| **Backend Phase 25** | **Not started** |
| **Generator** | `node scripts/generate-ui-source-inventory.mjs` (`--check` for staleness) |
| **Guard** | `tests/Feature/Docs/UiSourceContractTest.php` — 22 tests, 453 assertions |

### UI roadmap

| Phase | Title | Status | Branch | Proof | Key gate / dependency |
|---|---|---|---|---|---|
| UI-00 | Plan adoption and source reconciliation | ✅ `verified_complete` | PR [#50](https://github.com/ikrome002-design/servana/pull/50) merged — squash `d3f6e10`, branch deleted | [ui-00.md](proof/ui-00.md) | Phase 24 merge verified live |
| UI-01 | As-built browser and repository audit | ✅ `verified_complete` | PR [#51](https://github.com/ikrome002-design/servana/pull/51) merged — squash `413c146`, sole parent `d3f6e10`, final head `5c52372`, source tree == merge tree `e00866f`, merged `2026-07-29T12:33:28Z`, CI run `30450612654` five checks SUCCESS, governance comment `5117766612`, `reviewDecision` blank / 0 reviews (**not** independent approval), branches deleted | [ui-01.md](proof/ui-01.md) | Reconciled live on the UI-02 branch |
| UI-02 | Multi-host foundation | ✅ `verified_complete` | PR [#52](https://github.com/ikrome002-design/servana/pull/52) merged — squash `fb64ba6`, sole parent `413c146`, implementation commit `db3ace4`, final head `5add80c` (`ci: build SPA before backend shell tests` — a tested CI-contract correction, one file, **not** governance-only), source tree == merge tree `442ed1d`, merged `2026-07-30T10:38:01Z`, CI run `30532318808` attempt 1 five checks SUCCESS, governance comment `5129527972`, `reviewDecision` blank / 0 reviews (**not** independent approval), branches deleted | [ui-02.md](proof/ui-02.md) | Reconciled live on the UI-03 branch |
| UI-03 | Authentication, session family, account switching | ✅ `verified_complete` | PR [#53](https://github.com/ikrome002-design/servana/pull/53) merged — **regular merge commit** `00c9c1e` preserving **four** reviewed commits (`64ca7cc` implementation, `415d2f5` deployed-origin browser proof, `5bd6e12` + `182f2cc` fixture-only payout-test corrections), parents in order `fb64ba67…` then `182f2cca…`, merged `2026-08-01T07:08:07Z`, CI run `30688440846` attempt 1 five checks SUCCESS, governance comment `5150328091`, `reviewDecision` blank / 0 reviews (**not** independent approval), branches deleted. Deployed-origin proof **47 observations / 0 failures**; 9 screenshots; merged full-suite backend baseline **3,108 passed / 5 skipped / 0 failed** (the retracted `2,528` figure is not reused). | [ui-03.md](proof/ui-03.md) | Reconciled live on the UI-04 branch |
| UI-04 | Design system and shared components | ✅ `verified_complete` | PR [#54](https://github.com/ikrome002-design/servana/pull/54) merged — **squash** commit `e6afe832…` (single parent `00c9c1e…`), reviewed head `cf36cee…` whose tree `bd6728fb…` equals the merge tree, `mergedAt 2026-08-02T13:37:16Z`. Final CI `30748616089` **five checks SUCCESS**; the earlier run `30746233065` failed **only** on gitleaks against one exact fingerprint — a reproducible SHA-256 design-token digest, **verified false positive**, closed by a single historical fingerprint with no rule, path, entropy or workflow weakening. Governance comment `5158172398`; **`#FDBA74` hover approved** by the product owner; `reviewDecision` blank / 0 reviews (**not** independent approval); branches deleted. All **thirteen** closures promoted to `verified_complete`. | [ui-04.md](proof/ui-04.md) | Reconciled live on the UI-05 branch |
| UI-05 | Content and asset pipeline | ✅ `verified_complete` | PR [#55](https://github.com/ikrome002-design/servana/pull/55) merged — **squash** commit `e6664f2e…` (single parent `e6afe832…`), reviewed head `3902633c…` whose tree `64aeb959…` equals the merge tree, `mergedAt 2026-08-03T08:46:31Z`. Final CI `30797162231` **five checks SUCCESS**. Governance comment `5164195590`; `reviewDecision` blank / 0 reviews (**not** independent approval); branches deleted. `UI01-ASSET-002`, `UI05-FAQ-001` and `UI05-IMAGE-001` promoted to `verified_complete`. Its two content findings stayed **open** and are closed by UI-06. | [ui-05.md](proof/ui-05.md) | Reconciled live on the UI-06 branch |
| UI-06 | Eight public landing pages | ✅ `verified_complete` | PR [#56](https://github.com/ikrome002-design/servana/pull/56) merged — **squash** commit `6b67ad2e…` (single parent `e6664f2e…`), reviewed head `e7cac3bf…` whose tree `a8336102…` equals the merge tree, `mergedAt 2026-08-04T17:09:51Z`. Three preserved reviewed commits: `7877e7b` implementation, `71af50d` screenshot evidence, `e7cac3b` CI dependency + E2E-budget correction. Earlier run `30917088915` failed on two **external dependency advisories** and an **E2E job cancelled at a stale 20-minute budget with no failing test**; final CI `30924581598` **five checks SUCCESS**. Governance comment `5182250114`; `reviewDecision` blank / **0** reviews (**not** independent approval). Branch deleted local + remote. Eight account landing pages × **16** regions; `/faq` on all eight hosts (**1,264** items); **24** canonical host-derived legal routes; **32** curated images, **0** cross-account requests; approved factual trust evidence and **no plan amount anywhere**. **No API, permission, policy or migration changed.** Five closures now `verified_complete`: `UI01-ASSET-004`, `UI01-LEGAL-001`, `UI01-LEGAL-002`, `UI05-CONTENT-001`, `UI05-CONTENT-002`. | [ui-06.md](proof/ui-06.md) | UI-05 PR merged and reconciled live |
| UI-07 | Navigation registry and screen contracts | ✅ `verified_complete` | PR [#57](https://github.com/ikrome002-design/servana/pull/57) merged — **squash** commit `16d544c5…` (single parent `6b67ad2e…`), tree equal to the reviewed head; final five-job CI SUCCESS; governance comment recorded; `reviewDecision` blank / **0** reviews (**not** independent approval); branch deleted local + remote. One canonical handwritten authority for the **160** authenticated pages (22/23/18/19/24/19/20/15), pinned page-by-page to the binding human map; **160** generated screen specifications; a typed generated navigation registry with deterministic filtering; the account guard on **all eight** trees; **4** placeholder-component routes removed. **No API, permission, policy or migration changed.** Its four closures `UI07-GUARD-001`, `UI07-GUARD-002`, `UI07-ROUTE-001` and `UI07-NAV-001` are promoted to `verified_complete`. | [ui-07.md](proof/ui-07.md) | Reconciled live on the UI-08 branch |
| UI-08 | Super Administrator experience (22 pages) | ✅ `verified_complete` | PR [#58](https://github.com/ikrome002-design/servana/pull/58) merged — **squash** `b435f484…` (single parent `16d544c5…`), final reviewed head `2fdf8784…`, source tree == merge tree `60a1085a…`, merged `2026-08-10T15:55:21Z`. Final CI run `31403883471` five checks SUCCESS; governance comment `5242660629`; `reviewDecision` blank / 0 submitted reviews (**not** independent approval); branch deleted local + remote. **17 implemented / 5 `disabled_by_gate` / 0 planned / 0 removed = 22**. All fourteen UI-08 closures promoted to `verified_complete`. | [ui-08.md](proof/ui-08.md) | Reconciled live on the UI-09 branch |
| UI-09 | Merchant Administrator experience (23 pages) | ✅ `verified_complete` | PR [#59](https://github.com/ikrome002-design/servana/pull/59) merged — squash `84b7f803…`, final reviewed head `59d10333…`, equal source/merge tree `3077b246…`, merged `2026-08-12T17:06:03Z`. Final exact-head CI `31618625302` five jobs SUCCESS; governance comment `5269937398`; review decision blank / 0 reviews (**not** independent approval); source branches deleted. **15 implemented / 8 `disabled_by_gate` / 0 planned / 0 removed = 23**; all fifteen UI-09 closures promoted. | [ui-09.md](proof/ui-09.md) | Reconciled live on the UI-10 branch |
| UI-10 | Branch experience (18 pages) | 🟠 `local_complete` pending PR CI / governance / merge | `phase-ui-10-branch-experience` from verified UI-09 squash `84b7f803…`. Readiness A4/B1/C5/D5/E3/F0; final **15 implemented / 3 disabled_by_gate / 0 planned / 0 removed = 18**. Exact eight-group Branch workspace; four narrow reads take OpenAPI **337→341**; permissions stay **169/134/35** (delta 0/0). Local gates: Pint 1,892; Larastan 1,448/0; PostgreSQL **2,976 passed / 14 skipped / 48,859 assertions**; Vitest **1,371**; dedicated browser **52/52**; whole-product browser **1,325/1,325** in 46.8m; seven widths + 200%, axe serious/critical 0; production PHP `56c853a1…` + Nginx `fd348f4d…`, Nginx syntax and disposable no-volume host proof **38/38**; Composer/npm/gitleaks/Git integrity green; frozen historical evidence restored exactly. Ten closures are `local_complete`; final traceability consumer proof is 15/15. No implementation commit/PR is claimed in this pre-commit record. | [ui-10.md](proof/ui-10.md) | Next: one exact commit, PR/five-job exact-head CI/governance/squash/cleanup; UI-11/Phase 25 not started; Gate W closed |
| UI-11 | Human Resource experience (19 pages) | ⬜ Not started | — | — | UI-07 merged |
| UI-12 | Finance experience (24 pages) | ⬜ Not started | — | — | UI-07 merged |
| UI-13 | Front Office experience (19 pages) | ⬜ Not started | — | — | UI-07 merged |
| UI-14 | Personnel experience (20 pages) | ⬜ Not started | — | — | UI-07 merged |
| UI-15 | Audit experience (15 pages) | ⬜ Not started | — | — | UI-07 merged |
| UI-16 | Responsive, accessibility, theme, visual regression | ⬜ Not started | — | — | UI-08…UI-15 merged |
| UI-17 | Performance, security, production deployment, closeout | ⬜ Not started | — | — | UI-16 merged |

### Source inventory summary (proven in UI-00, not assumed)

| Item | Value |
|---|---|
| Canonical landing directory | `docs/landing_page/` (underscore). `docs/landing page/` (space) **has never existed** — the old `CLAUDE.md`/`AGENTS.md` path was wrong and is corrected |
| Canonical legal directories | `docs/legal/data_policy/`, `docs/legal/privacy_policy/`, `docs/legal/terms_of_service/` |
| Canonical FAQ directory | `docs/support/faq/` |
| Role source documents | **40** = 8 roles × 5 categories (8 landing / 8 data policy / 8 privacy policy / 8 terms / 8 FAQ) |
| Approved logo | `public/assets/brand/Logo.png` — PNG 500×500, `sha256:ada6fb03…` |
| `Logo.svg` | **Deleted under product-owner authority** in `49160cd` (2026-07-07). Absent; must never be restored or referenced |
| Favicons | All six present with **lowercase** names (`favicon.ico`, `favicon-16x16.png`, `favicon-32x32.png`, `apple-touch-icon.png`, `android-chrome-192x192.png`, `android-chrome-512x512.png`). The old `Favicon.ico` reference was a case bug |
| Landing images | **61** total — super_administrator 10 · merchant_administrator 8 · merchant_branch 9 · merchant_finance 5 · merchant_human_resource 8 · merchant_front_office 6 · merchant_personnel 7 · merchant_audit 8. All valid PNG, 0 cross-role duplicates. **UI-05 curated 32 of them** (4 per account) into `public/assets/landing_page_images/manifest.json` with 192 AVIF/WebP derivatives; the 61 originals are unchanged. Final placement and visual approval remain UI-06's |
| Navigation page counts | **160** — 22 / 23 / 18 / 19 / 24 / 19 / 20 / 15. §30 register parity **exact** |
| ADR range | **ADR-016 … ADR-025** (next available after the existing 0015) |
| Generated artifacts | `docs/frontend/source-inventory/{navigation-map,role-content,brand-assets,landing-images}.json` |

### Existing substrate — evidence, not acceptance

The repository already contains frontend substrate from Phase 11, corrected in Phase 23 and extended
in Phase 24:

- eight role shells and eight role landing surfaces, plus get-started pages;
- legal/FAQ rendering;
- a role navigation registry (`resources/spa/src/navigation/roleNavigation.ts`,
  `docs/frontend/navigation/role-navigation.yaml`);
- a screen inventory (`docs/frontend/screens/inventory.json` + `.yaml`) guarded by
  `screenInventory.spec.ts`;
- Phase 24's per-role lazy landing/FAQ content split (`resources/spa/src/content/roleDocuments.ts`).

**Existence is not final UI acceptance.** None of it was rebuilt, replaced or re-audited in UI-00.
The screen inventory records what is **built**; the navigation map records what is **required**;
UI-00 keeps them as two separate registers and conflates neither. **UI-01 owns the browser audit** —
no served-build provenance was even available at UI-00 kickoff, because no Vite manifest exists.

### Skipped work and exact owners

Recorded in full, with reason, current evidence, owner phase, risk and entry condition, in
[ui-00.md](proof/ui-00.md) § *Skipped work and owners*. Summary of owners:

`UI-01` as-built browser audit · `UI-02` eight-host runtime foundation · `UI-03` Magic Link host
binding, session family, account switching · `UI-04` design tokens and shared components ·
`UI-05` content compiler and asset pipeline · `UI-06` eight production landing pages ·
`UI-07` full machine-verifiable 160-page runtime contract · `UI-08 … UI-15` the eight account
experiences · `UI-16` responsive/accessibility/theme/visual release audit · `UI-17` UI performance,
security, deployment and closeout · **Backend Phase 25** production deployment — *not started here*.

**External gates unchanged.** Gate W remains **CLOSED**; **20D-W**, **21R-B** and **21N** remain
blocked; Wallet-owned money movement, Refer & Earn-owned rewards, notification/reporting and
external-onboarding items are untouched. UI-00 closes none of them. `REM-PERM-002`, `REM-EXP-001`,
`REM-SMS-002` and `REM-RE-002` stay open.

## Phase UI-07 — Navigation registry and screen contracts (`local_complete pending PR CI/review/merge`)

**Branch** `phase-ui-07-navigation-screen-contracts` · **base**
`6b67ad2e1cc2c1031a89dc8d82902a025feac721` (the verified UI-06 merge commit) · **no PR** ·
**proof** [ui-07.md](proof/ui-07.md) · **artifacts**
[`docs/frontend/audits/ui-07/`](frontend/audits/ui-07/)

**Canonical authority** `docs/frontend/navigation/servana-user-account-navigation-map.yaml`
(SHA-256 `f28ca6fc…`), pinned page-by-page — account, label, route and purpose — to the binding
human map `docs/frontend/navigation/servana-user-account-navigation-maps.md` (SHA-256 `1f69a7bb…`)
by `Ui07NavigationContractTest`.

**Contract** 160 pages — Super Administrator 22 · Merchant Administrator 23 · Branch 18 ·
Human Resource 19 · Finance 24 · Front Office 19 · Personnel 20 · Audit 15. The total is summed
from the entries, never written independently.

**Status** implemented 77 · planned 58 · disabled_by_gate 25 (all External Gate W) ·
removed_by_authority 0. Legacy vocabulary retired: 18 × `phase_11` → `implemented`, 5 × `planned`
→ `disabled_by_gate` naming the gate, 4 placeholder rows removed.

**Owners** UI-08…UI-15, one per page by account; backend ownership recorded separately and read
from the screen inventory, never inferred from UI numbering.

**Parity** 160 screen specifications (0 missing, 0 orphan, 0 shared) · inventory 122 rows, every
implemented contract page backed by one · router 112 named records, 112 lazy, 0 duplicate names,
0 duplicate paths, 0 planned/removed exposed, 0 contract-name collisions, catch-all excluded ·
navigation 144 primary + 16 non-navigation entries, each with a recorded reason · permissions all
referenced keys exist, **0** added (matrix stays 167).

**Account guard** all eight trees plus the merchant setup route — 9 trees, 100 authenticated
routes, 0 missing. Two authenticated routes sit outside a tree with recorded reasons
(`staff.accept`, `search`). All-eight allow and deny proven in unit and browser tests; denial
names neither the forbidden account nor the held one.

**Defects closed locally** `UI07-GUARD-001` (seven unguarded trees) · `UI07-GUARD-002` (the guard
was coarser than screen ownership — `/branch` and `/hr` are path prefixes, and five screens are
genuinely served to two accounts; Plan §13 gives branch creation to the Merchant Administrator and
denies it to the Branch Manager) · `UI07-ROUTE-001` (four placeholder-component routes exposing
planned pages) · `UI07-NAV-001` (primary navigation bound to a parameterised route). Also closed by
this contract: `UI01-ROUTE-001`, `UI01-ROUTE-004`, `UI01-ROUTE-005`, `UI01-NAV-001`. All are local
closure evidence only until UI-07 merges.

**E2E harness** The account guard exposed a long-standing gap: authenticated specs stubbed `/me`
but never the server-embedded account context. Central role→account helpers took the suite from
**235 failures → 15**, then fifteen individually classified corrections took it to **0** — four of
which were the `UI07-GUARD-002` product defect rather than a test defect. No guard weakening, no
account bypass, no route alias.

**Residual** `UI07-ENV-001` — eight scheduling/service-session suites fail in the last hour of a
Nairobi business day because they create "now"-based walk-ins without freezing time. Pre-existing;
UI-07 changed no scheduling code. Owner: the scheduling/service-session test owner, before Phase 25.

**Gates** Pint 1,767 · Larastan level 8 clean 1,341 · ESLint 0 errors / 11 warnings · vue-tsc
clean · Vitest 1,148 · build green · UI-07 browser gate 42/42 · negative controls 24/24 ·
backend serial == parallel 2,792/14/0 (45,570 assertions) · **full Playwright 1,054/0/0/0, exit 0**
(1,066 - 12: the four placeholder screens removed by `UI07-ROUTE-001` x three release-audit
sweeps; no coverage lost) · composer/npm audit and gitleaks clean · historical evidence 286 files
preserved, UI-06 33 + 33 screenshots intact.

**Not done** no account page implemented (UI-08…UI-15) · no permission, API, policy or migration
change · no responsive/accessibility/theme release review (UI-16) · no approved visual baselines
(UI-16) · no performance or deployment work (UI-17 / Phase 25) · no Gate-W work.

### Current authorized work

Phase UI-08 is now `verified_complete`: PR #58 merged as squash `b435f484` after final five-job CI
run `31403883471` succeeded. Its reviewed head `2fdf8784` and merge commit have the same tree
`60a1085a`; governance comment `5242660629` records the solo-maintainer process and there were zero
submitted reviews, which is **not** independent approval. The source branch is deleted locally and
remotely. All fourteen merged UI-08 closures are promoted to `verified_complete` in
`docs/frontend/audits/ui-08/defect-closure.json`.

The authorized next phase is UI-09 on `phase-ui-09-merchant-administrator-experience`, implementing
the Merchant Administrator's exact 23-page contract while External Gate W and Phase 21N remain
closed. UI-10 and later account experiences, backend Phase 25 and Gate-W activation are out of scope.

## Phase UI-06 — Eight public landing pages (`verified_complete`)

**PR** [#56](https://github.com/ikrome002-design/servana/pull/56) **MERGED** · **squash merge**
`6b67ad2e1cc2c1031a89dc8d82902a025feac721` (single parent `e6664f2ec1c60e55fa27c0a40fa2685a6442932f`)
· **reviewed head** `e7cac3bf39d8a905094768f281b9343de96a29d3` · **tree equivalence**
`e7cac3bf^{tree}` = merge tree = `a83361024c60bb83e2e961de454d6bdc4f99dc6c` · **mergedAt**
`2026-08-04T17:09:51Z` · **branch** deleted local + remote · **proof** [ui-06.md](proof/ui-06.md) ·
**artifacts** [`docs/frontend/audits/ui-06/`](frontend/audits/ui-06/)

Three preserved reviewed commits: `7877e7b` implementation · `71af50d` screenshot evidence ·
`e7cac3b` CI dependency + E2E-budget correction. Failed run `30917088915` was two **external
dependency advisories** plus an **E2E job cancelled at a stale 20-minute budget with no failing
test**; final CI `30924581598` five required checks SUCCESS. Governance comment `5182250114`;
**0** submitted reviews, `reviewDecision` blank — the solo-maintainer process is **not**
independent approval. Preserve: `brace-expansion` 5.0.9 override, `guzzlehttp/guzzle` 7.15.2,
E2E `timeout-minutes: 60`.

Screenshot evidence: 33 PNGs under `docs/frontend/audits/ui-06/screenshots/` and 33 preserved
originals under `docs/proof/ui-06/`, index valid — focused implementation evidence, **not**
release-approved visual baselines (UI-16).

Five closures now `verified_complete`: `UI01-ASSET-004`, `UI01-LEGAL-001`, `UI01-LEGAL-002`,
`UI05-CONTENT-001`, `UI05-CONTENT-002`.

**Governing sources.** UI/UX plan §4.2, §6.5, §8.1–§8.8, §11, §12, §13, §17, §18, §19, §21,
§22.1–§22.2, §25 (Phase UI-06), §28.2; ADR-016/017/021/024/025; the Brand Identity **Buttons**
sentence-case rule.

### Predecessor reconciliation

PR **#55** MERGED as squash `e6664f2ec1c60e55fa27c0a40fa2685a6442932f`, single parent `e6afe832…`,
reviewed head `3902633ce992e4973cbe7eaa229dd87dbabc57cb`, tree `64aeb959…` identical to the merge
tree, `mergedAt 2026-08-03T08:46:31Z`, final CI `30797162231` with **five** successful required
jobs, governance comment `5164195590`, **0** submitted and **0** approving reviews with a blank
`reviewDecision` (**not** independent approval), both branch refs deleted. UI-05 is
`verified_complete` and its three closures were promoted.

One structural correction made that promotion honest: `UI01-ASSET-002`'s lifecycle was hard-coded
in `scripts/generate-landing-images.mjs`, so promoting a merged closure meant editing a build tool.
It now lives in the reviewed decision record (`config/brand-asset-quarantine.json`), the generator
copies it, and the test asserts the two cannot disagree. Only `asset-quarantine.json` changed —
the image manifest and all 192 derivatives are byte-identical.

### What UI-06 built

- **Eight public landing pages**, one per approved host, each presenting **all sixteen** semantic
  regions of §8.3 from its own compiled content, curated imagery and registry-derived actions. One
  shared architecture, eight typed composition modules; no generic object with a swapped title.
- **A closed annotation vocabulary** (`content/landing/landingSection.ts`). The approved sources
  interleave reader copy with build instructions (`**CTA:** **GET STARTED**`, `**Navigation
  Links:** …`, `**Meta Title:** …`). Lines are *classified*, never edited, and an unclassified label
  fails the build.
- **`/faq` on every host** — the account's own compiled FAQ in full, through UI-04's `SvFaq`, with
  the source's category dividers preserved. All **1,264** items reachable.
- **24 canonical legal routes** (`/legal/{data-policy|privacy-policy|terms-of-service}` × 8), the
  account resolved from the server context and never from a path. Every internal link migrated; the
  old `/legal/:role/:doc` shape kept only as a redirect that fails closed on a mismatched role.
- **The §4.2 public route contract**: `/login`, `/register`, `/setup`,
  `/auth/magic-link/request|consume` added as **aliases** of the implementations that already exist
  — one login screen, unchanged route-name resolution, query string preserved.
- **A typed CTA resolver** checking every action against the account-host registry and the live
  route table. Merchant self-registration is exposed by **exactly one** account and linked by none
  of the other seven.
- **Approved factual trust evidence** on all eight pages instead of testimonials, and **no plan
  amount anywhere** — both structurally enforced by literal-`false` type fields rather than review.
- **32 curated images rendered**, four per account, responsive AVIF/WebP with measured intrinsic
  dimensions, exactly one eager high-priority hero per page, **0** cross-account asset requests.

### Binding product-owner decisions applied

`§2.1`/`§2.2` no customer testimonial is published; all eight pages carry a factual alternative and
Human Resource's already-factual source section is preserved · `§2.3` Super Administrator carries
plan-access-and-administration content · `§2.4` no unprovable amount, so **no** page states one ·
`§2.5` CTAs come from the registry; where source copy contradicts it, the security boundary wins.

### Defects closed locally

`UI01-ASSET-004` · `UI01-LEGAL-001` · `UI01-LEGAL-002` · `UI05-CONTENT-001` · `UI05-CONTENT-002` —
all `local_complete pending PR CI/review/merge`, **never** `verified_complete` before merge.
Evidence: [`defect-closure.json`](frontend/audits/ui-06/defect-closure.json). Six decisions
recorded: `UI06-CTA-001` (source CTA vs registry), `UI06-CTA-002` (residual wording mismatch, open
for the product owner), `UI06-CTA-003` (sentence-case labels per the Brand Identity),
`UI06-LEGAL-001` (compatibility route), `UI06-NAV-001` (two accounts share a navigation signature
because their sources do), `UI06-PRICE-001` (no provable public amount).

### Backend authority unchanged

**No** API operation, permission key, policy, migration, tenant query, financial calculation or
state machine changed. `routes/api.php`, `docs/api/openapi.json` and
`docs/auth/permission-matrix.yaml` are untouched. UI-03 authentication, session-family and
account-switching controls are unweakened.

### Gate results

| Gate | Result | UI-05 baseline |
|---|---|---|
| Pint | PASS, 1,766 files | 1,764 |
| Larastan level 8 | `[OK] No errors`, 1,341 files | clean |
| Backend **serial** | **2,753 / 14 skipped / 0 failed**, 41,042 assertions | 2,704 / 13 / 0 |
| Backend **parallel** | **2,753 / 14 skipped / 0 failed**, 41,042 assertions — identical to serial | 2,704 / 13 / 0 |
| ESLint | 0 errors / **27 warnings** | 0 / 27 |
| vue-tsc | clean | clean |
| Vitest | **1,086 passed / 118 files** | 946 / 114 |
| Vite build | green; 8 account chunks + 40 content chunks | 41 content chunks |
| Playwright — focused UI-06 | **95 passed / 0 failed / 0 flaky**; 1,450 observations, 0 failed | — |
| Playwright — **full** | **1,024 passed / 0 failed / 0 flaky / 0 skipped** | 923 / 0 / 0 / 0 |
| Phase 23 release audit | 376 passed | 367 |
| `composer audit` · `npm audit` · gitleaks | clean · 0 vulnerabilities · **no leaks** | clean |

Counts reconcile exactly: **+49** backend tests (46 landing contracts + 4 staleness, one of which
skips without Node), **+140** Vitest (5 new spec files less the deleted `Home.spec`), **+101**
Playwright (95 UI-06 + 9 release-audit cells − 3 from the replaced `Home` surface).

### Responsive · theme · accessibility · performance

**Responsive** 8 accounts × 7 widths = **56 cells**, all with no horizontal overflow, exactly one
navigation affordance per width. **Theme** fresh context renders light on all eight under an
emulated dark OS; explicit dark applies, persists per browser and survives reload. **Accessibility**
**26 axe runs** (8 landing light, 8 landing dark, 8 FAQ, 1 legal × 2 themes) with **0 serious and 0
critical**; no rule suppressed. **Performance** preliminary only — LCP 248–492 ms, CLS
0.0008–0.0011, ~60 kB JS and 326–400 kB images per page, 28 requests. Recorded, not claimed; UI-17
owns the p75 budget and CDN proof.

### Production images and host proof

PHP dev `d23c9c39…` · PHP prod `2467a5a2…` · Nginx `66d0e978…` · `nginx -t` successful inside the
network. The smoke probed **8 accounts × 8 public paths** with genuine `Host` headers: all 200 with
the correct server-resolved account, 32 originals + 192 derivatives with correct MIME, `Logo.svg`
and the quarantined brand file **404**, 2 unknown-host denials, no service worker. **0 failures.**

### Historical evidence

Hashed before and after the full Playwright run. **UI-01 … UI-04: 192 files, aggregate
`d46e3997…` — identical after restoration. UI-05: 10 files, aggregate `346a9dd7…` — identical.**
The run rewrote evidence as UI-05 recorded it would; restoration was precise — 9 tracked blobs
restored by exact path, 139 generated additions removed by exact filename, no broad `git clean`.

### Not done, and stated plainly

No 160-page route contract, no authenticated account experience, no release-wide visual baseline,
no deployed-origin browser gate, no deployment. `UI01-PROV-003/004/005` and `UI01-A11Y-001` stay
open with their recorded owners. Every skip has a named owner in [ui-06.md](proof/ui-06.md)
§ *Skipped work and owners*.

### Next human action — **done**

The UI-06 pull request was created, its five required checks passed on run `30924581598`, and it
merged as `6b67ad2e…`. UI-06 and its five closures were reconciled to `verified_complete` by
Phase UI-07.

## Phase UI-05 — Content and asset pipeline (`verified_complete`)

**PR** [#55](https://github.com/ikrome002-design/servana/pull/55) **MERGED** · **squash merge**
`e6664f2ec1c60e55fa27c0a40fa2685a6442932f` (single parent `e6afe832…`) · **reviewed head**
`3902633ce992e4973cbe7eaa229dd87dbabc57cb` · **tree equivalence** `3902633c^{tree}` = merge tree =
`64aeb959…` · **mergedAt** `2026-08-03T08:46:31Z` · **final CI** `30797162231`, five required jobs
SUCCESS · **governance comment** `5164195590` · **0** submitted / **0** approving reviews, blank
`reviewDecision` — **not** independent approval · **branches deleted** · `UI01-ASSET-002`,
`UI05-FAQ-001` and `UI05-IMAGE-001` promoted to `verified_complete`. Reconciled on the UI-06 branch.

**Branch** `phase-ui-05-content-asset-pipeline` (deleted) · **base** `e6afe832` (the verified UI-04
merge commit) · **proof** [ui-05.md](proof/ui-05.md) · **contracts**
[`docs/frontend/content/`](frontend/content/) · **artifacts**
[`docs/frontend/audits/ui-05/`](frontend/audits/ui-05/)

**Governing sources.** UI/UX plan §8.1–§8.8, §17.1–§17.4, §22.2, §25 (Phase UI-05), §26, §28.2,
§28.8; ADR-025; backend Plan §27.2.

### What UI-05 built

A **deterministic content compiler** (`scripts/generate-role-content.mjs`) that replaces the
build-time `import.meta.glob(…?raw)` discovery of repository Markdown with source-controlled,
hash-checked generated modules. **40 documents** — 8 accounts × 5 categories — each claimed exactly
once, each read from its own account's directory, with the account keys taken from the existing
`config/account-hosts.json` authority rather than a second role list. Loading stays lazy: **forty
static dynamic imports**, so no chunk can contain another account's content, and an unknown key
throws rather than falling back.

**Legal preservation.** All **24** legal documents are byte-identical, proven by decoding the
generated string literal with PHP's own JSON decoder rather than trusting the emitter, plus line,
heading, list-item and link-count parity and a `git status` assertion that no approved source was
modified. `/legal/:role/:doc` now renders through UI-04's `SvLegalDocument`, carrying the source
path and hash it was compiled from.

**Content version** `sha256:ff63281f…`, **source timestamp** `2026-06-21T11:17:57Z` resolved from
git history or carried forward — never a build clock, so the bytes reproduce.

**Curated imagery.** `config/landing-image-selection.json` records the human decision (4 images per
account, **32** of the 61 supplied); everything measurable is read off the files.
**192 derivatives** (96 AVIF + 96 WebP at 640/1024/1440, 9.8 MB) with pinned encoder options and no
upscaling; deleting the whole generated tree and regenerating produced byte-identical files. The
untouched original is the `<picture>` fallback.

**Seventeen negative controls** break one input at a time in a disposable copy and require the
generator to fail naming that problem — each verifying the unmutated copy passes first.

### Defects closed (locally)

`UI01-ASSET-002` — eleven unapproved brand working files were publicly served because the edge image
copies the whole `public` tree. Closed by a **non-destructive quarantine**: moved with `git mv` into
`docs/brand/quarantine/ui01-asset-002/`, hashes verified before and after, **nothing deleted**.

`UI05-FAQ-001` — the runtime parser required heading level two, so **60 of Merchant Administrator's
196** FAQ questions never reached any surface. Invisible in seven of eight accounts. 1,204 → **1,264**
items.

`UI05-IMAGE-001` — every supplied image was enumerated from a hard-coded count, `1.png` was assumed
to be every role's hero, and the alt text was invented at a declared 800 × 600 for files that are
1672 × 941 or 1448 × 1086.

All three are `local_complete pending PR CI/review/merge` — **not** `verified_complete`. Closure
evidence: [`defect-closure.json`](frontend/audits/ui-05/defect-closure.json). The historical UI-01
register is unchanged.

### Content findings recorded for the product owner, not fixed

`UI05-CONTENT-001` — `merchant_branch`, `merchant_finance`, `merchant_front_office` and
`merchant_personnel` supply testimonial copy carrying unverified customer quotes; the Personnel
source says outright that its three quotes are placeholders. Compiled verbatim, flagged
`renderPermitted: false`. `UI05-CONTENT-002` — four regions have no source content (no testimonials
for Merchant Administrator, Merchant Audit or Super Administrator; no pricing for Super
Administrator). §8.3 and §8.4 forbid inventing commercial or customer evidence, and rewriting
approved copy is not an engineering decision.

### Permissions, policies, migrations

**No route. No API operation. No permission key. No permission-matrix change. No policy. No
migration. No tenant query. No financial logic.** One dev-only dependency: `sharp` 0.35.3, pinned
exact, never imported by the SPA or by PHP.

### Gate results

Backend (in the `app` container — the host PHP has no `pdo_pgsql` driver, so `make test` is the
only correct runner): Pint 1,764 files · Larastan level 8 clean · serial **2,704 passed / 13
skipped / 0 failed / 38,994 assertions** · parallel (4 processes) **identical**. Reconciles against
UI-04's 2,643/9 as 2,643 + 61 = 2,704 and 9 + 4 = 13 — the four skips are the Node-dependent
staleness tests, which run in CI's Backend job.

Frontend: ESLint **0 errors / 27 warnings** (28 → 27; the lost pair was the removed single-line
`<h1 class="sr-only">` in `LegalDocument.vue`) · vue-tsc clean · Vitest **946 passed / 114 files**
(919 → 946) · Vite build green, **41 generated content chunks**.

Browser: UI-05 spec **33 passed**, 712 observations / 0 failures · full Playwright **923 passed / 0
failed / 0 flaky** (890 → 923) · axe **0 serious / 0 critical** across light, dark, all eight FAQ
data sets and 360 px.

Production: images `servana-ui05-phpdev:audit` `d23c9c393b30`, `servana-ui05-php:audit`
`093fe79ccac2`, `servana-ui05-nginx:audit` `5f197145cf5c`; `nginx -t` successful; asset smoke
**245 observations / 0 failures** — every original and derivative serves byte-identical with the
right MIME, and `Logo.svg`, the wrong-case logo and all eleven quarantined URLs return 404. The
edge image contains **no `docs/` directory**, so the quarantine archive does not ship.

Security: `composer audit` clean · `npm audit --audit-level=high` 0 vulnerabilities · `gitleaks`
**no leaks found** · `git diff --check` and `git fsck --full` exit 0. Determinism: 17/17 negative
controls fire; three consecutive content generations write 0 artifacts; deleting and rebuilding the
whole derivative tree reproduces **192 byte-identical files**.

**gitleaks correction.** The first run reported one leak — the PR #54 squash minted a new commit
introducing the same `DESIGN_TOKEN_SOURCE_SHA256` line, so the same non-secret carries a second
`commit:path:rule:line` fingerprint. It was re-verified from scratch (`Get-FileHash` on the
committed token source reproduces the flagged value exactly) and closed with **one** additional
exact fingerprint. No rule, path, entropy, workflow or generator change. Recurrence remains an open
residual for a durable, separately authorized treatment.

**Historical evidence.** The full Playwright run rewrote 139 UI-01 screenshots + `browser-evidence.json`
and 6 UI-04 screenshots + `screenshot-index.json`. Restored by enumerated path only — `git restore
--source=HEAD` for the 8 tracked blobs, `rm` for the 139 additions after asserting each sits under
`docs/proof/ui-01/screenshots/`. No `git clean`, no wildcard, no capture-policy change (UI-16 owns
that). `scripts/ui04-evidence-hash.mjs` reproduces the pre-run report byte for byte: **177 files
unchanged**.

**Local environment.** Meilisearch held 48 indexes (37 `servana_p22cmd_*`, 8 `servana_p22test_*`) —
two orders of magnitude below UI-04's contamination, engine healthy, no search test failed, so **no
cleanup was performed**. The Phase-22 test reaper stays a future search-test owner's work.

### Not done, and stated plainly

No landing page, no public FAQ route, no 160-page route contract, no account experience, no visual
baseline, no deployment. `UI01-ASSET-004`, `UI01-LEGAL-001` and `UI01-LEGAL-002` are **not** claimed
closed — a manifest is not a rendered page, and a compiled FAQ is not a route. Every skip has a
named owner in [ui-05.md](proof/ui-05.md) § *Skipped work and owners*.

### Commit and push state

One atomic completion commit — `ui-05: implement content and asset pipeline` — 306 files changed
(271 added, 24 modified, 11 renamed at `R100`, 0 deleted), exactly one commit ahead of
`origin/main`. Branch pushed. **No pull request created.** The commit hash is read from the pushed
branch rather than recorded inside the commit it names.

### Next human action — **done**

The UI-05 pull request was created, merged as `e6664f2e` with five successful required checks, and
reconciled to `verified_complete` on the UI-06 branch. See the UI-06 section above for the current
next action.

## Phase UI-04 — Design system and shared components (`verified_complete`)

**PR** [#54](https://github.com/ikrome002-design/servana/pull/54) **MERGED** · **squash merge**
`e6afe832fa9b45c4f452bcd43e19338ac87bfd9a` (single parent `00c9c1e…`) · **reviewed head**
`cf36cee837fa5f724a9e0d8b3018c9c868ce6697` · **tree equivalence** `cf36cee^{tree}` = merge tree =
`bd6728fb…` · **mergedAt** `2026-08-02T13:37:16Z` · **final CI** `30748616089`, Backend / Frontend /
Docker / Security / E2E all successful · **governance comment** `5158172398` · **`#FDBA74` hover
approved** by the product owner · **0 reviews submitted, 0 approving, `reviewDecision` blank —
not independent approval** · branches deleted · **thirteen closures promoted to `verified_complete`**.

The one CI failure (`30746233065`) was gitleaks against a single exact fingerprint — the reproducible
SHA-256 digest of the committed design-token source, printed in plaintext in the same generated
file's own header. **Verified false positive**; no credential existed, so nothing was revocable. The
correction is one historical fingerprint in `.gitleaksignore`; no rule, path, glob, entropy
threshold, workflow step or job permission was weakened, and UI-05 re-verified it is still the sole
active entry.

**Branch** `phase-ui-04-design-system-shared-components` · **base** `00c9c1e` (the verified UI-03
merge commit) · **proof** [ui-04.md](proof/ui-04.md) · **design system**
[`docs/frontend/design-system/`](frontend/design-system/) · **artifacts**
[`docs/frontend/audits/ui-04/`](frontend/audits/ui-04/)

**Governing sources.** UI/UX plan §9–§14, §17–§19, §21, §25 (Phase UI-04), §26, §28; ADR-009,
ADR-021, ADR-024, ADR-025; backend Plan §2 AS-3, §9 rule 7, §10.2, §11.5, §13.2.

### What UI-04 built

One canonical **design-token authority** (`tokens.json`) with a deterministic `--check` generator
— 48 palette, 48 semantic × 2 themes, 62 component tokens — and a **computed** contrast contract
(35 requirements evaluated in both themes) that caught three real failures on its first run ·
**light as the clean-browser default** with `prefers-color-scheme` removed from every layer, plus
the smallest self-owned authenticated persistence (`users.theme_preference`, own-scope endpoint,
**no permission key**) · **Heroicons** pinned and imported individually, with a source guard whose
negative controls fire on the exact glyphs UI-01 recorded · a **web app manifest** referencing both
approved Android icons · **54 shared `Sv*` components** behind a machine-verified registry, with
one focus trap for every overlay, one association owner for every form control, and one column
contract driving both the desktop table and the mobile record cards · the final **profile control**
and **account-context switcher** built on UI-03's server-derived contexts · a **fixed footer**
whose height token also drives the page reserve · a distinct **Human Resource shell**.

Four legacy duplicates were **removed rather than aliased** (`SvInput`, `SvTextarea`, `SvModal`,
`SvAccountSwitcher`), with 71 files migrated onto the canonical names.

### Defects closed (locally)

Five inherited: `UI01-NAV-002`, `UI01-THEME-001`, `UI01-ASSET-001`, `UI01-ASSET-003`,
`UI01-RENDER-001`. **Eight** found during the work: **`UI04-TOKEN-001`** (16 `var(--x, #hex)`
fallbacks across 14 pages whose custom properties were never defined, so the literal colour was
*live*), **`UI04-TOAST-001`** (colour-only status, a nested live region, positioning inside the
fixed-footer band, toasts added after mount never expiring), **`UI04-POPOVER-001`** (Escape and
outside-click dead when mounted already open), and five surfaced by the **final full Playwright
gate**: **`UI04-TOKEN-002`** (the shell avatar paired Brand Deep with a surface that inverts by
theme — 1.07:1 in dark on every shell-bearing screen, 103 failures from one element),
**`UI04-RESP-001`** (the profile trigger sized to its content, overflowing the header at exactly
768px), **`UI04-TOAST-002`** (`aria-live` confers no role, so the toast stack could not be
addressed by role after `UI04-TOAST-001` removed `role="status"`), **`UI04-A11Y-001`** (the profile
control hard-coded dark text, unreadable on the Super Administrator's brand-deep header) and
**`UI04-TOKEN-003`** (the light-theme brand hover shade gave 1.89:1 against the ADR-009-fixed CTA
label; the contract gated only the base colour).

That gate had previously been recorded clean from a **truncated terminal tail**; read in full it
reported 166 failures. Closure evidence:
[`defect-closure.json`](frontend/audits/ui-04/defect-closure.json). All thirteen are now
`verified_complete` (PR #54 merged). The historical UI-01 register is unchanged.

**`UI04-TOKEN-003` was approved at review.** It changed a visible brand hover shade (light theme,
`#9A3412` → `#FDBA74`, 1.89:1 → 8.17:1 against the ADR-009-fixed CTA label). It altered none of the
seventeen approved brand palette values and was forced by guardrail 11. The product owner recorded
the approval in governance comment `5158172398`, so the flag is discharged.

### Permissions, policies, migrations

**No permission key. No permission-matrix change. No policy change.** One expand-only migration
(`users.theme_preference`, nullable + `CHECK`), with its data-dictionary entry written first. One
route added (`PATCH /api/v1/auth/preferences`, own scope); route total 301 → 302.

### Not done, and stated plainly

No content compiler, no landing pages, no FAQ route, no 160-page route contract, no account
experiences, no release-wide visual baselines, no deployment. `SvNotificationsControl` is a
data-driven visual contract because **no notification API exists** — no table, controller, route
or fake record was created. Every skip has a named owner in
[ui-04.md](proof/ui-04.md) § *Skipped work and owners*.

### Outcome

Done. The pull request was created, reviewed under the solo-maintainer exception, and merged; UI-04
and its thirteen closures were reconciled to `verified_complete` on the UI-05 branch.

## Phase UI-03 — Authentication, session family, account switching (`verified_complete`)

**PR** [#53](https://github.com/ikrome002-design/servana/pull/53) **MERGED** · **merge commit**
`00c9c1e0025e3979464691be662915ada872cc18` · **reviewed base** `fb64ba67…` · **final head**
`182f2cca…` · **proof** [ui-03.md](proof/ui-03.md) · **threat model**
[ui-03-auth-session-threat-model.md](security/ui-03-auth-session-threat-model.md) · **artifacts**
[`docs/frontend/audits/ui-03/`](frontend/audits/ui-03/)

**Governing sources.** UI/UX plan §5.1–§5.4, §18.1–§18.7, §21, §23–§26, §25 (Phase UI-03), §27;
ADR-016/017/018/019; backend Plan §9, §18, §70, §79 R6, §13.2.

### Merge facts (verified live on the UI-04 branch)

A **regular merge commit** preserving **four reviewed commits** — parents in order
`fb64ba67c8555ab68aff4f64d97a4d10e4eeab0f` then `182f2cca78f56e1d0f74984ccb723a83be805140`,
raw GitHub `mergedAt` `2026-08-01T07:08:07Z`:

1. `64ca7cc…` — `ui-03: secure host-bound authentication and account switching`
2. `415d2f5…` — `ui-03: complete deployed-origin browser proof`
3. `5bd6e12…` — `test: pin payout adjustment inside run period`
4. `182f2cc…` — `test: respect created-at-only adjustment schema`

Commits 3 and 4 are **fixture-only** corrections to a pre-existing compensation test that PR CI
exposed as calendar-boundary nondeterministic (`created_at` crossing the fixed July payout period
in `Africa/Nairobi`); the first correction then failed separately because the append-only
`compensation_adjustments` table has no `updated_at` column. The final commit removed only that
unsupported value, keeping the deterministic `created_at` and the `-1000` assertion. **No payout
production code, financial behaviour or database schema changed.**

Final CI run `30688440846` attempt 1, event `pull_request`, head `182f2cca…`, **five required jobs
all SUCCESS**. Governance comment `5150328091` present exactly once. `reviewDecision` blank with
**0 submitted reviews** — **not** independent approval. Both UI-03 branch refs deleted;
`main == origin/main == 00c9c1e…`.

### Acceptance evidence carried forward

Deployed-origin browser proof **47 observations / 0 failures** · local full Playwright
**865 passed / 0 failed** · **nine** committed UI-03 screenshots · preserved UI-01/UI-02 evidence
**163 files unchanged**. The merged full-suite backend baseline, read from the successful Backend
log, is **3,108 passed / 5 skipped / 0 failed** (20,697 assertions, parallel, 4 processes). The
retracted `2,528` figure (residual risk R8) is **not** reused; R8 is closed by this measurement.

### Defects closed — all `verified_complete`

`UI01-ROLE-001`, `UI03-AUTH-001`, `UI03-EDGE-001`, `UI03-CTX-001`, `UI03-MFA-001`, `UI03-TEST-001`,
`UI03-E2E-001`. Closure lifecycle:
[`defect-closure.json`](frontend/audits/ui-03/defect-closure.json). The historical UI-01 register
row is deliberately **not** rewritten — its audit-time state is evidence.

### Permissions

**None added.** Cross-user session management remains a **blocked decision** — no canonical
permission authority exists and UI-03 did not invent one.

### Residual risks still open after merge

`R2` (`requiresAccount` attached to `/platform` only → UI-07), `R4`–`R7`, `R9`, `R10` remain as
recorded in [ui-03.md](proof/ui-03.md). `R1` and `R3` were closed by the deployed-origin proof;
`R8` is closed by the merged full-suite baseline above.

## Phase UI-02 — Multi-host foundation (`verified_complete`)

**Branch** `phase-ui-02-multi-host-foundation` · **base** `413c146` (the verified UI-01 squash
merge) · **no PR** · **proof** [ui-02.md](proof/ui-02.md) · **artifacts**
[`docs/frontend/audits/ui-02/`](frontend/audits/ui-02/)

**Plan authority:** UI/UX plan §4.1–§4.7, §6.1–§6.5, §18.1, §18.5–§18.7, §21, §23–§26, §25
(Phase UI-02), §28; ADR-016, ADR-017.

### What UI-02 built

- **One account-host authority** — `config/account-hosts.json`, with three generated/derived
  consumers: Laravel config (`config/account_hosts.php`), the typed frontend registry
  (`resources/spa/src/host/accountHosts.generated.ts`) and the Nginx `server_name` allowlist
  (`docker/nginx/account-hosts.generated.conf`). Generator:
  `node scripts/generate-account-hosts.mjs` (`--check` in CI). **24 hosts = 8 accounts × 3
  environments**; staging is derived from a configured suffix, never hard-coded.
- **Backend host layer** — `AccountHostRegistry` (exact allowlist), `AccountHostResolver`
  (normalization, injection/ambiguity rejection, safe denial), `AccountHostUrlGenerator`
  (allowlist-backed absolute URLs, unsafe-redirect rejection), `ResolveAccountHost` middleware
  (421 + redacted logging), trusted-proxy configuration.
- **Deployed serving contract** — Laravel renders the SPA shell on every approved host with a
  server-resolved account context; Vite emits to `spa-assets/` so it no longer collides with
  Laravel's `public/assets`; Nginx owns one prefix per owner, default-denies unknown hosts with
  `444`, and models machine hosts in a separate block.
- **Frontend context bootstrap** — resolved before the router, validated against the address
  bar, fails closed on missing/malformed/unknown/mismatched context.
- **Foundation-only public surface** — each host renders its own account label and the approved
  logo. Explicitly **not** the finished landing page.

### The four UI-01 defects UI-02 owns (locally closed)

| Defect | Prior severity | Result |
|---|---|---|
| `UI01-PROV-001` | critical | Root serves the Servana shell on all 8 hosts; the Laravel scaffold view is deleted |
| `UI01-PROV-002` | critical | Entry JS/CSS load 200 with correct MIME and immutable caching under `/spa-assets/` |
| `UI01-HOST-001` | high | 24-host exact allowlist; unknown hosts closed at the edge; host proven non-authorizing |
| `UI01-ASSET-005` | low | Favicons and `Logo.png` 200 on every host; `Logo.svg` 404 everywhere |

Closure evidence: [`defect-closure.json`](frontend/audits/ui-02/defect-closure.json). They are
**not** `verified_complete` until the UI-02 PR merges. The other **23** audited defects stay
open with their original owners (UI-03 … UI-17); the UI-01 register was not rewritten.

### A defect this phase found in its own design

Rehearsing the smoke against the **built images** returned `500` on every browser route: the
Vite manifest was absent from the application image (`public/spa` is in `.dockerignore`, the SPA
is built into the nginx image, and prod shares no volume). Fixed with a `spa-manifest` stage in
`docker/php.Dockerfile`. The dev stack hid it because both containers bind-mount `./public` —
which is precisely why the host matrix runs against the real production topology.

### Deliberately not done

Magic Link host binding and session families (**UI-03**) · design tokens, shared components,
theme correction (**UI-04**) · content compilation (**UI-05**) · the eight production landing
pages (**UI-06**) · the full 160-page runtime route contract (**UI-07**) · account experiences
(**UI-08 … UI-15**) · release-wide accessibility/visual-regression approval and the
deployed-origin browser gate, `UI01-PROV-003` (**UI-16**) · production DNS, TLS, HSTS,
deployment (**UI-17**) · **backend Phase 25** (not started) · Gate-W-blocked work (**still
blocked**).

### Next exact action

Review the pushed branch `phase-ui-02-multi-host-foundation`, create the UI-02 pull request into
`main`, allow the five required checks and review/governance to complete, merge, then reconcile
UI-02 and the four defect closures to `verified_complete` before beginning UI-03.

## Phase UI-01 — As-built browser and repository audit (`verified_complete`)

> **Lifecycle closure (reconciled live on the `phase-ui-02-multi-host-foundation` branch,
> 2026-07-29).** PR [#51](https://github.com/ikrome002-design/servana/pull/51) MERGED into
> `main` as squash commit `413c1466be96373a408954c3813b982241b25273` (== `origin/main`), sole
> parent `d3f6e10c1ff9490bc558199940f76fbec9497272`, final PR head
> `5c52372e78088ebeb23bcb7d98bbc0d750681149`, source tree == merge tree
> `e00866fa7839e242baf5b23fc6782413948ea7ff`, merged `2026-07-29T12:33:28Z`. Final CI run
> `30450612654` conclusion `success` with all five required checks SUCCESS. Governance comment
> `5117766612` present exactly once; `reviewDecision` blank with **0** submitted reviews —
> **not** independent reviewer approval. Local and remote UI-01 branches deleted. All UI-01
> audit artifacts are preserved unmodified. The section below is preserved as written during the
> phase; its "no PR" / "local_complete" wording is historical.

**Branch** `phase-ui-01-as-built-browser-audit` · **base** `d3f6e10` · **PR #51 merged** ·
**proof** [ui-01.md](proof/ui-01.md) · **artifacts** [`docs/frontend/audits/ui-01/`](frontend/audits/ui-01/)

### UI-00 closure, verified live before any UI-01 work

PR **#50** *Phase UI-00: Adopt role-specific UI/UX subdomain plan* is **MERGED**: base `main` ←
head `plan/role-ui-ux-subdomains`; final PR head `c09adbaf479d62e5db839ca6dd99fd42d31df57b`; squash
merge `d3f6e10c1ff9490bc558199940f76fbec9497272`; sole parent `db3827be40194c4a3905679e5d182f014113641b`
(Phase 24, PR #49); merge tree `360278dbc9172384e4f8d290f882c421aa794dac` **equal** to the final
PR-head tree; merged `2026-07-29T06:35:40Z`; final successful CI run **30425113792** with **exactly
five** required checks all `SUCCESS`; governance comment **5114064349** present once, naming that
head and run; `reviewDecision` **blank**; **0 submitted reviews** — no independent reviewer approval
exists or is claimed; UI-00 branch absent local and remote. UI-00 is **verified_complete**.

### What UI-01 delivered

Evidence and classification only — **no corrective runtime product code**. Served-build provenance
proven end to end (commit → tree → Docker image → Vite manifest → emitted asset → browser-loaded
asset); the repository's route, component, navigation, page-claim and content graph audited as it
stands; 141 provenanced baseline screenshots; and a 27-defect register, all open, each with a
future owner phase and an acceptance test.

| Measure | Result |
|---|---|
| Implementation claims classified | 123 → **95 true · 4 false · 1 unreachable · 0 stale · 23 not_claimed** |
| Required 160-page contract | **42 claimed_by_route · 118 not_claimed** |
| Router records / named routes / layout shells | 126 / 116 / 10, **0 duplicate names, 0 duplicate paths** |
| Navigation registry vs generated fixture | 102 vs 102 items, **0 drift, 0 dead links** |
| Navigation placement vs contract | **8 of 8 match** (Super Admin header, seven sidebar) |
| Baseline screenshots | **141 captured, 0 unreachable, 0 failed** across 360/767/768/1024/1025/1280/1440 |
| Defects | **27 open** — 2 critical, 7 high, 10 medium, 3 low, 5 observation |

**Two critical findings.** The deployed application serves no working Servana frontend: `/` returns
the stock Laravel welcome page, and `/spa/` mounts nothing because `vite base: '/'` conflicts with
the nginx `/spa/` alias so every chunk 404s. Both were invisible to 846 passing Playwright tests,
because the entire existing browser suite runs against `vite preview` — an origin that exists in no
deployment.

### Skipped work, owners, risk and entry conditions

| Item | Owner | Why skipped | Risk if forgotten | Entry condition |
|---|---|---|---|---|
| Eight production hosts, nginx, URL generation | UI-02 | out of UI-01 scope; the audit must not create hosts | contracted account separation never arrives; the two critical serving defects persist | UI-02 start |
| Magic Link host binding, session families, account switching | UI-03 | out of scope | cross-host session behaviour and the `/platform` role guard stay unproven | UI-03 start |
| Design tokens, shared components, theme, footer, icons | UI-04 | out of scope | dark-by-OS default, emoji icons and unguarded money rendering persist | UI-04 start |
| Content compiler, legal compilation, image manifest | UI-05 | out of scope | 11 unapproved brand files keep shipping publicly | UI-05 start |
| Eight public landing pages and FAQ surfaces | UI-06 | out of scope | no public entry point; 8 FAQ paths render a broken state | UI-06 start |
| Full 160-page runtime route contract | UI-07 | out of scope | 118 contract paths stay unrouted behind a silent catch-all | UI-07 start |
| Eight account experiences | UI-08 … UI-15 | out of scope | account pages stay incomplete; 4 dashboards remain stubs | each phase start |
| Release responsive / accessibility / theme / visual audit | UI-16 | UI-01 captured a baseline only and made **no** accessibility verdict | UI-01 images mistaken for approved baselines | UI-16 start |
| Frontend performance, security, deployment closeout | UI-17 | out of scope | build-integrity and Node-engine gaps persist | UI-17 start |
| **Backend Phase 25** production deployment | Phase 25 | needs its own product-owner authorization | — | separate authorization |

**External gates unchanged.** `docs/integrations/wallet/gate-w-evidence.md` and
`docs/proof/phase-20d-w.md` are both **absent**, so Gate W remains **CLOSED** and **20D-W**,
**21R-B** and **21N** remain blocked. `REM-PERM-002`, `REM-EXP-001`, `REM-SMS-002` and
`REM-RE-002` stay open and unchanged; no `REM-*` item was manufactured for a UI-01 finding.

### Next exact action

Review the pushed branch `phase-ui-01-as-built-browser-audit`, create the UI-01 pull request into
`main`, allow the five required checks and the review/governance process to complete, merge, then
reconcile UI-01 to `verified_complete` before beginning UI-02.

## Phase 24 — Performance optimization (`verified_complete`)

> **Lifecycle closure (reconciled live on the `plan/role-ui-ux-subdomains` branch, 2026-07-28).**
> PR [#49](https://github.com/ikrome002-design/servana/pull/49) MERGED into `main` as squash commit
> `db3827be40194c4a3905679e5d182f014113641b` (== `origin/main`), squash parent
> `13f54a4df54a46abb2928783373383a87ba301d2`, final PR head
> `46bed762f3e9afadce920ba9376bf6bc6f9b6e5e`, head branch `phase-24-performance-optimization`,
> merged `2026-07-28T08:19:47Z`. Final CI run `30340905747` conclusion `success` with all five
> required checks SUCCESS. `reviewDecision` blank under the PR-specific solo-maintainer exception
> with **0** submitted reviews — **not** independent reviewer approval. Local and remote Phase 24
> branches deleted. The section below is preserved as written during the phase; the
> "no PR" / "local_complete" wording in it is historical.

**Branch** `phase-24-performance-optimization` · **base**
`13f54a4df54a46abb2928783373383a87ba301d2` (the verified Phase 23 PR #48 squash-merge) ·
**PR #49 merged** · proof: [phase-24.md](proof/phase-24.md) ·
benchmark profile: [phase-24-benchmark-profile.md](performance/phase-24-benchmark-profile.md) ·
baseline: [phase-24-baseline.md](performance/phase-24-baseline.md).

**Plan sections:** §80 Phase 24 entry (Correction 24.2), **§72**, §69, §67, §71, §13, §19, §23–§25,
§28–§30, §64–§65, §68, §73–§76, §80.1, §85.

### Entry gates (both passed, verified live)

- **Gate A — Phase 23 PR #48 verified MERGED.** Squash `13f54a4…` == `origin/main`, squash parent
  `d010ec5…`, final head `ee2dc2b…`, merged 2026-07-27T19:18:34Z; final CI run **`30296509464`**
  five required checks SUCCESS; governance comment **`5095716132`** present exactly once;
  `reviewDecision` blank, **0** submitted reviews (**not** independent approval); both Phase 23
  branches deleted; `git fsck --full` exit 0; divergence `0 0`; tree clean.
- **Gate B — External Gate W remains CLOSED.** `docs/integrations/wallet/`,
  `docs/integrations/wallet/gate-w-evidence.md` and `docs/proof/phase-20d-w.md` are all absent, so
  **20D-W / 21R-B / 21N stay blocked**. The live §80.1 chain `… → 22 → 23 → 24 → 25` makes Phase 24
  executable; the launch rule binding 20D-W and 21R-B applies at **Phase 25 exit**, not Phase 24
  entry.

### Phase 23 reconciliation performed on this branch

Roadmap row + section header + `docs/CHANGELOG.md` + `docs/proof/phase-23.md` (new §0 merge-closure
table; no technical proof rewritten) + `docs/remediation/register.yaml`
(**REM-SCR-002**, **REM-TRACE-001** → `verified_complete`, each with `completion_commit 13f54a4…`)
+ `docs/traceability/servana-requirements.csv` (`SRV-SEC-001`, `SRV-MERCHANT-PROFILE-001`,
`SRV-BRANCH-CALENDAR-001` → `verified_complete`, 62 rows: 54 verified / 3 blocked_external_gate /
3 deferred_future_phase / 2 architecture_adopted). **REM-PERM-002** and **REM-EXP-001** left open
and unchanged. The traceability guard was retargeted (`23` → verified list;
`P23_IN_FLIGHT_PHASE = '24'`), 14 → 15 cases, re-run **15 passed / 20 assertions**.

### Baseline inventory (measured, not quoted)

Routes **301** (126 GET; **70** parameterless `api/v1` collection endpoints); paginated call sites
**51 / 45 files**; migrations **118**; factories **80**; **application data-cache call sites = 2**,
both the `HealthController` deep probe — Servana performs **no** response/data caching today;
**11** named rate limiters; **0** pre-existing performance/query-count tests; `DatabaseSeeder` has
no demo tenant volume, so a perf dataset must be constructed. Env: 4 CPU / 7.90 GiB host, Docker
4 CPU / ≈3.77 GiB, PHP 8.3.32, PG 16.14, Redis 7.4.9, Node 24.15.0.

### Carried-work table (each verified live, not trusted from the old note)

| Historical item | Live finding | Phase 24 action | Result/evidence |
|---|---|---|---|
| OPcache/preload (Phases 2–5) | **GAP** — `docker/php.Dockerfile:3,76` and `opcache.ini:1` claim prod preload; **no `opcache.preload` directive exists anywhere**. OPcache itself is correctly on (`validate_timestamps` 0 prod / 1 dev). | implement deterministic prod preload | Increment 7 → PH24-OPCACHE-001 |
| Per-role `roleContent` split (Phase 11) | **GAP** — 16 markdown files (8 landing + 8 FAQ) statically imported into one module; 4 consumers pull all eight roles' copy. Legal docs already lazy. | role-level lazy split + bundle guard | Increment 6 → PH24-BUNDLE-001 |
| Estimator recomputation (Phase 16B) | **GAP** — `recalculateBranch()` ≈ **E × (4 + S)** queries; eligibility/staff/availability re-resolved per entry; `currentState()` re-queries because its existing `$rows` argument is never passed. | eliminate the amplification | Increment 4 → PH24-QUEUE-001 |
| Busy personnel in wait estimate (Phase 16C) | **GAP** — estimator uses `AvailabilityResolver` (schedule only, "`busy` is NOT computed here"); the authoritative `PersonnelStateProjector` is never consulted, so mid-session personnel inflate `active_capacity` and the advertised wait is **under-estimated**. | align denominator with the authoritative busy projection | Increment 4 → PH24-QUEUE-002 |
| p95/load proof (Phase 23 handoff) | **ABSENT** — no harness, dataset, query-count guard or p95 record. | deliver | Increments 2–3, 8 |

### Skipped / deferred work (verified live, not copied forward)

| Work | Reason not done in Phase 24 | Correct owner | Risk if forgotten |
|---|---|---|---|
| Wallet performance (initiation ≤2 s, webhook ack p95 ≤250 ms) | Gate W CLOSED; no runtime exists | 20D-W → Phase 25 production recheck | integration performance unverified at launch |
| R&E inbound/qualification performance | dependency blocked behind 20D-W | 21R-B | runtime unverified |
| Horizon, class-separated queue topology, notifications, scheduled reports | 21N blocked behind 20D-W | 21N | no production queue/report topology |
| Production availability / RPO / RTO | operational proof; not establishable locally | Phase 25 | launch readiness incomplete |
| Deployment, secrets manager, backups/PITR, restore drill, runbooks, alerts | out of Phase 24 scope | Phase 25 | no production readiness |
| REM-EXP-001 export-retention scheduling | owner blocked | 21N | retention convergence stays open |
| REM-PERM-002 lifecycle-vs-read authority asymmetry | product-owner permission decision required | authorized future remediation | Merchant Admin can still manage a staff profile it cannot read |
| `exports.staff_roster` disposition | no Plan definition | product owner / authorized permission remediation | inert key remains |
| Tenant/branch switcher | no Plan §27.3 launch specification | product decision | no switcher surface |
| Live SMS provider (REM-SMS-002), live R&E sandbox (REM-RE-002) | external onboarding | external | must close before Phase 25 exit |

### Increments

- **Increment 1 — predecessor reconciliation + baseline plan: COMPLETE.** Gate A + Gate W verified
  live; Phase 23 reconciled across six files; runtime/surface inventory captured; all four carried
  items proven still open against current code; §72 ownership matrix recorded;
  `docs/proof/phase-24.md`, `docs/performance/phase-24-benchmark-profile.md` and
  `docs/performance/phase-24-baseline.md` created. Verification: `Phase23TraceabilityTest` 15
  passed / 20 assertions. No product code changed; no migration; no commit.
- **Increment 2 — deterministic dataset harness: COMPLETE.** `PerformanceDatasetSeeder` (3 tiers,
  built from the repo's own 80 factories) + `config/servana.php → performance.tier`. Two safety
  guards: refuses to run outside local/testing, and refuses any database whose name is not
  disposable; not wired into `DatabaseSeeder`. Proven on disposable **PG 16.14 `servana_p24_perf`**
  (118 migrations from zero → seed → measure → **dropped**, verified absent; dev DB untouched).
  `baseline` generated **933 rows** (3 merchants / 6 branches / 48 services / 36 staff / 240 clients
  / 216 availability / 144 eligibility / 72 queue entries / 18 in-progress sessions). One **fixture**
  defect of mine found and fixed: a `called` entry written without `assigned_at` was correctly
  rejected by `queue_entries_assigned_at_check` — the schema was right, the fixture was wrong; no
  constraint weakened.
- **Increment 4 — queue estimator + busy projection: COMPLETE.** Taken before Increment 3 because
  one carry-forward is a **correctness** defect. Three defects fixed:
  - **PH24-QUEUE-002 (correctness).** Estimator counted mid-session personnel as capacity. Measured:
    with 1 of 2 busy the estimate stayed **45** (expected 90); with both busy **30** (expected 60).
    Root cause — capacity used the schedule-only `AvailabilityResolver` ("`busy` is NOT computed
    here"), never the authoritative `PersonnelStateProjector` already used by the availability read.
    Fixed → 45→**90**, 30→**60**, completion restores 45. `busy` stays derived, never stored;
    formula, label, scopes and the `max(1,…)` floor unchanged; no state-machine change.
  - **PH24-QUEUE-001 (N+1).** Measured pre-fix: `estimateFor` **14 queries**; `recalculateBranch`
    **60** (4 entries) and **252** (16 entries). Root cause — capacity re-resolved per entry and
    `AvailabilityResolver::currentState()` queried once per personnel because its existing `$rows`
    argument was never passed. Fixed with `AvailabilityResolver::rowsForMany()` +
    `PersonnelStateProjector::busyAmong()` (each rule stays with its owner), capacity resolved once
    per distinct service, work-ahead by in-memory prefix scan. `estimateFor` **14 → ≤6**; capacity
    cost now **constant (4)** regardless of eligible-personnel count.
  - **PH24-QUEUE-003 (newly discovered).** Statement capture then showed **3 extra SELECTs per saved
    entry** — Phase 22 made `QueueEntry` searchable and Scout indexes per save, eager-loading that
    document's relations each time. Fixed by persisting inside `withoutSyncingToSearch()` and
    re-indexing the changed set **once**; indexing is not disabled and the index ends identical.
    **22 → 13 statements** for a 5-entry recalculation. Final shape `9 + C` for `C` changed entries.
  - Gates: `QueueWaitEstimatorQueryBudgetTest` **6 passed/11 assertions**; `tests/Feature/Scheduling`
    **176 passed/474**; `tests/Feature/Search` **173 passed/808**; Pint **PASS (1684)**;
    Larastan L8 **no errors (1303)**. No migration, no index (none yet justified by a plan), no
    authorization/tenant/masking/state-machine/financial change.
- **Increment 3 — query/index/pagination/N+1 review: COMPLETE.** Representative tier built on
  disposable **PG 16.14** (`servana_p24_perf_rep_20260728064707`, 118 migrations from zero,
  **15 360 rows**, dropped + verified absent, dev DB untouched; seed 744 s). **70** parameterless
  `api/v1` collection endpoints inventoried and grouped by query pattern. Five
  `EXPLAIN (ANALYZE, BUFFERS)` plans captured — worst **3.025 ms** (merchant-wide clients, deep
  offset 400); no disk sort. **No index added, no migration**: every filter/sort is index-backed or
  trivially bounded, and the single Seq Scan is on a 90-row table (cheaper plan — documented
  justified exception). **Pagination bounded everywhere** (23/44 files use the shared `ApiPagination`
  contract, the other 21 apply identical `min(max(per_page,1),100)` bounds) — recorded, not changed:
  the shared contract 422s an over-limit `per_page` while the duplicated clamp silently clamps (API
  contract inconsistency, not a perf defect). **No N+1 remains** — 4 collection guards assert query
  count *equality* across two cardinalities. One false positive dismissed with evidence (7
  controllers lack eager loads because their Resources serialize only own-row columns). Two harness
  defects of mine found + fixed: `ServiceCategoryFactory`'s global `fake()->unique()` over a 6-name
  pool capped the dataset at 6 branches (worked around in the seeder, shared factory untouched); and
  Scout was syncing seeded rows into the **developer's** Meilisearch indexes because `scout.prefix`
  is `servana_{APP_ENV}_`, not database-derived — seeding now runs inside `withoutSyncingToSearch`
  and the polluted indexes were flushed.
- **Increment 5 — cache-scope audit + forward guard: COMPLETE.** Re-verified live: **0 application
  data caches**, 3 cache/Redis sites (all `HealthController`), **11** rate limiters all keyed by
  principal/IP, **0 unsafe keys**. **No cache added.** New forward guard
  `CacheScopeGuardTest` (4 cases) fails on any undeclared new cache site, keeps the allowlist
  honest, records the Plan §69 key dimensions, and rejects a global-bucket rate limiter.
- **Increment 6 — role content lazy split: COMPLETE (PH24-BUNDLE-001).** New
  `content/roleDocuments.ts` loads landing+FAQ via `import.meta.glob` (the pattern `legalContent.ts`
  already used); `roleContent.ts` is now markdown-free; `RoleLandingScaffold.vue` loads its role's
  two documents async with loading/error states + stale-response guard. **484.3 KB raw / 144.7 KB
  gzip → 54.8 KB raw / 16.5 KB gzip per role (-88.6 %)**; `roleContent` chunk **→ 0.2 KB**; 16
  independently-fetched chunks; a role downloads **2** documents, not 16. Content verbatim, no
  legal/branding/nav/permission change. Guard `roleDocuments.spec.ts` (5 cases). Bug caught while
  implementing: my glob used `docs/landing page/` (stale CLAUDE.md path note) — real directory is
  `docs/landing_page/`.
- **Increment 7 — production OPcache preload: COMPLETE (PH24-OPCACHE-001 + -002).** New
  `docker/php/preload.php`; `opcache.preload = ${PHP_OPCACHE_PRELOAD}` in the shared ini; prod points
  at it, dev empty; `preload_user` deliberately unset (pool is non-root). Prod image verified by
  **running it**: `uid=1000(servana)`, `opcache.preload` resolved, `validate_timestamps => Off`,
  `[servana-preload] compiled 2522 files, skipped 0`, pool `ready to handle connections`.
  **PH24-OPCACHE-002 found only by booting the image**: the first version used `fwrite(STDERR,…)`,
  but STDERR is CLI-only, so under php-fpm it fataled and preloaded **nothing** while still looking
  correctly configured — fixed with `error_log()`, plus a guard rejecting CLI-only constructs.
  **Cold-start timing reported as inconclusive** (13.41 s vs 38.73 s with preload; 32.19 s vs 5.79 s
  without) — host contention dominates; no improvement claimed. Guard
  `OpcachePreloadConfigurationTest` (11 cases). No deployment performed.
- **Increment 8 — §72 proof + global gates: COMPLETE.** Three complete benchmark runs, 0 errors
  across 630 requests. **Worst read p95 120.31 ms** (≤500) · **worst write p95 58.22 ms** (≤800);
  p95 reported per run + conservative worst-run, never averaged. Harness defect caught first: one
  principal exhausted the 120/min `api` limiter so four surfaces timed fast **429s** at ~5 ms with a
  100 % error rate — fixed with a per-endpoint principal (throttle stays in the measured path) plus
  a status precondition. Blocked/deferred §72 targets recorded as such, never as passes.

**Full measurements:** [phase-24-results.md](performance/phase-24-results.md).

### Final local gates (all sequential on the 4-CPU / 7.90 GiB host)

composer validate OK · Pint **PASS 1 689** · Larastan L8 **0 errors / 1 303 files** ·
**backend serial 2 368 passed / 8 skipped / 0 failed / 14 106 assertions** and
**`--parallel` identical 2 368 / 8 / 0 (4 procs)** · Phase 24 perf suites **25 passed / 1 skipped**
(skip = opt-in latency benchmark) · ESLint **0 errors / 138 warnings** (= Phase 23 baseline) ·
vue-tsc clean · **Vitest 551 / 101 files** (was 544 / 100) · build PASS ·
**full Playwright 846 passed / 0 failed** (= Phase 23 baseline, no retries) ·
OpenAPI **247 paths / 296 operations unchanged**, contract + permission-types checks green ·
**generator determinism 5/5 byte-identical over two passes** · composer audit clean ·
**npm audit 0 vulnerabilities** · gitleaks no leaks · Docker dev + php-prod + nginx-prod all build ·
**disposable PG 16.14 proof**: 118 migrations from zero, 97 tables, 0 forbidden Wallet/21N tables,
audit-chain verifier clean, dropped + verified absent, dev DB untouched · `git diff --check` clean.

**No migration added** — count unchanged at 118, consistent with the index review's conclusion.

### Failed runs and classification

| Run | Classification | Resolution |
|---|---|---|
| Representative seed — Faker `OverflowException` | harness defect (mine) | seeder creates the category directly; shared factory untouched |
| Representative seed — polluted dev Meilisearch index | harness defect (mine) | seed inside `withoutSyncingToSearch`; indexes flushed |
| Benchmark attempt 1 — ~5 ms p95 with 100 % errors | harness defect (mine) — rate limiter exhausted, timing 429s | per-endpoint principal + status precondition |
| Prod image boot — `Undefined constant "STDERR"` | **product defect in new Phase 24 code** (PH24-OPCACHE-002) | `error_log()`; guard rejects CLI-only constructs |
| Playwright run 1 — webServer 120 s timeout | environment/load flake (backend suite running concurrently) | isolated re-run **846 passed**; no code/timeout/retry/assertion changed |

### Residual risks

Laptop wall-clock is not a production guarantee (Phase 25 owns production verification) · preload
cold-start benefit unquantified (host contention) · search latency not benchmarked on the
representative dataset (Scout deliberately skipped during seeding) · the `per_page` 422-vs-clamp
contract inconsistency remains across 21 controllers (no unbounded collection either way) ·
benchmark cardinality is split between per-branch end-to-end latency and whole-database `EXPLAIN`
evidence.

**Exact next human action:** open the pull request for `phase-24-performance-optimization` → `main`,
let the five required checks run, and record the governance evidence. **Phase 25 must not begin
before Phase 24 merges.** Gate W remains CLOSED → 20D-W / 21R-B / 21N stay blocked, and §80.1 still
requires 20D-W and 21R-B to complete before **Phase 25 exit**.

## Phase 23 — Security hardening, responsive/dark/a11y release audit, threat model, traceability (`verified_complete`)

**Branch** `phase-23-release-hardening-audit` (deleted local + remote) · **base**
`d010ec50f412dfe97ee1c412362e16bf263c2a4d` (the verified Phase 22 squash-merge) ·
**PR #48 MERGED** as squash `13f54a4df54a46abb2928783373383a87ba301d2` (final head
`ee2dc2b48d50ff156f8034552d9965bbb4186967`, merged `2026-07-27T19:18:34Z`) · final CI run
`30296509464`, five required checks SUCCESS · governance comment `5095716132`,
`reviewDecision` blank, 0 submitted reviews (**not** independent approval) ·
proof: [phase-23.md](proof/phase-23.md).

> Lifecycle note: the increment narrative below was written while Phase 23 was in flight and is
> preserved as historical technical evidence. Its `local_complete` / "no PR" statements are
> superseded by the merge facts in this header, reconciled from live Git/GitHub evidence on the
> `phase-24-performance-optimization` branch.

### Entry gates (both passed, verified live)

- **Gate A** — `git fsck` clean; branch/HEAD/`origin/main`/merge-base all `d010ec5`; divergence `0 0`;
  tree+index+untracked clean; Docker healthy (10 services); PostgreSQL reachable. PR #47 re-verified:
  MERGED, head `8dbb274`, squash parent `1e1b0fd`, CI run **`30218560304`** five checks SUCCESS,
  governance comment `5085264996`, `reviewDecision` blank (**not** independent approval), 0 reviews,
  both Phase 22 branches deleted.
- **Gate B — External Gate W is CLOSED.** `docs/integrations/wallet/gate-w-evidence.md` and
  `docs/proof/phase-20d-w.md` are both **absent**; `docs/integrations/` holds `refer-earn/` only.
  Therefore **20D-W blocked**, **21R-B blocked** (needs 20D-W), **21N blocked** (needs 20D-W).
  The live §80.1 chain `… → 22 → 23 → 24 → 25` makes Phase 23 executable with Gate W closed.

### Baseline inventory

Routes **294 → 295** (287 → 288 under `api/v1`; 117 → 118 GET-only; 170 mutating, unchanged).
Screens **124** = 98 implemented + 18 phase_11 + **8 planned**; §27.1 spec coverage **116/116**;
one orphan spec `finance/finance-dashboard.md`. Tests: 344 backend files, 98 Vitest, 33 Playwright.
Traceability: 53 rows — **1 `not_implemented`**, **2 narrative-prose statuses**, 6 stale statuses.
REM-TRACE-001 `in_progress` (CI gate never wired).

Planned-screen ownership — 20D-W ×2 and 21N ×2 are **truthfully blocked**; `merchant-profile` (15A),
`branch-calendar` (16A) and `platform-audit-reports` (19) are **proven release gaps** whose owners are
`verified_complete`; `hr-eligibility` (15B) is a **likely duplicate** of the implemented
`service-eligibility` at the same route name. Classification is pending in the screen-inventory increment.

### Work completed

**Defect PH23-SEC-001 — `GET /api/v1/staff` had no authorization boundary.** Any authenticated
merchant member (Front Office, Personnel, **Audit**, Finance, Branch Manager) could enumerate the
branch staff roster **with unmasked phone numbers**: no `EnsurePermission`, no `authorize()` call, no
`viewAny` on the policy, and `StaffProfileResource` returns `phone`. Root cause: Phase 20F never
performed the `staff.view` activation it owned, so the canonical key stayed `planned` and could not be
referenced by middleware. **Product-owner decision: the narrow options endpoint (Phase 20G precedent).**

- `staff.view` activated across YAML/PHP/DB/TS — **HR-only**, per Plan §19.3. Active **130 → 131**,
  planned **38 → 37**, catalogue **168 unchanged**.
- `StaffProfilePolicy` gained `viewAny`; `view` separated from `manage` (read never implies mutation).
- New **`GET /api/v1/branch/personnel-options`** gated by the existing `branch.dashboard.view`,
  returning only `{id, display_name}`; `PersonnelSchedule.vue` migrated to it. **No role grant widened,
  no permission key invented**; the Branch Manager's exposure shrank.
- Regression caught and fixed: staff **search** anchored on the old `manage` authority, so the gate and
  the per-record re-check drifted. `StaffSearchDefinition::canSearch()` now uses `staff.view` —
  search is never a wider surface than the `hr.staff-profile` page its results link to.
- Stale owner notes corrected: the `StaffProfile` "Phase 23 upload seam" comment (the `profile_photo`
  pipeline is **owned and delivered by Phase 10F**) and the search catalogue's staff row.

**Defect PH23-DET-001 — the compensation suite depended on the wall clock (PRE-EXISTING on `main`).**
The full serial suite reported **27 failed**. 22 were `tests/Feature/Compensation/`, all
"A backdated compensation change requires an impact preview before approval", and **all 22 reproduced
identically on the pristine base under `git stash`**. Root cause: `app.timezone` is `UTC` but the
domain decides business **days** in `Africa/Nairobi` (CLAUDE.md §1, Plan §59), so between **21:00 and
23:59 UTC** Laravel's `today()` is yesterday's business date and a fixture meant to be effective today
is evaluated as backdated. Production code is **correct** (every business-date decision routes through
`CompensationBusinessDate`; bare `now()` is only used for UTC timestamps) and the SPA defaults
`effective_from` to empty, so this was a **fixture** defect. Fixed with a `businessToday()` Pest helper
across all 87 call sites, plus a regression guard that **pins the clock inside the failing window** and
was verified non-vacuous (reverting the fixture makes it fail 422). The F8 backdating control is
unchanged — a genuinely backdated plan still returns 422 at that same instant.

**Defect PH23-TEST-001 — global constant collision (mine, fixed).** The other 5 failures were Phase 20G
`CommissionRuleServiceOptionsTest` returning 403: my new test declared a file-scope
`const OPTIONS_URL`, which in Pest is **global** and collided with that suite's identically-named
constant, redirecting its requests to the new endpoint. Renamed to `BRANCH_PERSONNEL_OPTIONS_URL`; an
audit confirmed it was the only duplicate PHP file-scope constant in `tests/`.

### Work explicitly NOT done, with owners

| Item | Owner | Blocker |
|---|---|---|
| Wallet collections/runtime/webhooks/reconciliation | Phase 20D-W | External Gate W CLOSED |
| R&E qualification, activity, inbound reconciliation | Phase 21R-B | needs 20D-W |
| Horizon, queue topology, notifications, scheduled reports, search-index worker, drift scheduler, SMS retention scheduler | Phase 21N | needs 20D-W |
| Live SMS provider verification (REM-SMS-002), live R&E sandbox (REM-RE-002) | external onboarding | must close before Phase 25 exit |
| Performance/p95, opcache/preload | Phase 24 | Phase 23 must complete first |
| Deployment, secrets manager, backup/PITR, restore drill, scheduled audit-chain verification | Phase 25 | Phase 24 must complete first |
| Profile-photo / merchant-logo upload workflows | **not Phase 23** | old-numbering PROGRESS notes; pipeline already delivered by 10F; any UI belongs to the `merchant-profile` screen gap |

### Increment 2 — whole-product threat-model verification (DONE)

The matrix is **machine-checked**, not prose: `P23_THREAT_MATRIX` in
`tests/Feature/Security/Phase23ThreatModelCoverageTest.php` is the source of truth, and
`docs/security/phase-23-threat-model-verification.md` renders it. The guard fails if a scenario loses
evidence, a referenced suite is renamed/deleted, a status leaves the closed vocabulary, or a scenario
id vanishes from the document. **All 40 scenarios (TM-01…TM-40) have a definite disposition:
36 `automated`, 2 `absence_proof` (TM-18 export-shaped routes, TM-29 SSRF), 2 `blocked_external_gate`
(TM-39 R&E inbound → 21R-B, TM-40 Wallet webhook → 20D-W).** Vague statuses are rejected.

Three absence proofs run directly: **no user-controlled outbound fetch exists anywhere in `app/`**
(the one HTTP client targets `config('refer-earn.base_url')`); **no Wallet webhook/provider callback
route exists** and `docs/integrations/wallet/` is absent; **no inbound R&E write route exists**. No
route was created merely to test forgery — that would implement a Wallet-owned capability inside
Servana. Also added `docs/security/phase-23-penetration-test-checklist.md` (A–I, 40 items) including
the Plan-mandated outbox-tamper and webhook-forgery cases; H1–H3 are recorded **BLOCKED, not passing**.

*Self-inflicted issue, fixed:* the guard first flagged `POST api/v1/testing/step-up/…` — the Phase R3
**test-only** harness, never registered outside `testing`. Pattern narrowed to inbound partner-writes.

### Increment 3 — route/permission/policy/contract hardening (DONE)

**Protected-read coverage guard** (`ProtectedReadAuthorizationCoverageTest`) — the gap that allowed
PH23-SEC-001, since `RouteSecurityContractTest` classifies non-GET routes only. It walks the live
route table and requires one of four named boundary kinds per read route.
**Result: all 118 read routes already had a real boundary — no new defect.** The 13 initially flagged
resolved to `EnsureBranchScope`, `EnsureFirstTimeSetupAccess`, policy calls via private helpers,
direct `PersonnelAvailabilityPolicy` invocation, `FileAccessService`, or
`abort_unless($this->context->can(...))`. **Five documented exceptions** remain (`me`,
`auth.mfa.status`, `search.index`, `branches.index`, `merchant.dashboard`), each with an enforced
substantive reason and a tripwire capping the list at 6. `staff.index`, `staff.show`,
`branch.personnel-options.index`, `files.show`, `files.download` have **pinned** boundary kinds so a
refactor cannot silently downgrade them.

**Forbidden-capability guard** (`Phase23ForbiddenCapabilityAbsenceTest`) — extends absence proof
beyond routes to the permission registry, canonical matrix, OpenAPI, both generated TS contracts,
screen inventory and navigation YAML. Covers Super-Admin merchant/first-admin creation and
impersonation, personnel contact export in every form, Wallet ledger/reconciliation concepts, R&E
referrer/reward/payout concepts, provider runtime surfaces, provider config namespaces and
frontend-held Meilisearch credentials. A **guardrail on the guard** proves the legitimate
`mpesa_offline` payment method survives every pattern (all provider patterns match a path *segment*).

**Contract-privacy guard** (`Phase23ContractPrivacyTest`) — no storage-internal/secret field name
(`phone_index`, `phone_encrypted`, `email_encrypted`, `totp_secret`, `token_hash`, `webhook_secret`,
`signing_key`, …), no credential/JWT-shaped literal, no absolute private storage path in any
published contract; no Resource exposing masked **and** raw phone; no Playwright artefact tracked in git.

**No production code changed in Increments 2–3** — no defect required one.

Gates: threat guard **6 passed** · protected-read **5 passed** · forbidden-capability **6 passed** ·
contract-privacy **5 passed** · combined security/route-contract/permissions/matrix/isolation/tenancy/
phase23 **471 passed, 4 skipped, 0 failed** (6 077 assertions) · Pint PASS (1 665 files) ·
Larastan L8 clean · `git diff --check` clean.

### Increment 4 — finance and audit export hardening (DONE)

Canonical matrix is **machine-checked**: `P23_EXPORT_SURFACES` / `P23_NON_DOCUMENT_ROUTES` in
`tests/Feature/Security/Phase23ExportHardeningTest.php` is the source of truth;
`docs/security/phase-23-export-hardening.md` renders it. The guard fails when a new export-shaped
route ships unclassified, when a classified route disappears, or when a declared control leaves a
live route.

**Inventory:** **22 document surfaces** (5 finance-export · 6 audit-export · 3 file-domain · 3 receipt ·
4 subscription-invoice · 1 personnel statement) + **13 shaped-but-not-document routes** recorded with
reasons. Exactly **2** routes serve raw bytes (`audit-exports.download`, `files.download`) — both
signed **and** authenticated **and** re-authorized at stream time. **No** report-download or
scheduled-report route exists; `DayCloseReport`/`CashUpReport` purposes are registered but their
generators belong to **Phase 21N** (Plan §69) — truthful absence, no export type created.

All 22 required controls have automated evidence (per-control table + the Audit-role table —
branch-scoped only, `branch_id IS NULL` never exported, review/export metadata as the only Audit
write, operational/financial/hash-chain source rows immutable — are in the matrix doc). **Two
controls were defective:**

- **PH23-EXP-001 — revocation/expiry never reached the file domain.** Revoking a finance/audit export
  set only the **export** status; the `UploadedFile` stayed `available/clean`, and the Phase 10F
  routes authorize on the **file's** lifecycle. `POST /api/v1/files/{ulid}/download-link` therefore
  re-issued a fresh signed URL for a revoked export **indefinitely** (the caller learns the file ULID
  from the very link it was legitimately issued). Proven red-then-green: 4 cases returning **200**
  where 404 was required. Fixed by writing the terminal state onto the file in the same transaction
  (`markLifecycle(Revoked|Expired)` in all four revoke/expire actions) — the same pattern
  `GenerateSubscriptionInvoicePdf` already uses; `ExpireSignedExport` now also sweeps `revoked` rows
  so byte cleanup is not regressed. Residual: **REM-EXP-001** (opened) — a domain-triggered expiry
  marks the file `expired`, which the sweep does not re-select; inert today (neither expiry action is
  scheduled, and the hourly sweep fires at the same retention instant). Owner **Phase 21N** (§67).
- **PH23-EXP-002 — the billing invoice PDF purpose declared no resource permission.**
  `FilePurpose::BillingInvoicePdf` had `permission => null` with no owner scope, so tenant membership
  alone authorized it: **Front Office and Personnel could download the merchant's subscription
  invoice** via the generic file routes, bypassing Merchant-Admin-only
  `merchant.subscription.invoice.download`. Fixed by declaring that **existing** key on the purpose,
  plus a new guard that fails for **any** generated purpose with neither a resource permission nor
  owner scope — the mechanical check this defect escaped. Three `SubscriptionInvoicePdfDownloadTest`
  cases then failed: a **fixture** artefact (they authorized under `bindForJob`, which carries no
  permissions by design, with a membership-less user). Repaired to bind a real Merchant Administrator
  through `TenantContextResolver::populate()` — a strengthening, not a weakening.

One **detector** false positive corrected in the detector: `Route::gatherMiddleware()` returns Laravel
aliases (`auth:sanctum`, `signed`) where `route:list --json` prints FQCNs; the middleware was present.

Gates: export-hardening guard **12 passed** (59 assertions) · export/file/finance/audit/billing/
receipt/isolation directories **714 passed, 7 skipped, 0 failed** · combined 14-group regression
**898 passed, 7 skipped, 0 failed** (8 455 assertions) · Pint PASS (1 666 files) · Larastan L8 clean ·
`git diff --check` clean.

### Increment 5 — requirement traceability and CI enforcement (DONE)

**REM-TRACE-001 → `local_complete`.** The CSV is a checked contract now:
`tests/Feature/Traceability/Phase23TraceabilityTest.php` (14 cases) + the extended
`resources/spa/src/screens/screenInventory.spec.ts` (8 → 13 cases), both wired into CI as **named
steps** (Backend: `Contract — requirement traceability (Plan §85)`; Frontend:
`Contract — screen inventory and §27.1 specifications`). Vocabulary documented in
`docs/traceability/README.md`.

**Closed 7-value vocabulary:** `verified_complete` · `local_complete` · `implemented` ·
`architecture_adopted` · `blocked_external_gate` · `deferred_future_phase` · `not_applicable`.
Rejected: `not_implemented`, `partially_implemented`, any prose/multiline status.

**Five drifts reconciled from live evidence (CSV 53 → 60 rows):**

1. **18 rows** sat at `implemented` while every owning phase (3–9, R2–R4) is merged **and** verified
   (PRs #3–#9/#14–#16 green, a proof file each, Phase V as-built PR #12 `c58b64a`, gate closure PR #20
   `7ac20a5`; PROGRESS records R2/R3/R4 as `verified_complete` verbatim) → `verified_complete`.
2. **4 stale** against merged owners: `SRV-PERM-002`, `SRV-AUDIT-005` (Phase 19 PR #32 `7ef259e2`),
   `SRV-COMPENSATION-001` (20F PR #39 `f4bc664`), `SRV-COMPENSATION-002` (20G PR #41 `dcdbfb6`).
3. **`SRV-AUDIT-003`** `partially_implemented` → `verified_complete`: Plan §70 assigns integration
   audit events to their owning phases (20D-W/21R-A/21R-B), so they are **not** in this row's scope;
   `AuditMutationCoverageTest` proves every live mutating route emits a typed event.
   **`SRV-AUDIT-004`** `not_implemented`/phase 25 was **stale and wrong** — the scheduled chain
   verification *and* its bounded redacted failure signal shipped in Phase 19 Increment 7
   (`routes/console.php` `audit:verify-chain` daily/withoutOverlapping/onOneServer +
   `AuditChainScheduleTest`/`AuditChainFailureSignalTest`). Only the centralized alert transport is
   Phase 25 → new `SRV-AUDIT-006` (`deferred_future_phase`).
4. **`SRV-PAYMENT-001/002`** carried whole CI histories as prose inside `status` → moved verbatim to
   `evidence`.
5. **41 references in `automated_tests` named suites that do not exist** (`PayoutRunStateMachineTest`,
   `PromotionStateMachineTest`, `LargestRemainderAllocationTest`, `SalaryProrationTest`,
   `CommissionCalculationTest`, …) across 6 rows — aspirational names never reconciled to the suites
   that shipped. All replaced with the real per-domain suite lists.

**5 new rows model deliberately-absent work:** `SRV-WAL-002` (20D-W), `SRV-RE-002` (21R-B),
`SRV-REPORT-001` (21N) all `blocked_external_gate` with a named gate **and** a named absence test;
`SRV-AUDIT-006`, `SRV-PERF-001` (24), `SRV-DEPLOY-001` (25) `deferred_future_phase`; `SRV-SEC-001`
(Phase 23, `implemented` — a guard case forbids `verified_complete` before PR merge).
Final: `verified_complete` 51 · `blocked_external_gate` 3 · `deferred_future_phase` 3 ·
`architecture_adopted` 2 · `implemented` 1.

**Defect PH23-SCAN-001 (evidence integrity, no product impact).**
`RecursiveIteratorIterator(RecursiveDirectoryIterator)` **truncates directory listings** on this dev
bind mount: **970 of 1 087** PHP files under `app/` (10.8% skipped); in `tests/Feature/Auth/` it
returned 15 of ~40, starting mid-alphabet, so `PermissionMatrixTest` was invisible while
`file_exists()` returned true. Five static guards were built on it — including the **TM-29 SSRF
absence proof** that claims to walk every file under `app/`, plus `NoDirectProviderIntegrationTest`
(§9 rule 20), `FileStorageBoundaryTest` (§65), the forbidden-capability SPA scan, and
`ReferEarnScopePurityTest`. Fixed with one shared `sourceFilesUnder()` helper in `tests/Pest.php`
(scandir recursion, sorted) adopted by all five, **plus a coverage self-check** on the SSRF proof
asserting its walked count equals an independent Symfony Finder count. All five now pass with 117
more files in scope. No production code involved.

**Screen inventory — 3 metadata fixes + 1 release gap (124 → 123 entries, 116 specs, 0 orphans):**

- **deleted** the orphan generated spec `finance/finance-dashboard.md` — proven stale: a Phase 11
  (PR #23 `d098f37`) stub whose inventory key was **renamed** to `finance-task-inbox` by Phase 18B
  (PR #31 `64bd0a1`), which generated the replacement and left this behind;
- **removed** the duplicate planned entry `hr-eligibility` — identical domain/layout/roles/permissions
  to the **implemented** `service-eligibility`, which already owns route `hr.eligibility`; the
  availability half is `personnel-schedule` (15B);
- **re-attributed** `platform-audit-reports` Phase 19 → **Phase 21N** (Plan §69 puts the whole
  reporting catalogue in 21N; every §27.3 Audit-role screen is implemented).

⛔ **REM-SCR-002 OPENED — two Plan §27.3 launch screens do not exist.** `merchant-profile`
(owner 15A, `verified_complete`) and `branch-calendar` (owner 16A, `verified_complete`) are `planned`
with no route and no spec. `merchant.profile.manage` is an **active** key whose only consumer is the
`merchant_logo` file purpose; `branch.calendar.manage` is an **active canonical** key with a **live
`branch_calendar_exceptions` table** and **zero** route/controller/policy/screen. No vulnerability
(the capability is absent, not unguarded) — a **release-completeness** gap. Building either is 15A/16A
feature scope, not release hardening, so Phase 23 does **not** invent it. Both are registered in the
screen guard's `REGISTERED_RELEASE_GAPS` (keyed to REM-SCR-002), and that list is asserted **exact**,
so the guard fails the moment either screen is delivered. **This blocks Phase 23 local completion and
needs a product-owner decision.**

Also recorded: `branch.calendar.manage` and `exports.staff_roster` are the **only two** of 131 active
permission keys with no consumer of any kind (an audit of all 131 against the live route table found
18 without `EnsurePermission`; 16 are legitimately enforced by a policy call,
`abort_unless(context->can(...))` or a file purpose). `exports.staff_roster` ("Export the staff roster
only", HR default grant) **has no Plan definition at all** and sits beside the permanently prohibited
contact-export boundary (§9 rule 6) — recorded, not changed (matrix change needs product-owner
authority).

Gates: traceability **14 passed** · screen inventory **13 passed** · security/files/integrations/
infrastructure **314 passed, 3 skipped, 0 failed** · combined 9-group backend **497 passed, 4 skipped,
0 failed** (6 156 assertions) · Vitest **527 passed / 98 files** · ESLint **0 errors, 138 warnings**
(exact baseline) · Pint PASS (1 667) · Larastan L8 clean · `git diff --check` clean.

*Environment note (not a regression):* a background group run overlapping a foreground run against the
same `servana_test` database produced `QueryException` failures across `tests/Feature/Files/`. Re-run
in isolation: **41 passed, 3 skipped, 0 failed**. Concurrent `artisan test` processes must not share
the dev test database.

### REM-SCR-002 — the two omitted Plan §27.3 launch screens (DELIVERED)

**Product-owner decision 2026-07-27: Option A — build both screens now.** Option B (accept as a
documented pre-release gap and close Phase 23) was declined; Plan §27.3 was **not** amended. Executed
on this branch as bounded corrective remediation for omitted owning-phase deliverables, deliberately
**before** Increments 6–9 so the release-wide audits include both screens instead of auditing an
absent surface. Full Bug-Fix-Protocol record in `docs/proof/phase-23.md` §12.

**REM-SCR-002A — merchant business profile.** Activated the canonical §19.3 pair
`merchant.profile.view` (`M|-|A|n/a|Y|-|info`) + `merchant.profile.update` (`M|-|R|n/a|Y|-|high`),
Merchant Administrator only, and **retired the legacy duplicate `merchant.profile.manage`** — the
matrix invariant forbids a legacy key whose successor is active, and retirement-on-activation is the
precedent Phases 20A/20B/20E/20F each applied (legacy keys 8 → **7**). Its `merchant_logo` file
purpose moved to the canonical write key; the dead `MerchantPolicy::manageProfile` (no caller) was
deleted. `GET|PATCH /api/v1/merchant/profile` carry **no `{merchant}` binding** (tenant from the
membership); `UpdateMerchantProfile` locks the row, writes a 7-field allowlist, and audits
`merchant.profile_updated` with **field names only, never values**. The logo stays on the existing
Phase 10F scanned pipeline — no second upload path; the never-written legacy `logo_path` column was
left untouched.

**REM-SCR-002B — branch calendar.** **No key activated, none invented** (`branch.calendar.manage` was
already active — it was one of only two active keys with no consumer anywhere). Four routes inside
`EnsureBranchScope` gated by that key, `EnsureBillingMutable` on every write, `BranchMutation`
classification. `(branch, date)` is the public identity (no ULID) and **exactly one exception per
date** — which also removes a latent non-determinism in the pre-existing
`AppointmentBranchScheduleValidator`, whose `whereDate(...)->first()` lookup would have been
order-dependent with two rows on a date. Closure types normalise to a null window and reject supplied
times; `modified_hours` requires an ordered window, because the validator treats a windowless
modified-hours row as fully closed.

**Counts.** Permissions active 131 → **132**, planned 37 → **35**, catalogue 168 → **167** (shrank
only by the retired legacy duplicate), legacy 8 → **7**. Routes 295 → **301** (`api/v1` 288 → 294,
GET 118 → 120); OpenAPI **247 paths / 296 operations**. Screens: `implemented` 98 → **100**, `planned`
7 → **5**, specs 116 → **118**, 0 orphans. Registered release gaps **2 → 0**.

**Gates:** `tests/Feature/Merchants/` + `tests/Feature/Branches/` **67 passed** (301 assertions) ·
component specs **17 passed** · combined 16-group backend regression **680 passed, 7 skipped, 0
failed** (7 824 assertions) · traceability guard **14** · screen guard **13** · Vitest **544 passed /
100 files** · ESLint **0 errors, 138 warnings** (exact baseline, restored via `--fix` on the new files
only) · vue-tsc clean · production build OK · Pint PASS (1 682) · Larastan L8 clean ·
`api:contract:check` OK · `permission-types --check` up to date · `git diff --check` clean.

**No migration was added** — both surfaces write existing as-built tables (`merchant_profiles`,
`branch_calendar_exceptions`).

### Increments 6–9 — whole-product release audit (COMPLETE)

One data-driven matrix — `tests/e2e/phase-23-release-audit.spec.ts` +
`tests/e2e/support/releaseAudit.ts` — derived from `docs/frontend/screens/inventory.json` **at run
time**, auditing every live screen: **100 `implemented` + 18 `phase_11` = 118**. A coverage guard
fails in both directions, so a later screen cannot escape the audit and no invented key can pass.
The 5 `planned` screens are owned by phases that genuinely have not shipped and are not audited.
Full matrix: [phase-23-responsive-dark-audit.md](frontend/phase-23-responsive-dark-audit.md) ·
[phase-23-release-audit.md](accessibility/phase-23-release-audit.md).

| Increment | Coverage | Result |
|---|---|---|
| 6 responsive | 118 screens × 360 / 768 / 1280, navigate once then **resize** | **118 passed** |
| 7 dark mode | 118 screens × light / dark | **118 passed** |
| 8 accessibility | 118 screens × {mobile, desktop} × {light, dark} = **472 axe analyses** | **118 passed — 0 serious, 0 critical** |
| 8 behavioural | skip link, landmarks, drawer focus/Escape/restore, real `Tab` focus ring, 200 % zoom, reduced motion | **6 passed** |
| 9 concurrent | `--workers=4` | **367 passed, 0 failed, 0 flaky** (7.7 m) |
| 9 repeated serial | `--workers=1 --repeat-each=3` | **1 101 passed, 0 failed, 0 flaky** (34.3 m) |
| 9 full suite | `npm run e2e` | **846 passed, 0 failed, 0 flaky** (25.4 m) |

Retries **0** (local), skipped **0**, flaky **0**. No sleep added, no timeout raised, no retry
enabled, no assertion weakened, no screen or role skipped.

**Three product defects found and fixed**

- **PH23-RSP-001** — `MerchantProfile.vue` / `BranchCalendar.vue` hand-rolled inputs omitted the
  `w-full` the shared `SvInput` carries (`SvInput.vue:62`); a 241 px intrinsic input width propagated
  up `form → SvCard → section → main` and scrolled the page at 360 px. Fixed with `w-full` on all 15
  inputs/selects plus `min-w-[8rem] flex-1` on the `flex-wrap` time/reason wrappers.
- **PH23-RSP-002** (**pre-existing**, shell-wide) — `main#main-content` is a flex item with the
  default `min-width: auto`, so one wide child widened the whole document; the audit-action heading
  `branch.calendar_exception_set` is 376 px of unbreakable machine token. Fixed with `min-w-0` on
  `main` (`AppShell.vue`) **and** `break-words` on the heading (`AuditEventDetail.vue`) —
  `overflow-wrap: break-word` does not reduce min-content width, so neither works alone.
- **PH23-A11Y-001** (**pre-existing**, critical) — `RegistrationMonitoring.vue` nested both
  `role="tabpanel"` elements inside `SvStateBoundary`, which renders its slot only in the `success`
  state, so in loading / empty / error the tabs' `aria-controls` referenced nothing. Both panels now
  always exist with the inactive one `hidden`, the boundary renders inside each, and the directory
  grid moved to an inner wrapper (a `display: grid` class outranks the `hidden` attribute).

**Two detector false positives — corrected in the harness, no product change:** a contrast probe
that read `bg-white/15` over the dark brand header as solid white, and a focus-ring probe using
`element.focus()` (which never matches `:focus-visible`) instead of a real `Tab` traversal.

**Two environment findings — not product defects, not flakes** (proof §17):
`ENV-P23-E2E-001` browser OOM during long single-worker runs on this 8 GB host (1 903 MB free with
the Docker stack up) and `ENV-P23-E2E-002` a shared preview-server teardown when an overlapping
`npm run e2e` shut down the port-4173 server the repeated-serial run had reused
(`reuseExistingServer: true`) — 14 `ERR_CONNECTION_REFUSED`, zero assertion failures. Both were
resolved operationally: the repeated-serial proof was rerun **unchanged** in isolation with the
Docker stack stopped.

### Final Phase 23 gate results

| Gate | Result |
|---|---|
| `composer validate --strict` | PASS |
| Pint | PASS — 1 682 files |
| Larastan | level 8, **0 errors** |
| Backend serial (`php artisan test`) | **2 342 passed, 7 skipped, 0 failed** — 14 012 assertions, 1 442.31 s |
| Backend parallel (`php artisan test --parallel`) | **2 342 passed, 7 skipped, 0 failed** — 14 012 assertions, 597.34 s |
| ESLint | **0 errors, 138 warnings** (exact baseline) |
| `vue-tsc` | clean |
| Vitest | **544 passed / 100 files** |
| Production build | PASS |
| Targeted backend suites (15 feature dirs + `tests/Unit/Scheduling`) | **1 264 passed, 7 skipped, 0 failed** — 7 979 assertions |
| Concurrent Playwright (`--workers=4`) | **367 passed, 0 failed, 0 flaky** — 7.7 m |
| Repeated-serial Playwright (`--workers=1 --repeat-each=3`) | **1 101 passed, 0 failed, 0 flaky** — 34.3 m |
| Full Playwright (`npm run e2e`) | **846 passed, 0 failed, 0 flaky** — 25.4 m |
| Generator determinism | 5 artefacts, two passes, **byte-identical**, 0 mismatches |
| `composer audit --locked` | no advisories |
| `npm audit --audit-level=high` / `npm audit` | **0 vulnerabilities** |
| gitleaks | no leaks (25.44 MB) |
| Docker php dev / php prod / nginx prod | exit 0 (0.5 m / 2.0 m / 2.7 m), 0 warnings |
| Disposable PostgreSQL 16.14 proof | `servana_p23_proof_20260727191124` — 118 migrations, 97 base tables, 132 permissions, forbidden-table scan empty, `audit:verify-chain` exit 0, dropped and verified gone |

**Live counts** (measured, not assumed): screens 100 implemented + 18 phase_11 + 5 planned
(**118 live**) · specifications **118**, spec files on disk 118, **orphans 0** · registered release
gaps **0** · OpenAPI **247 paths / 296 operations** · traceability **62 rows** · routes **301** ·
permission catalogue **167 = 132 active + 35 planned**, plus **7** legacy-active keys
(`PermissionLegacyKeyReconciliationTest` asserts exactly 7).

### Open decisions / residual risk

1. **Merchant Admin can `manage` a staff profile it cannot `view`** — pre-existing, from the *legacy*
   `branches.manage_users_lifecycle` whose canonical successors are `merchant.user.suspend` /
   `merchant.user.deactivate`. Reconciling it is a permission-matrix change needing product-owner
   authority; **out of Phase 23 scope**. Tracked as **REM-PERM-002**, which stays open.
2. `staff_profiles.phone` remains plaintext at rest (§74 concern; no active Phase 23 requirement).
3. **REM-EXP-001** — a domain-triggered export expiry marks the file `expired`, which the file-domain
   retention sweep does not re-select, so its bytes would outlive the retention window. Inert in
   production (the expiry actions are not scheduled; the hourly sweep fires at the same instant).
   Owner **Phase 21N** (Plan §67). Stays open; the Phase 21N scheduler was **not** implemented here.
4. **REM-SCR-002 → `local_complete pending PR`** — both Plan §27.3 launch screens are delivered and
   now carry full responsive / dark-mode / accessibility / E2E coverage. Promotes to
   `verified_complete` only on the Phase 23 PR merge.
5. **REM-TRACE-001 → `local_complete pending PR`**.
6. `exports.staff_roster` is the **last** ACTIVE permission key with no consumer and **no Plan
   definition at all**. Retiring or re-scoping it is a permission-matrix change requiring
   product-owner authority.
7. A calendar exception's `(date, type)` identity is not re-pointable — the operator deletes and
   re-creates. Deliberate (that pair is what `UNIQUE(branch_id, date, type)` and the scheduling gate
   key on) and recorded in the §27.1 spec.
8. **Tenant switching and branch switching have no implemented surface** — no screen, control, route
   or inventory entry; the tenant and assigned branches are resolved server-side from the caller's
   membership. Plan §27.3 defines no switcher, so building one would be new feature delivery outside
   this audit. Recorded for the product owner; **not** a registered release gap.
9. **Gate W remains CLOSED** — `docs/integrations/wallet/` is absent, and Phases **20D-W**,
   **21R-B** and **21N** stay truthfully blocked. Nothing they own is stubbed or simulated.

### Next exact action

**Human:** open the Phase 23 pull request from `phase-23-release-hardening-audit` into `main` and let
CI run. Phase 23 stays `local_complete pending PR CI/review/merge` until that PR merges with green
CI; only then do REM-SCR-002, REM-TRACE-001 and the Phase 23 traceability rows become
`verified_complete`. **Do not begin Phase 24** — Plan §80.1 places it after Phase 23 closes.

## Phase 22 — Search (local_complete pending PR)

Implements Plan **§68** (Search) and **§80 Phase 22**, under **§64**, **§73** (RK-05 personnel
contact exfiltration), **§74**, **§24.5**, **§23/§24.1–24.2**, **§19.4** and **ADR-010**.
Branch `phase-22-search` off **`d8a7a15…`** (the verified Phase 21S PR #45 merge commit).
Proof: [phase-22.md](proof/phase-22.md). Specification:
[search-catalogue.md](architecture/search/search-catalogue.md) ·
[search-indexing.md](architecture/search/search-indexing.md) ·
[search-security.md](architecture/search/search-security.md).

**External Gate W re-verified CLOSED before branch creation** (`docs/integrations/wallet/`,
`gate-w-evidence.md` and `docs/proof/phase-20d-w.md` all absent; `docs/integrations/` holds only
`refer-earn/`). So **20D-W** stays blocked by Gate W, **21R-B** stays blocked behind 20D-W, **21N**
stays blocked by `(17,18,20D-W) → 21N`, and Plan §80.1 line 2517 makes **Phase 22 the next
executable non-Wallet phase**.

**D-22-01 — the search gate adds no permission (escalated to the product owner and resolved).** The
live matrix holds **130 active / 38 planned** keys and **none is owned by Phase 22**; no `search.*`
key exists anywhere; the only search key is the front-office-only `front_office.search`. Rather than
invent `search.global.view` or broaden that key, Phase 22 treats search as an **aggregator over
existing authorities, not an independent data authority**. `GET /api/v1/search` is authenticated,
tenant-scoped, active-membership-gated and rate-limited; each result type is admitted only after the
server proves the caller already holds the authority governing that type's own list/detail route;
and a caller with no searchable authority receives **200 + an empty collection, never 403** (a 403
would be an existence oracle over the catalogue). Consequently
`docs/auth/permission-matrix.yaml`, `PermissionRegistry`, `docs/proof/phase8-matrix.txt` and the
generated `permissions.ts` are all **unmodified** — proven by `Phase22SearchGateTest`.

**Three independent layers must agree before a row is returned:** the Meilisearch query carries a
server-built `merchant_id`/`branch_id` filter; the candidate ids are re-resolved through the model's
own tenant-scoped query with `BelongsToMerchant`/`BelongsToBranch` still applied; and every surviving
record re-passes `Gate::allows('view')` — the same policy call its own detail route makes. A
**deliberately poisoned index** (a foreign row written with this tenant's tenancy pair) is proven to
return nothing, so security never depends on the engine.

**Contact protection is a schema property, not a condition (D-22-03).** The search response has **no
contact field at all** — no `phone`, `phone_masked`, `phone_last_four`, `email` or `email_masked` —
even though four of the underlying canonical Resources return masked client contact today. Nothing
sensitive is indexed (`phone_encrypted`, `email_encrypted`, `phone_index`, blind indexes,
`StaffProfile::phone`, operator free text). Authorized **exact** phone lookup exists for Front Office
through the existing keyed HMAC blind index, never reaches Meilisearch, returns the client's name
only, and is **redacted out of `meta.query`** rather than echoed back; a partial phone fragment is
not searchable anywhere.

**Catalogue:** `client`, `staff`, `appointment`, `queue_entry`, `service_session`, `invoice`,
`receipt` (indexed) + **`served_client`** (Personnel own-scope, **never indexed** — delegated to the
Phase 21S `ServedClientSelector`). `service` and nine other candidates are deferred with recorded
reasons. **No migration and no table** — Phase 22 is additive behaviour over existing substrate.

**Gate evidence:** composer validate OK; Pint **1655 files PASS**; Larastan L8 **0 errors (1287
files)**; full backend **serial 2229 passed / 7 skipped / 0 failed / 13336 assertions** (2006/7/0 at
Phase 21S → +223) and `--parallel` identical; **27 real-Meilisearch tests** against the live
`getmeili/meilisearch:v1.10` service (including the poisoned-index and settings-not-synced cases),
now also wired into the CI Backend job as a service container so they run in CI and **fail rather
than skip** without an engine; OpenAPI **243 paths / 289 operations** (242/288 → +1/+1) with
`api:contract:check` OK and the generators proven **byte-identical across the baseline and two
regeneration passes** (`permissions.ts` shows **no diff at all** — the mechanical proof of D-22-01);
ESLint **0 errors / 138 baseline warnings** (no new warning); vue-tsc clean; Vitest **519/519**
(501 → +18); build OK; Playwright **479 passed** (453 → +26) with axe serious/critical **0** across
360/768/1280 × light/dark and at 200% zoom; `composer audit` no advisories; `gitleaks` no leaks;
Docker dev app + prod app + prod nginx all built. The 7 skips are the pre-existing baseline (3
ClamAV opt-in-profile + 4 threat-model placeholders). **No migration and no table.**

**Phase 22 refreshed-head local verification is complete; pull request creation is pending.**
The original implementation commit
`edff8c059671b551eec1e6f9617ea3ae6add0d7b` remains preserved in refresh merge head
`e5df1834ab4cd726cfc501b20a177f8ab6d85a35`, whose second parent is REM-DEP-002 squash
merge `1e1b0fd3c9ed76a50e9d47adf1cea0c0222c1408`.

Refreshed-head local gates passed on 2026-07-26: Composer validation; Pint across 1,655
files; Larastan level 8 across 1,287 files; selected backend 223 tests / 908 assertions;
complete backend serial and parallel 2,229 passed / 7 skipped / 13,336 assertions;
two-pass generator determinism with 243 OpenAPI paths and 289 operations; ESLint with
0 errors and the established 138-warning baseline; vue-tsc; 519 Vitest tests across
98 files; Vite build; Composer audit; npm audit with 0 vulnerabilities; gitleaks;
26 selected Phase 22 Playwright tests; 479 complete Playwright tests; and PHP dev, PHP
prod and nginx prod Docker image builds.

The only post-gate source corrections are seven Pint-only blank-line insertions and one
three-line Playwright cross-phase truth update in `tests/e2e/phase-20f.spec.ts`; no
application behavior changed. No Phase 22 pull request exists. Lifecycle is
`local_complete pending PR`; Gate W remains **CLOSED**, and Phase 23 is not started.

## REM-DEP-002 — npm audit high-severity remediation (verified_complete — PR #46 merged)

Not a Plan §80 feature phase — a **dependency remediation** carried on its own branch
`remediation/rem-dep-002-npm-audit`, cut from `origin/main` = `d8a7a15…` (Phase 21S PR #45
merge). Register item **REM-DEP-002** (added 2026-07-25; it had never been recorded in
`docs/remediation/register.yaml` before). Proof: `docs/proof/rem-dep-002.md`.

- **Why it exists.** CI's Frontend job ends with `npm audit --audit-level=high`
  (`.github/workflows/ci.yml:184`). On `main` that command exits 1 with **15 high-severity
  findings**, so the job fails on *any* branch cut from `main` — including the pending
  Phase 22 PR — for a reason unrelated to the phase under review. Fixed on its own branch
  so the audit gate is never weakened and Phase 22 is never asked to carry an unrelated fix.
- **Root cause.** Only **two** published advisories, not fifteen:
  `GHSA-mh99-v99m-4gvg` (`brace-expansion <=5.0.7`) and `GHSA-r28c-9q8g-f849`
  (`postcss <=8.5.17`); the other 13 entries are transitive propagation through `minimatch`.
  The only patched `brace-expansion` is **5.0.8**, reachable only via `minimatch >= 10.0.3`.
  ESLint 9 pins `minimatch@^3.1.5` and `@eslint/eslintrc` (which default-imports it), so that
  chain cannot be cleared by an override — npm's own `fixAvailable` is `eslint@10.8.0`,
  `isSemVerMajor: true`.
- **Fix.** Semver-major upgrade *only* where an override is provably unsafe — `eslint`
  `^9.13.0 → ^10.8.0`, `@eslint/js → ^10.0.1`, `eslint-plugin-vue → ^10.10.0`,
  `typescript-eslint → ^8.65.0`, plus `vue-eslint-parser ^10.4.1` (now a peer) and
  `globals ^17.7.0` (now direct). Everywhere the consumer uses minimatch's **named** exports,
  a **scoped `minimatch ^10.2.5` override** (`@redocly/openapi-core`, `@vue/language-core`,
  `editorconfig`, `glob`) avoids four further majors — `vue-tsc`, `openapi-typescript`,
  `@vue/test-utils` and `glob` are **unchanged**. `postcss` cleared by lockfile refresh inside
  the existing `^8.4.47` range. Rejected with evidence: `npm audit fix --force` (downgrades
  `openapi-typescript` to 6.7.6 and `@vue/test-utils` to 2.4.0), `@redocly/openapi-core@2`
  (`ERR_PACKAGE_PATH_NOT_EXPORTED` against every `openapi-typescript@7.x`), and `vue-tsc@3`
  (new `TS6133` in Phase 21S `ClientSms.vue`).
- **Consequential edits.** `eslint.config.js` re-declares `globals.browser`
  (eslint-plugin-vue 9 injected it implicitly from its flat base config; v10 does not — a
  restoration, not a relaxation), and `BillingSettings.vue` changes `let next = index` to
  `let next: number` for `no-useless-assignment`, promoted into `js.configs.recommended` by
  ESLint 10. **Four files changed**; no backend file, route, table, permission key, policy,
  migration, generated artifact or CI configuration touched.
- **Gates (all green).** `npm audit --audit-level=high` → **0 vulnerabilities** (and 0 at every
  severity); ESLint **0 errors / 138 warnings — rule-for-rule identical to the ESLint 9
  baseline** measured in a throwaway worktree, so no lint regression; `vue-tsc` clean;
  `api:contract:check` **OK 242 paths / 288 operations** (unchanged); Vitest **501/501**;
  Vite build OK; Playwright **453 passed**; gitleaks no leaks; `git diff --check` clean;
  `composer validate --strict` OK; Pint 1611 files; Larastan L8 clean (1257 files).
  Vitest/Playwright counts match the Phase 21S baseline exactly — no test changed, skipped or
  weakened.
- **Backend suite is NOT green, and it is not this change's doing.** `php artisan test` reports
  **24 failed / 7 skipped / 1982 passed**. Proven pre-existing: `git diff origin/main` contains
  **zero** backend files, and re-running the failing file on a stashed, byte-identical
  `origin/main` checkout reproduces **exactly the same 6 failed / 1 passed**. Proximate cause is
  the `tests/Pest.php:444` helper calling `POST /queue-entries/{id}/call` on an entry still in
  `waiting`, a transition `QueueEntryStatus::allowedTransitions()` forbids by design
  (`waiting → assigned → called`); the trigger looks environmental (likely time-of-day personnel
  availability — these runs executed 23:00–00:00 `Africa/Nairobi`), since Phase 21S recorded
  2006 passed / 0 failed on the same unchanged code days earlier. **Flagged for separate
  investigation; deliberately not fixed here** — backend behaviour is outside REM-DEP-002's scope,
  and it does not affect the Frontend job this remediation exists to unblock.
- **Lifecycle.** ✅ **`verified_complete`** — PR #46
  **"REM-DEP-002: Fix npm audit dependency chain"** merged into `main` on 2026-07-26 as
  squash commit `1e1b0fd3c9ed76a50e9d47adf1cea0c0222c1408` from final PR head
  `b97340802ff8d142f0f7b0d8c0d7e4e65f28ea3d`. Backend, Frontend, Docker, Security and E2E
  checks passed. `reviewDecision` remained intentionally blank under
  `docs/governance/solo-maintainer-review-exception-pr-46.md`; this is not independent
  reviewer approval. Local and remote `remediation/rem-dep-002-npm-audit` branches were
  deleted.
- **Follow-on.** REM-DEP-002 no longer blocks Phase 22. The original Phase 22 implementation
  commit `edff8c059671b551eec1e6f9617ea3ae6add0d7b` remains preserved while `origin/main` is
  merged into `phase-22-search`. The complete Phase 22 gates, including
  `npm audit --audit-level=high`, must pass on the refreshed head before push and before the
  Phase 22 pull request is created. Gate W remains **CLOSED**; 20D-W, 21R-B and 21N remain
  blocked, and Phase 23 is not started.

## Phase 21S — Personnel Bulk SMS to Personally Served Clients (verified_complete)

**Closure evidence (verified live on the `phase-22-search` branch, 2026-07-25).** PR
[#45](https://github.com/ikrome002-design/servana/pull/45) "Phase 21S: Implement personnel bulk SMS"
is `MERGED` into `main`, base `main`, head `phase-21s-personnel-bulk-sms`, not a draft, merged
`2026-07-23T09:13:10Z`. Its three commits are each recorded exactly once: implementation
`9d2c547a4a8e8af76a80bc138ae0b608e448dfe7`, CI-fix `34a5921ca5b2f4502e20172c10ed472d7d416954`,
final head `dc48d095529757dd1282ad5a8659e8e087cbc2a8`. Merge commit
`d8a7a15603c22e41354e570f4d2735935468d973` == `origin/main`. Final CI run
[29992575586](https://github.com/ikrome002-design/servana/actions/runs/29992575586) — `pull_request`
on `dc48d09…`, `completed`/`success`, all five required jobs SUCCESS. `reviewDecision` **blank**
under the PR-specific solo-maintainer governance exception
([comment 5056479540](https://github.com/ikrome002-design/servana/pull/45#issuecomment-5056479540),
which states in terms that it "is not independent reviewer approval" and that "Gate W remains
closed") — **not** independent reviewer approval. Local and remote
`phase-21s-personnel-bulk-sms` branches both deleted. **REM-SMS-001 → `verified_complete`**;
**REM-SMS-002** stays open (no SMS provider is pinned by the Plan; live provider/callback
verification must close before Phase 25 exit).

Implements Plan **§64** (Personnel Bulk SMS), **§80 Phase 21S**, **§13.13** canonical DDL for
`personnel_sms_campaigns` / `personnel_sms_recipients` / `sms_delivery_attempts` /
`sms_billing_entries`, **§19.3/§19.4** (matrix + the non-overridable "Personnel can never gain
contact export"), **§20** (plan-entitlement enforcement), **§22** (billing-status gate), **§24.5**
(log redaction), **§70** (audit), **§73** (threat model: personnel contact extraction), **§74**
(privacy/masking), **ADR-010** (personnel contact protection), **ADR-005** (integer minor units).
Closes **REM-SMS-001**. Proof: `docs/proof/phase-21s.md`.

- **Lifecycle:** ✅ **`local_complete pending PR CI/review/merge`**. Branch
  `phase-21s-personnel-bulk-sms` created off `b5a8733616a4603996e18695db31528299cdf8d7`
  (PR #44 merge commit) after a clean-`main` preflight (`origin/main…HEAD` = `0 0`, clean tree, no
  staged files, `git fsck` clean, no pre-existing 21S branch). A **new IDE session** then verified
  the dirty checkpoint (branch/base/`0 0` divergence, 120 authorised working-tree entries, 0
  staged), reran every closure gate from the final tree (all green — see `docs/proof/phase-21s.md`
  §Quality gates), reconciled the documentation, and created the single completion commit
  `phase-21s: implement personnel bulk sms`; `origin/main…HEAD = 0 1` after push. It becomes
  `verified_complete` only on the reviewed PR merge — **no PR exists yet**, so it is not
  `verified_complete`, `ci_passed`, or `merged`.
- **Gate W decision (re-verified before branch creation):** **CLOSED.**
  `docs/integrations/wallet/gate-w-evidence.md`, `docs/integrations/wallet/` and
  `docs/proof/phase-20d-w.md` are all absent; `docs/integrations/` contains only `refer-earn/`.
  20D-W stays blocked → 21R-B and 21N stay blocked → **21S is the next executable phase**
  (Plan §80.1 `16C + 15A(consent) → 21S`).
- **Entry criteria:** Phase 15A `verified_complete` (PR #24, merge `81a5866`, CI `28338582235`)
  with the `client_consents` substrate live; Phase 16C `verified_complete` (PR #28, squash
  `ffe37cc`) with `service_sessions` + `ServiceSessionStatus::Completed` live. No SMS provider
  credentials exist → the Plan's fallback applies: implement against a deterministic fake client
  and record a deferred-verification item (**REM-SMS-002**) that must close before Phase 25.
- **Source-of-truth conflicts resolved (full evidence in the proof):**
  1. `personnel.my_served_clients.view` carried `owning_phase: Phase 21N` in the matrix and
     `phase: Phase 15A` in the navigation YAML, while Plan §64/§80 place the served-clients view
     inside **21S** — activated here with its existing attributes verbatim.
  2. The Plan §20 entitlement gate had **no runtime**: `PlanContextResolver` was bound to
     `UnboundPlanContextResolver` (always `null`), so the `sms` entitlement could never resolve.
     Minimal substrate added (concrete resolver + gate) — see the Phase 21S entitlement section.
  3. No SMS provider credentials/config exist anywhere → fake-by-default binding, fail-closed HTTP
     client, **REM-SMS-002** opened.
- **Scope delivered:** 4 additive tables + 5 triggers; the `app/Domain/Messaging/Sms` bounded
  context; own-scope served-client read (masked, name-only search, rate-limited by the shared `api`
  limiter); advisory preview + authoritative confirm running the SAME evaluator; transactional
  campaign + recipient snapshots; queue-after-commit delivery through a provider ADAPTER (fake
  bound unconditionally in `testing`); transient-only retry with capped backoff and high-severity
  dead-lettering; `sms_billing_entries` liability queue with a structural no-double-billing index;
  10 typed audit events; 2 canonical permissions activated (**128 → 130 active, 40 → 38 planned**);
  8 new routes (**OpenAPI 235 → 242 paths / 280 → 288 operations**); the `personnel.sms` screen.
- **Contact protection (ADR-010, the phase-defining invariant):** the ONLY full number lives in
  `personnel_sms_recipients.phone_encrypted` — encrypted, `$hidden`, read by exactly one class
  immediately before the provider call, and **NULL** for any recipient excluded at composition. No
  export/download/print/copy route, control or permission exists anywhere; guessed export-shaped
  paths 404 **and** audit at HIGH severity; no phone reaches a response, log, audit context, URL,
  OpenAPI example, browser storage or a Playwright artefact.
- **Minimal Plan §20 entitlement runtime added** (`SubscriptionPlanContextResolver` +
  `EnsureEntitlement`), because 20A shipped the interface unbound and 20B never replaced it, so the
  `sms` entitlement could never have resolved. Blast radius is exactly the four Phase 21S
  composition/commitment routes, asserted by test.
- **Work skipped, with the owning phase for each item:**

  | Skipped | Owner |
  |---|---|
  | Live SMS provider credentials, pinned result-code map, pinned tariff, authenticated delivery-receipt endpoint | **REM-SMS-002** — closes before Phase 25 |
  | Scheduled retention purge of aged SMS delivery snapshots (Plan §74) | **21N** (§67 scheduler) |
  | Rolling a `billable` SMS charge into a subscription invoice line | the billing phase that owns SMS charge aggregation |
  | Wallet payment runtime; Integrations Health shared screen | **20D-W** — blocked until Gate W opens |
  | `subscription.*` / `activity.*` R&E events; qualification engine; inbound reconciliation; R&E gap reconciliation | **21R-B** — needs 20D-W |
  | Notifications, queue topology/Horizon, scheduled reports | **21N** — needs 20D-W |
  | Search (incl. indexing the served-client surface) | **22** |
  | Release-wide security / responsive / dark / accessibility / threat-model audit | **23** |
  | Performance optimization (incl. a max-size batch profile) | **24** |
  | Production readiness / deployment; `REM-RE-002` closure | **25** |
  | Referrer accounts; referral codes as system of record; campaigns; reward rules; reward calculation; reward ledger; referrer payouts; reward statements | **not Servana** — Citrus Refer & Earn (ADR-013) |
  | Payment provider credentials; STK/C2B/Daraja; raw provider callbacks | **not Servana** — Wallet by Citrus (ADR-012) |

## Phase 21R-A — Citrus Refer & Earn Referral Capture, Outbox, Signed Delivery (verified_complete)

Implements Plan §58A (referral capture, outbox, signed delivery), §58B.1 `merchant.*` rows only,
§58B.2 payload minimal-fact schema, §13.17 (`referral_snapshots`, `re_outbound_events`,
`re_event_deliveries`), §25.6 (snapshot + outbox machines), §17.1 (Servana→R&E machine identity),
§9 rules 22–24, §10.1 `app/Domain/Integrations/ReferEarn`, §12.1 item 5, §80 Phase 21R-A;
ADR-013, ADR-015. Servana is a **source product**: it captures the referral, emits signed facts, and
owns **none** of R&E's reward truth. Proof: `docs/proof/phase-21r-a.md`.

- **Lifecycle:** ✅ **`verified_complete`** — reconciled from `local_complete pending PR CI/review/merge`
  during **Phase 21S Increment 1**, against live `gh` + `git` evidence (not session memory):

  | Field | Live value |
  |---|---|
  | PR | #44 — "Phase 21R-A: Implement referral capture and R&E outbox" |
  | State | `MERGED`, base `main`, merged `2026-07-22T10:17:57Z` |
  | Base before 21R-A | `6047835b3a388fff5cc92a13370963635700f5e3` |
  | Initial implementation head | `a9ee4445d56be29217c9db146d585228bf3f27ed` |
  | Final patched head (CI stabilization) | `7b7cdb342ffa37df09ac91a030d8417746266710` |
  | **Merge commit** | **`b5a8733616a4603996e18695db31528299cdf8d7`** == `origin/main` |
  | Merge strategy | GitHub **merge commit**, **not squash** (`mergeCommit.oid` ≠ head SHA). Recorded truthfully; history not rewritten. |
  | Final CI run | `29909918754` — `event=pull_request`, `status=completed`, `conclusion=success`, `headSha=7b7cdb34…` |
  | Required jobs | Backend SUCCESS · Frontend SUCCESS · Docker SUCCESS · Security SUCCESS · E2E — Playwright SUCCESS |
  | `reviewDecision` | **blank** — solo-maintainer governance exception, **not** independent approval |
  | Governance evidence | PR comment posted after green CI: <https://github.com/ikrome002-design/servana/pull/44#issuecomment-5044610118> |
  | Branch cleanup | remote branch deleted by the merge (`git ls-remote` → 0 refs); stale local branch deleted with `git branch -d` (was `7b7cdb3`, reported merged into `main`) |

  Increments 1-7 all COMPLETE and green; one completion commit.
  Branch `phase-21r-a-referral-capture-outbox` was created off
  `6047835b3a388fff5cc92a13370963635700f5e3` (Phase 20H PR #43 squash merge); preflight verified
  branch `main`, `origin/main…HEAD` = `0 0`, clean tree, no staged files, `git fsck` clean, both
  Phase 20H branches absent, no pre-existing 21R-A branch.
- **Gate W decision (recorded before branch creation):** **CLOSED.** `docs/integrations/`,
  `docs/integrations/wallet/` and `docs/integrations/wallet/gate-w-evidence.md` are all absent — no
  Wallet Servana Collections Slice evidence, sandbox credentials, pinned Wallet OpenAPI hash, contract
  suite, STK/C2B transcript, signed-webhook transcript, reconciliation transcript, or explicit PASS
  (Plan §80.2.4 requires the evidence file). No new authoritative Wallet readiness evidence exists.
  **Action:** proceed with 21R-A; **no pivot to 20D-W**.
- **Entry criteria (Plan §80 Phase 21R-A):** 20B complete ✅ (PR #36); §1.3 plan-adoption PR merged ✅
  (PR #34); R&E sandbox service-account credentials **not received** — `docs/integrations/refer-earn/`
  did not exist. The Plan's own fallback applies verbatim: implement against `FakeReferEarnClient`
  + recorded contract fixtures and record a deferred-verification item that must close before Phase 25.
- **Increment 1 COMPLETE (verification, reconciliation, inventory):** PR #43 verified MERGED with five
  SUCCESS CI checks on the governance head; Phase 20H reconciled `in_progress → verified_complete`
  (roadmap row, Phase 20H section, CHANGELOG, traceability); Gate W recorded CLOSED; as-built
  registration path inspected and the insertion point proven before any wiring (Plan §81 rule 22);
  `docs/integrations/refer-earn/{credentials-receipt,contract-pins}.md` created recording that **no
  R&E credential, product code or signing algorithm was issued**; `REM-RE-002` raised as the
  Plan-authorised deferred-verification item. **Documentation drift fixed:** the data dictionary
  labelled `re_inbound_requests` as 21R-A; Plan §13.17/§12/§80 all place it in **21R-B** (root-cause
  block in the proof).
- **Increment 2 COMPLETE (schema, enums, models, state machines):** 3 additive migrations
  (`referral_snapshots`, `re_outbound_events`, `re_event_deliveries`); 6 enums; 3 models; 3 factories;
  `TransitionReferralSnapshot` as the sole status writer; 4 database triggers (capture-evidence +
  terminal-status guard; outbox append-only; outbox no-delete; delivery append-only); `TenantOwnership`
  EXEMPT classifications with rationales; manifest +3; data dictionary rewritten. Gates:
  `Phase21RASchemaTest` **25/513 assertions**, `ReferralSnapshotStateMachineTest` **10/69**,
  `MigrationManifestTest` **6/6**.
- **Increment 3 COMPLETE (registration capture):** `CaptureReferralSnapshot` wired into the existing
  `RegisterMerchant` transaction inside a **SAVEPOINT** so an R&E-side fault can never fail a merchant
  registration (Plan A-19); `ReferralCodeNormalizer` + `LandingMetadataAllowlist`; three optional
  request fields; `ValidateReferralCodeJob` dispatched **after** commit. Gates: `ReferralCaptureTest`
  **14 passed**; the as-built Phase 6 suite unchanged and green (`tests/Feature/Onboarding` **23 /
  2975 assertions**) — the non-breaking-extension proof Plan §75.1 requires.
- **Increment 4 COMPLETE (outbox, canonical payloads, signing, delivery):** `EnqueueProductEvent`
  (transaction guard, emission-scope gate, advisory-lock sequence allocation, canonical hash);
  `CanonicalJson`; `MerchantEventPayloadBuilder` (explicit per-type allowlist); five committed JSON
  Schemas with `additionalProperties: false`; `CitrusEventSigner` (exact §9 rule 22 canonical string,
  algorithm-aware, **fails closed** per ADR-015); `ReferEarnClientInterface` + `HttpReferEarnClient` +
  `FakeReferEarnClient`; `DeliverProductEvent` + `DeliverReOutboxJob` (claim under lock, per-merchant
  ordering, §58A.2 response routing, backoff/dead-letter, redacted attempt history);
  `DeliveryResponseRedactor`. Gates: `OutboxEmissionTest` **20**, `OutboxTransactionGuardTest` **1**,
  `OutboxDeliveryTest` **19**, `AttributionLifecycleTest` **11**,
  `ReferEarnPayloadDataMinimizationTest` **28**, `ReferralCodeNormalizerTest` **27**.
- **Increment 5 COMPLETE (merchant lifecycle hooks):** `merchant.setup_completed` from
  `CompleteFirstTimeSetup`; `merchant.status_changed` (reason **category** only) from
  `Suspend`/`Reactivate`/`DeactivateMerchant`; `merchant.identity_snapshot_changed` via
  `MerchantIdentityObserver` — chosen because inspection proved there is **no** merchant
  identity-update route as-built, so an observer covers every present and future writer without
  inventing a route this phase has no mandate to add. Gates:
  `ReferEarnMerchantLifecycleEventTest` **11**, `ReferEarnTenantIsolationTest` **6**,
  `ReferEarnScopePurityTest` **7/40**.
- **Increment 6 COMPLETE (frontend):** exactly one screen touched —
  `resources/spa/src/pages/auth/RegisterMerchant.vue` (§12.1 item 5): optional referral field, `?ref=`
  pre-fill, dismissible "Referral code applied" notice, advisory (never blocking) format hint, the
  value preserved through a server validation error, **no referrer identity rendered**, and an
  unreferred submission that omits the referral keys entirely so its body is byte-identical to the
  pre-21R-A contract. Gates: ESLint **0 errors / 138 warnings = the `origin/main` baseline**, vue-tsc
  clean, Vitest **481 → 490**, build PASS, `tests/e2e/phase-21r-a.spec.ts` **16 passed** (axe at
  360/768/1280 in light **and** dark, no-horizontal-overflow assertions, keyboard path).
- **Increment 7 COMPLETE + green (closure gates + docs + single commit + push):** Pint **PASS 1528**; Larastan L8 **0 errors**; **full backend serial 1844 passed / 7 skipped / 0 failed** (11005 assertions) and **`--parallel --processes=4 --recreate-databases` identical 1844/7/0** (20H baseline 1663/7); ESLint 0 errors / 138 warnings = the `origin/main` baseline; vue-tsc clean; **Vitest 490/96 files**; build PASS; **full Playwright 432 tests = 416 baseline + exactly the 16 new**; OpenAPI **235 paths / 280 operations unchanged**, byte-identical across two regeneration passes, `api:contract:check` OK, `servana:permission-types --check` up to date; `composer audit` clean; `npm audit --audit-level=high` **0 vulnerabilities**; gitleaks no leaks; both CI Docker targets (php dev, nginx prod) build. **Disposable PostgreSQL 16.14 proof:** `servana_p21ra_proof_*` — 114 migrations from zero, 93 tables, 3/3 Phase 21R-A tables, 11 CHECKs, 13 indexes, 3 RESTRICT FKs, 4 triggers, **forbidden-table scan empty**, database dropped, dev DB intact, 0 leftovers. **Two environment flakes recorded, not hidden:** a full Playwright run under concurrent load failed 4 pre-existing specs (all locator timeouts) and a Docker BuildKit session error — both re-ran clean in isolation (66/66; both images build) with no code change. **Defect found and fixed before commit:** `EnqueueProductEvent` wrote the outbox row but nothing dispatched `DeliverReOutboxJob`, so the outbox would never have delivered — now dispatched `afterCommit()`; the first test for it asserted against `Queue::fake()` semantics rather than the guarantee and was rewritten. Both carry root-cause blocks in the proof.
- **No new route, no new permission, no new policy.** `platform.integrations.refer_earn.manage`
  (matrix `owning_phase: Phase 21R-A`) deliberately stays **`planned`**: the capabilities it names —
  rule-version creation, dead-letter replay, inbound key-set changes — are all 21R-B / Integrations
  Health work, so activating it would grant authority over surfaces that do not exist. OpenAPI path
  and operation counts are therefore **unchanged at 235 / 280**; the only contract movement is three
  optional request fields on the existing public self-register endpoint plus four new `AuditEvent`
  values in the audit-read enums.
- **Deliberate deferral (recorded, not silent):** the shared **Integrations Health screen** (§12.1
  item 4), which Plan §80 lists under the Phase 21R-A frontend, is **deferred to Phase 20D-W**. Its
  permission `platform.integrations.health.view` carries `owning_phase: Phase 20D-W` in the
  authoritative matrix, and the screen's Wallet panels (webhook lag, inbox failures, breaker state)
  cannot be built while Gate W is closed — Plan §0 forbids stubbing or partially implementing a Wallet
  capability. Building only the R&E panel would activate a 20D-W-owned permission and ship a
  half-populated shared screen. The R&E outbox metrics it would show (depth, oldest undelivered age,
  last delivery error, dead-letter count) are all derivable from `re_outbound_events` when that screen
  lands.
- **Work skipped, with the owning phase for each item:**

  | Skipped | Owner |
  |---|---|
  | Wallet payment runtime; wallet merchant-account links; subscription payment attempts/payments/receipts/reversals; wallet webhook inbox; billing reconciliation exceptions; subscription invoice payment locks; merchant billing credits; STK; PayBill/Till; C2B; Daraja callbacks; provider credentials; provider reconciliation | **20D-W** — blocked until Gate W opens |
  | `subscription.*` and `activity.*` event emission | **21R-B** (needs 20D-W payment sources) |
  | Monthly qualification engine; `re_activity_rule_versions`; `re_qualification_periods`; `re_qualification_decisions` | **21R-B** |
  | Inbound R&E reconciliation surface; `re_inbound_requests`; `ReconcileReEventGapsJob` | **21R-B** |
  | Integrations Health screen (incl. its R&E panel) | **20D-W** (owns the shared screen + permission) |
  | Personnel bulk SMS | **21S** |
  | Notifications, queue topology/Horizon, scheduled reports | **21N** |
  | Search | **22** |
  | Release-wide security / responsive / dark / accessibility audit | **23** |
  | Performance optimization | **24** |
  | Production readiness / deployment; rotation runbooks + staging rotation drill | **25** |
  | Referrer accounts; referral codes as system of record; campaigns; reward rules; reward calculation; reward ledger; referrer payouts; reward statements | **not Servana** — Citrus Refer & Earn (ADR-013) |
  | Payment provider credentials; STK/C2B/Daraja; raw provider callbacks | **not Servana** — Wallet by Citrus (ADR-012) |

- **Closure (recorded in Phase 21S Increment 1):** the PR was opened, CI observed green
  (`29909918754`), governance evidence posted, PR #44 merged with the GitHub merge-commit strategy
  into `b5a8733616a4603996e18695db31528299cdf8d7`, and the remote branch deleted. 21R-A is now
  `verified_complete`. Phase 21R-B stays blocked behind 20D-W / Gate W; **21R-A does not unblock it
  on its own**. `REM-RE-002` (no R&E sandbox credentials / signing algorithm / product code — the
  outbox is fixture-verified only) remains **open** and must close before Phase 25.

## Phase 20H — Payout Runs and Earnings (verified_complete)

Implements Plan §62 (Payout Runs), §63 (Earnings Statements and Queries), §13.12 canonical DDL,
§25.4/§25.5 state machines, §65 (10F files), §80 roadmap; Correction 19.6–19.9; financial.
**Consumes** the 20G ledgers (`salary_ledger`, `commission_ledger`, `compensation_adjustments`)
into internal payout workflow + personnel earnings surfaces. **Servana moves no money.** Proof:
`docs/proof/phase-20h.md`.

- **Lifecycle:** ✅ `verified_complete` — reconciled from `in_progress` during Phase 21R-A Increment 1.
  **PR #43** MERGED into `main` (squash merge `6047835b3a388fff5cc92a13370963635700f5e3` == `origin/main`;
  implementation commit `309057c…`; test-only CI repair `16c368a…`; governance / final PR head
  `9824e46…`; merged `2026-07-22T04:27:01Z`); final CI run **29890786464** — Backend, Frontend, Docker,
  Security and E2E — Playwright all SUCCESS; `reviewDecision` blank under
  `docs/governance/solo-maintainer-review-exception-pr-43.md` (**not** independent approval); local +
  remote `phase-20h-payout-runs-earnings` deleted. **Increments 1–7 all COMPLETE + green** on the
  PR #42 remediated base `1879110` (Inc1 spec/reconciliation; Inc2 schema; Inc3 payout domain; Inc4
  earnings domain; Inc5 API/contracts; Inc6 frontend; Inc7 full local gates re-run green + disposable
  PG16.14 proof + scope-purity audit). **NOT** `ci_passed`/`merged`/`verified_complete` — there is no
  Phase 20H PR yet (its creation needs separate product-owner authorization).
- **Branch/base:** `phase-20h-payout-runs-earnings`, created from `origin/main` =
  `dcdbfb69f338f1cbdf13c0a0b507ef600cfe7f14` (= Phase 20G PR #41 squash merge). Verified at
  creation: branch HEAD = merge-base = `dcdbfb6…`; tree clean; divergence `0 0`; `git fsck` clean
  (one harmless dangling commit from the deleted 20G branch). Never on `main`.
- **H1 — prior-phase reconciliation:** Phase 20G PR #41 verified MERGED (merge `dcdbfb6` ==
  `origin/main`; impl commit `51ebb5d`; five CI checks Backend/Frontend/Docker/Security/E2E
  SUCCESS; `reviewDecision` blank = solo-maintainer exception, **not** independent approval; 20G
  branch deleted local+remote). Phase 20G reconciled `local_complete pending PR → verified_complete`
  in this file, the roadmap row, and CHANGELOG. Governance truth preserved, not rewritten.
- **H2 — Gate W / sequencing:** Gate W **CLOSED** (`docs/integrations/wallet/` absent) → Phase 20D-W
  blocked → **Phase 20H is the next executable phase** (depends only on merged 20G ledgers +
  Phase 8/15B staff + Phase 20A subscription threshold + Phase 10F files; none Wallet-gated).
- **H3 — schema/ownership:** New branch-owned tables `personnel_payout_runs`,
  `personnel_payout_items`, `earnings_queries` (Plan §13.12) + three **expand** FKs adding
  `payout_item_id → personnel_payout_items(id)` to the 20G ledgers (which already carry the nullable
  un-constrained column + a guard permitting only `status`+`payout_item_id` to transition). **D-H3-1:**
  add `currency` char(3) to runs/items (the §13.12 summary omits it, but the no-cross-currency
  invariant + single `gross_total_minor` require a single-currency run) — additive completion, not a
  Plan contradiction. **D-H3-2:** the shipped 20G ledger enums are **forward-only** (no `included_in_payout→pending`
  release), so a 20H run **claims** a ledger row by setting only `payout_item_id` at **submit/freeze**
  (status untouched), **releases** by clearing `payout_item_id` on reject/cancel, and advances status
  **forward only** `earned/pending→included_in_payout→paid` at mark-paid — **no 20G state-machine
  change**.
- **H4 — eligible liability:** commission `status='earned'`, salary `status='pending'`, adjustments
  `payout_item_id IS NULL`, all within run merchant/branch/currency/period and unlinked; snapshotted
  once (never recomputed from current plans/rules); negative reversal/clawback rows net honestly.
- **H5 — payout lifecycle:** `draft→submitted→finance_verified→approved→paid` with the high-value
  fork `finance_verified→pending_merchant_admin_approval→approved`; reject/cancel pre-paid; paid is
  terminal (corrections via a new adjustment run). Invalid transition → `422`.
- **H6 — high-value threshold (RESOLVED):** existing `merchant_subscriptions.high_value_payout_threshold_minor`
  (Phase 20A). Snapshotted at creation to `high_value_threshold_snapshot_minor`; high-value when
  `gross_total_minor > snapshot`; **null snapshot ⇒ high-value gate inactive ⇒ ordinary Finance
  approval** (D-H6-1). Nothing hardcoded; no new substrate.
- **H7 — freeze:** items snapshotted at draft creation (regenerable while draft); frozen on submit
  (only status mirror transitions post-draft); immutability trigger.
- **H8 — mark-paid:** approved status + external reference + paid date + Finance actor + fresh
  step-up + Idempotency-Key + `run+items FOR UPDATE` + ledger `included_in_payout→paid` + statement
  availability + `payout_run.marked_paid` (critical). **No provider/Wallet call; no money movement.**
- **H9 — adjustment runs:** already-paid reversals are negative `compensation_adjustments`; net-
  negative staff/currency carried honestly as negative `gross` (no destructive rewrite, no silent
  absorption) — **D-H9-1**, resolvable without a product-owner decision.
- **H10–H12 — earnings:** personnel own-scope overview (tabs gated by `CompensationModel`), payout
  history, statements via 10F (`earnings_statement` purpose already exists — no schema change),
  earnings queries (subject commission/salary/payout_item; resolve via adjustment only; **respond =
  Finance** per matrix, D-H12-1).
- **H13 — permissions:** 16 planned Phase 20H keys (`payout_run.*`, `earnings_query.respond`,
  `merchant.compensation_summary.view`, `merchant.payout.approve_high_value`, `personnel.my_*`) →
  activate in Inc 5 (active **113→129**, planned **56→40**; no legacy retirement).
- **H14–H18:** route families + classifications, audit events (create/submit/verify/approve/reject/
  mark-paid + earnings-query + statement), Finance/HR/MA/Personnel screens, reports-catalogue defs
  only (delivery 21N), and the full financial-invariant test matrix — all specified in
  `docs/proof/phase-20h.md`.
- **Blocking conflicts:** none. Every H-gate resolved from the Plan + live matrix + live repository;
  no product-owner decision required.
- **Skipped work / owners:** Wallet/provider/settlement → 20D-W (Gate W CLOSED); scheduled report
  delivery + notification center → 21N; personnel bulk SMS → 21S; search → 22; release-wide audits →
  23; performance → 24; deployment/alerting/runbooks → 25; actual external money movement → provider
  phase (never 20H). The Phase-15A HR Service Eligibility `/services` observation remains a separate
  follow-up unless a full gate proves it blocks 20H.
- **Increment 2 COMPLETE + green:** 6 forward-only migrations (3 tables `personnel_payout_runs`/
  `personnel_payout_items`/`earnings_queries` + 3 expand FKs `payout_item_id → personnel_payout_items`
  on the 20G ledgers); 6 enums (`PayoutRunStatus`/`PayoutItemStatus`/`EarningsQuerySubjectType`/
  `EarningsQueryType`/`EarningsQueryAssignedRole`/`EarningsQueryStatus`); 3 models + 3 factories;
  2 state machines (`PayoutRunStateMachine`/`EarningsQueryStateMachine`); payout-item freeze trigger
  (DELETE only while draft; snapshot columns immutable); `TenantOwnership` (BRANCH_OWNED + MODELS +
  COMPOSITE_CONSISTENCY); manifest (6 entries); data-dictionary section + 2 state-machine docs.
  **Cross-phase reconciliations (test-only, expected):** the shipped 20G ledger `payout_item_id` FK is
  now real, so `Phase20GSchemaTest` (real payout item), `Phase20GTenantIsolationTest` (FK-now-exists +
  Wallet-only forbidden list), `Phase20FSchemaTest`/`CompensationPlanActionTest`/`CompensationPlanApiTest`
  (table-absent → zero-rows) were reconciled; no product change outside 20H. Gates: `Phase20HSchemaTest`
  13 + `Phase20HEnumParityTest` 7 + `Phase20HStateMachineTest` 5; reconciled+coverage batch 127; full
  reconciled batch 178; Pint clean; **Larastan L8 0 errors (1099 files)**. No 20G state-machine change
  (forward-only enums honoured by the claim-via-`payout_item_id` design).
- **Increment 3 COMPLETE + green:** payout domain. 14 Phase 20H `AuditEvent` cases (payout lifecycle
  + earnings; domain+severity coverage green). Services `SelectEligiblePayoutLiabilities` (H4
  `entry_type`-aware, reversal-excluding, currency/branch/period-bounded, FOR-UPDATE) +
  `PayoutRunItemSnapshotter`. 10 actions: `CreatePayoutRunDraft` (threshold snapshot from
  `merchant_subscriptions`), `UpdatePayoutRunDraft`, `SubmitPayoutRun` (lock + re-snapshot + claim +
  freeze), `VerifyPayoutRun` (+ high-value auto-route), `ApprovePayoutRunStandard` (finance_verified-
  only), `ApprovePayoutRunHighValue` (pending_merchant_admin_approval-only), `RejectPayoutRun`
  (release), `CancelPayoutRunDraft`, `MarkPayoutRunPaid` (forward ledger settle, encrypted ref, **no
  money movement**). Reversal netting verified against `ReverseCommissionEntry`/`ReverseSalaryAccrual`.
  Gates: `PayoutRunLifecycleTest` **11 passed**; `AuditSeverityCoverageTest` 5; Pint clean; **Larastan
  L8 0 errors (1110 files)**. Each action: single txn + FOR UPDATE + state-machine guard + audit.
- **Increment 4 COMPLETE + green:** earnings backend domain. Migration `…000007` adds nullable
  `personnel_payout_items.earnings_statement_file_id` (outside the freeze guard). `PersonnelEarningsReadModel`
  (own-scope, explicit `StaffProfile`; per-currency overview from source-ledger facts only — no
  double-count; tabs by `CompensationModel` with conflict fail-closed + historical fallback; payout
  history; compensation terms). `EarningsStatementDocumentRenderer` + `GenerateEarningsStatement`
  (on-demand, idempotent, immutable, paid-only; 10F `GeneratedFileWriter` extended with optional
  `ownerUserId` → own-scope download authority; `earnings_statement_file_id` set-once). `CreateEarningsQuery`
  (own-scope subject validation → 404 no-leak; `query_type→assigned_role`) + `RespondToEarningsQuery`
  (resolve/reject via state machine; monetary correction ONLY via `RecordCompensationAdjustment` +
  `resolved_adjustment_id`; replay-safe). Shared payout/earnings test helpers moved to `tests/Pest.php`.
  Gates: `PersonnelEarningsReadModelTest` 10 + `EarningsStatementTest` 4 + `EarningsQueryTest` 6;
  affected serial 78 + NoDirectProvider/audit/receipts 16 + 20F/file/receipt 184; Pint clean; **Larastan
  L8 0 errors**. D-H11 (on-demand statement), D-H11-link, D-H12-subject/respond recorded in proof.
- **Increment 5 COMPLETE + green (no commit):** API surface + generated contracts. **16 canonical keys
  activated** (`payout_run.*`, `earnings_query.respond`, `merchant.compensation_summary.view`,
  `merchant.payout.approve_high_value`, `personnel.my_{compensation,earnings,statements,payouts}` +
  `my_earnings_query.create`) across YAML/PHP registry/DB/`permissions.ts`/`phase8-matrix.txt` —
  **active 112 → 128; planned 56 → 40; no legacy retirement.** Two new `StepUpAction` cases
  (`PayoutVerify`, `PayoutHighValueApprove`); the four payout step-up actions left harness-only
  `businessActions()`. Policies `PersonnelPayoutRunPolicy` + `EarningsQueryPolicy` (registered). 16 Form
  Requests (all server-owned fields `prohibited`; `paid_date before_or_equal:today`; correction only on
  resolve). Masked Resources `PersonnelPayoutRun`/`PersonnelPayoutItem`/`EarningsQuery`/`EarningsStatement`
  (presence-only external ref; source COUNTS not ledger ids; integer minor money). 6 thin controllers +
  `MerchantCompensationSummaryReadModel`. **25 routes**: HR draft (`branch_mutation`), Finance
  verify/approve/reject/mark-paid + query-respond (`financial_mutation`; MFA group + fresh step-up on
  verify/approve/mark-paid/high-value; Idempotency-Key on every financial route — per the live
  `RouteClass` contract, superseding the proof-H14 idempotency dashes), MA summary + high-value approval,
  Personnel own-scope earnings/compensation/payouts/statement (`tenant_mutation` + `EnsureBillingMutable`;
  download reuses the 10F `files.*` endpoints, own-scope by `owner_user_id`) + query create/read. Audit
  route→event map extended (12 mutation routes). OpenAPI **235 paths / 280 operations** (23 new); `api.ts`
  + `permissions.ts` regenerated; `api:contract:check` + `permission-types --check` green; **second
  generation deterministic (hash-verified)**. Gates: `Phase20HPayoutRunApiTest` 14 +
  `Phase20HEarningsApiTest` 11 + `Phase20HPermissionActivationTest` 6; **`--group=auth` 76**,
  **`--group=compensation` 478**, **`--group=security --group=audit` 205**, file/receipt/manifest/tenancy
  30; `OpenApiContractTest` byte-current. **Pint clean; Larastan L8 0 errors (1145 files); git diff
  --check clean.** No frontend/Playwright/Wallet/money-movement/notification/scheduled-report. Lifecycle
  stays **in_progress**.
- **Increment 6 COMPLETE + green (frontend; no commit):** 5 screens (`hr/PayoutRuns`, `finance/PayoutRuns`,
  `merchant/CompensationSummary`, `personnel/Earnings`, `finance/EarningsQueries`) + 4 Pinia stores
  (`payoutRunStore`, `merchantCompensationSummaryStore`, `personnelEarningsStore`, `earningsQueryStore`)
  typed from generated `api.ts`; routes wired (hr/finance/merchant/personnel); 4 planned nav placeholders
  flipped live + `finance.earnings-queries` added; 4 inventory entries flipped implemented + 1 added; §27.1
  specs regenerated (`generate-screen-specs.mjs`, 114 specs); `inventory.yaml`/`role-navigation.yaml`
  snapshots regenerated deterministically. Financial UX: Idempotency-Key mint/reuse/remint by
  action+payload; step-up-required safe states → `auth.mfa.challenge`; float-free `majorToMinor`; HR never
  verifies/approves/marks-paid; Finance mark-paid states it records an EXTERNAL payment and moves no money
  (raw external ref never displayed after save); MA never marks-paid; Personnel has no staff selector +
  downloads via the authorised signed file link; responder offers only an additive correction (no ledger
  editor); no Wallet/provider wording. **Contract-truth fix DEF-20H-001** (authorized): `paid_at`/
  `responded_at` made genuinely-nullable in api.ts via the ternary (JSON byte-identical; OpenAPI unchanged
  235/280). Gates: **Vitest 435→481** (+46; 4 store 24 + 5 component 22), inventory/nav 20; **ESLint 0
  errors, vue-tsc clean, build PASS**; **Playwright 397→416** (+19; responsive 360/768/1280, 200% zoom,
  keyboard+Escape, axe serious/critical 0 light+dark page+dialog; role denial); backend contract reruns
  76 passed (OpenApiContract byte-current, Phase20H API, route-security, idempotency, audit, permission
  parity, NoDirectProvider, file download); **Pint clean; Larastan L8 0 errors**. No frontend Wallet/
  provider/money-movement/notification/scheduled-report. Lifecycle stays **in_progress**.
- **Increment 7 gates RUN + green; completion HELD (no commit):** composer validate; Pint (1474);
  Larastan L8 0; **backend serial 1663/7skip/0fail**; **parallel 1663/7/0**; disposable **PG16.14** proof
  (90 tables/111 migrations; all 20H tables+triggers+FKs; no forbidden provider/wallet/notification
  tables; dropped; dev DB intact); contract determinism (openapi/api.ts/permissions.ts byte-stable;
  checks green 235/280); ESLint 0; vue-tsc clean; **Vitest 481**; build PASS; **Playwright 416** (axe 0);
  **gitleaks clean** (one proof false-positive fixed); Docker dev+prod app+prod nginx built. **BLOCKER
  (product-owner Option 1 = remediate first):** `npm audit --audit-level=high` fails on **inherited**
  advisories — `axios` HIGH (GHSA-gcfj-64vw-6mp9, prod, fixed ≥1.18.0) + transitive `brace-expansion`/
  `js-yaml`; `composer audit` guzzle medium-only. `package-lock.json`+`composer.lock` **byte-identical to
  origin/main** (Phase 20H changed no deps; origin/main fails the same gate). Per CLAUDE.md §7 the prod
  axios HIGH blocks the commit; the fix broadens beyond 20H, so it is isolated to a separate
  `security/dependency-audit-high-remediation` branch off `origin/main`. **Phase 20H NOT committed/pushed;
  dirty tree preserved; lifecycle in_progress.**
- **Increment 7 (resumed after PR #42) — dependency remediation merged; branch refreshed; closure gates
  re-run:** the inherited-advisory blocker is resolved. **PR #42** ("Security: Remediate inherited
  dependency audit advisories") **MERGED** into `main` (squash merge
  `1879110de6cb1d73ef82403dd7007cca447f8c5c`; head-before-squash
  `caa7161bece583e009dfe2bfca762dcfe3261689`; final CI run **29838903181** all checks SUCCESS —
  Backend/Frontend/Docker/Security/E2E; `reviewDecision` blank under
  `docs/governance/solo-maintainer-review-exception-pr-42.md`; changed only `composer.lock`,
  `package-lock.json`, `package.json`, and that governance file). The remediation branch was deleted
  locally and remotely. The Phase 20H branch was **refreshed from `dcdbfb6…` to `1879110…`** with
  `git reset --keep origin/main` (no code churn; all 139 Phase 20H dirty entries preserved; PR #42 files
  identical to base, absent from the Phase 20H diff). Post-refresh dependency gates now **GREEN**:
  `npm audit --audit-level=high` → **0 vulnerabilities**; `composer audit --locked` → **no security
  vulnerability advisories found**. All Increment 7 closure gates **re-ran GREEN on the refreshed base**
  (in-container Composer deps resynced to the refreshed lock first — guzzle 7.12.1→7.15.1): composer
  validate valid; Pint 1474 clean; Larastan L8 0 errors; **backend serial 1663/7skip/0fail** (9898
  assertions) + **parallel 1663/7/0** (4 procs); disposable **PG16.14** proof (111 migrations/90 tables;
  all 20H tables/triggers/FKs; forbidden-table scan empty; dev DB intact); contract determinism
  (openapi/api.ts/permissions.ts byte-stable; **235 paths/280 operations**; permission-types --check +
  api:contract:check green); ESLint 0; vue-tsc clean; **Vitest 481**; build PASS; **Playwright 416/0**
  (axe serious/critical 0); gitleaks clean; Docker dev+prod app+prod nginx built. Two CPU-contention
  flakes (Vitest forks-pool worker-start; one Phase-18A `payment.spec.ts` page-load timeout) were
  reproduced-clean on isolated re-run (Vitest 481; payment.spec 10/10; full Playwright 416/0) — no code
  change. Scope-purity audit clean (139 entries; 0 overlap with PR #42; 0 forbidden paths/runtime).
  Lifecycle remains **in_progress**; the single Phase 20H completion commit + branch push follow; no
  Phase 20H PR exists yet.
- **Closure (recorded in Phase 21R-A Increment 1):** the single Phase 20H completion commit
  `309057c…` was created and pushed; PR #43 was opened and its initial Backend CI run failed on **two
  stale hand-maintained permission expectations only** — `PermissionMatrixTest::expectedMatrix()` was
  missing the 16 permission grants Phase 20H activated, and `PermissionDatabaseProjectionTest:38` used
  `payout_run.mark_paid` as its "still planned" fixture after 20H made that key active. The repair
  commit `16c368a96dbd3d53a5bb7fda8a3b39e55ac46b92` ("test: update permission expectations for phase
  20h") changed **only** those two test files: it added the 16 grants and swapped the projection
  fixture to the still-planned `personnel.my_sms.send` (Phase 21S). **No implementation permission
  truth, policy, registry, matrix YAML or generated artifact was changed**, and no test was weakened
  or skipped. Governance head `9824e46…`, final CI run `29890786464` all green, squash-merged as
  `6047835…`.

## Phase 20G — Salary Accrual and Commission Processing (verified_complete)

Implements Plan §60 (Salary Processing), §61 (Commission Processing), §13.12 canonical DDL,
§80 roadmap; Correction 19; financial. **Creates the earned/accrued financial facts** 20F
configured. Proof: `docs/proof/phase-20g.md`.

- **Lifecycle:** ✅ `verified_complete` — reconciled from `local_complete pending PR` during Phase
  20H Increment 1. **PR #41** MERGED into `main` (squash merge `dcdbfb69f338f1cbdf13c0a0b507ef600cfe7f14`
  == `origin/main`; implementation commit `51ebb5d…`; merged `2026-07-20T11:54:59Z`); five required
  CI checks (Backend/Frontend/Docker/Security/E2E — Playwright) all SUCCESS; `reviewDecision` blank
  under the solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-41.md`)
  — **not** independent reviewer approval; local + remote 20G branches deleted.
- **Branch/base:** `phase-20g-salary-commission-ledgers`, created from `origin/main` =
  `57dce1031ce10c37977540a0e63b1491d444b877` (= the PR #40 merge commit). Verified at creation:
  branch HEAD = merge-base = `57dce10…`; tree clean; `git fsck` clean. Never on `main`.
- **Prior-phase reconciliation (this branch):** PR #39 (20F) `verified_complete`; PR #40
  (post-20F hardening) `verified_complete` — reconciled across `docs/PROGRESS.md`,
  `docs/proof/phase-20f.md`, `docs/proof/post-20f-deferred-hardening.md`, CHANGELOG,
  traceability. Truthful blank `reviewDecision` preserved (solo-maintainer exception).
- **Gate W:** **CLOSED** — `docs/integrations/wallet/` absent (no collections-slice evidence,
  credentials, pinned OpenAPI hash, contract suite, STK/C2B/webhook/reconciliation transcripts,
  or PASS). Phase 20D-W stays blocked. **No Wallet/provider runtime in Phase 20G.**
- **Why 20G is executable:** 20F `verified_complete` supplies `commission_rules` +
  `personnel_compensation_plans` config; 18B `verified_complete` supplies
  `payment_validation_events`, the durable `commission_handoff_events` outbox, and
  refund/void seams. Neither depends on Wallet. `20F + 18B(validated payments) → 20G`.
- **G1–G12 decisions** (full table: `docs/proof/phase-20g.md`):
  - **G2 schema:** §13.12 canonical DDL. `payout_item_id` created **nullable, no FK** now
    (20H `personnel_payout_items` target doesn't exist yet → 20H expand migration adds the FK;
    ADR-004). Tables: `commission_ledger`, `salary_ledger`, `compensation_adjustments`,
    `commission_rule_services` (selected-services substrate). All BRANCH_OWNED.
  - **G3 earning boundary:** commission earned **only** at Finance validation, via the
    pre-built durable idempotent `commission_handoff_events` outbox (written atomically inside
    `ValidatePaymentRecordingGroup` / `FinalizeRefund`). A 20G consumer creates
    `commission_ledger` earned/reversal rows idempotently and marks `consumed_at`. This is the
    sanctioned atomic-outbox per G3 — commission-creation failure does not roll back validation.
  - **G4 basis vocabulary:** shipped 20F enum `CommissionCalculationBasis`
    (`service_price, invoice_item_total, paid_amount, net_after_discount`) is repository-
    authoritative (merged, immutable DB CHECK; plans configure against it). Plan §13.12 DDL
    text (`service_item_net/gross_amount, validated_paid_allocation`) predates the 20F F-gate
    refinement; recorded as noted divergence resolved by hierarchy L3 — **not a blocker**.
  - **G5 fixed cap:** "fixed minor capped where required" = capped at the item's eligible
    validated allocation (grounded in the §13.12 invariant "sum of allocations ≤ eligible
    validated allocation"); no cap surface invented.
  - **G6 idempotency:** earned unique `(payment_validation_event_id, invoice_item_id,
    staff_profile_id, entry_type)`; salary unique `(compensation_plan_id, staff_profile_id,
    pay_period_segment, entry_type)` — DB-enforced.
  - **G7 reversals:** exact-negative append-only row referencing the original; already-paid →
    negative `compensation_adjustment` (no payout run in 20G).
  - **G8 salary proration (product-owner decision):** **Actual/Actual calendar-day** in
    Africa/Nairobi. Monthly denominator = actual days in the Nairobi month (28–31); weekly =
    ISO Mon–Mon, denominator 7. Half-open plan windows `[effective_from, effective_to)`. Build
    all payable segments; exact rational per segment (no float); round the **period total**
    once via ADR-005 round-half-up; floor each segment; allocate residual by largest remainder;
    tie-break ascending segment start → plan ULID → ledger ULID/segment key. Termination date =
    final payable day (exclusive boundary `+1`). `continue` accrues during suspension;
    prospective `pause` first non-payable at its effective date; resumption first payable.
  - **G9 attendance:** **no attendance/shift/timesheet/roster substrate exists** anywhere.
    monthly/weekly accrue; **daily/hourly/per_shift fail closed** (typed guard; no inferred
    hours, per §60). Owner of an approved attendance/shift source = **unbuilt future HR phase**
    (not 20G, not 20H) — recorded as a carried-forward gap.
  - **G10 suspension:** `suspension_salary_policy` already on the plan (default `continue`,
    CHECK `pause/continue`). Prospective override = supersede plan to a new effective-dated
    version with `pause` (rides the existing immutable effective-dated supersession; HR
    proposes + existing approval flow). No new substrate.
  - **§9.1 selected_services (product-owner decision):** build a normalized
    `commission_rule_services` pivot in 20G (not JSON, not disabled). Membership immutable once
    the rule leaves draft; ≥1 membership required before a selected-services rule leaves draft;
    live-data preflight **stops** on any non-draft selected-services rule with zero memberships;
    Finance validation earns only for items whose `service_id` is in the resolved rule's
    immutable membership set (else fail closed; never fall back to all_services). Recorded as an
    inherited 20F integration seam **closed by 20G**.
  - **G11 permissions:** activate only `compensation.liability.view` +
    `compensation.adjustment.create` (Finance default, MFA; adjustment.create fresh step-up +
    high-severity audit). Commission-rule selected-services config reuses existing 20F
    compensation permissions (no `commission.rule.*` invented).
  - **G12 scheduler/locks/audit:** Africa/Nairobi (`CompensationBusinessDate`); existing
    `PeriodLockService`/`FinancialPeriodGuard`; existing append-only hash-chained audit.
- **Increment status:** **Increment 1 (reconciliation + specification) COMPLETE.** **Increment 2
  (schema/enums/models/tenancy) COMPLETE + verified** — 4 forward-only migrations applied on PG16
  (`commission_rule_services`, `commission_ledger`, `salary_ledger`, `compensation_adjustments` +
  additive `invoice_items_id_merchant_id_unique`), 6 enums, 4 models, 4 factories, `TenantOwnership`
  registration, manifest, data-dictionary, 2 ledger state-machine specs; green `Phase20GEnumParityTest`
  (6) + `Phase20GSchemaTest` (16) + manifest/tenancy coverage (18); §9.1 preflight found 0 existing
  `selected_services` rules (no remediation). **Increment 3 (domain calculations & state machines)
  COMPLETE** — salary: `SalaryProrationCalculator` (G8 crux) + `SalarySegmenter` + `AccrueSalaryForPayPeriod`
  + `compensation:accrue-salary` scheduler; commission: `CommissionEarningResolver` +
  `EarnCommissionForValidationEvent` + `CommissionHandoffConsumer` (atomic outbox) +
  `ReverseCommissionEntry`/`ReverseSalaryAccrual` + `RecordCompensationAdjustment` + 2 ledger state
  machines + 7 audit events. G10 `suspension_salary_policy` added by forward-only expand
  `2026_07_17_000005` (missing from shipped 20F). **Increment 3 green:** SalaryAccrual 10, SalaryProration 7,
  CommissionEarning 10, CommissionReversal 5, CompensationAdjustment 3, StateMachine 2, TenantIsolation 4.
  Increments 4–7 pending.
- **Work inherited & to close:** salary accrual + `salary_ledger`; commission earning at
  validation + `commission_ledger`; compensation adjustments; refund/void/payment-reversal
  commission reversals; Finance compensation-liability visibility + adjustment creation;
  `selected_services` membership substrate (§9.1).
- **Work skipped (owner):** payout runs/items, earnings queries/statements, mark-paid,
  payout approval, paid-state ledger linkage → **20H**; Wallet/provider runtime → **20D-W**
  (Gate W); notifications/scheduled reports → **21N**; attendance/shift source → **future HR
  phase**; §9.3 impact-preview endpoint & §9.2 general compensation-status scheduler stay with
  their owners (20F UX follow-up / 21N) — 20G runs only its own salary-accrual scheduler.
- **Exact next action:** Increment 4 — authoritative Finance-validation/refund/void/payment-reversal
  integration and atomicity proof (validation-earn rollback proof; wire the invoice-void +
  payment-reversal handoff seams that don't exist yet; partial-refund proportionality; period-lock
  behavior at the seams; replay/concurrency proof). Then Increments 5–7. **No commit/push yet — only
  at full local completion.**

Implements Plan §59 + §80 "Phase 20F — Compensation Plan Setup and Commission Rules"
(Correction 19; HR) and Scope §12.1–§12.9, §18.3. **HR/admin configuration only** — this
phase defines *how personnel will earn*; it creates none of the earned financial facts
owned by Phase 20G/20H. Proof: `docs/proof/phase-20f.md`.

> **CURRENT LIFECYCLE (reconciled on the Phase 20G branch): `verified_complete`.**
> Phase 20F merged via **PR #39** "Phase 20F: Implement compensation plan setup" (squash
> merge `f4bc664b7ba77476f9db01dcb0ec1a526dc20538`; `origin/main` == `f4bc664`; merged
> `2026-07-17T12:11:44Z`). The two deferred follow-ups were then discharged by the post-20F
> hardening branch, merged via **PR #40** (merge commit `57dce1031ce10c37977540a0e63b1491d444b877`,
> `verified_complete`; see the post-20F hardening row above and `docs/proof/post-20f-deferred-hardening.md`).
> **The increment-by-increment narrative below is retained as HISTORICAL context** — it was
> written while the branch was pre-commit `in_progress`; the "no commit/no push/no PR" and
> "increment N pending" statements in it describe that earlier checkpoint, **not** the current
> state. Do not read them as current claims.

- **Lifecycle (historical, at the time the detail below was written):** 🚧 `in_progress`. **NOT** `local_complete` / `ci_passed` / `merged` /
  `verified_complete`. No commit, no push, no PR had occurred for Phase 20F **at that checkpoint** (now superseded — see the CURRENT LIFECYCLE note above).
- **Branch:** `phase-20f-compensation-plan-commission-rules`, created from
  `origin/main` = `c0881993ae0c59536013c9b84e182e5000fa1e11` (= the Phase 20E PR #38 merge
  commit). Verified at creation: branch HEAD = merge-base = `c088199…`; working tree clean;
  `git fsck --full` clean. Never worked on `main`; never committed to `main`.
- **Phase 20E reconciliation (this increment):** PR #38 MERGED → `verified_complete` across
  `docs/PROGRESS.md`, `docs/CHANGELOG.md`, `docs/proof/phase-20e.md`,
  `docs/traceability/servana-requirements.csv`. Evidence in the Phase 20E section above.
  Truthful blank `reviewDecision` preserved.
- **Gate W status:** **CLOSED** — `docs/integrations/wallet/gate-w-evidence.md`,
  `docs/integrations/wallet/`, and `docs/integrations/` are **absent**. No Wallet Servana
  Collections Slice evidence, sandbox service-account credential proof, pinned Wallet OpenAPI
  hash, passing Wallet contract suite, sandbox STK transcript, sandbox C2B transcript, signed
  webhook transcript, reconciliation transcript, or explicit PASS status exists. Per the v4
  sequencing rule ("if Gate W is not open when 20C exits, continue to 20E/20F and return to
  20D-W when Gate W opens"), **20D-W stays blocked and 20F proceeds**. No pivot to 20D-W; no
  Wallet runtime is introduced by Phase 20F.

### Why Phase 20F is the next executable phase

Phase 20E is merged (PR #38). Gate W is closed, so 20D-W remains blocked. Phase 20F depends
only on substrate already merged into `main`: the HR/staff-profile substrate (Phases 8/15B) and
the Phase 20A platform preferred-personnel-fee substrate (`preferred_personnel_fee_rules`).
It is therefore independently eligible under the §80 dependency graph.

### Specification gates F1–F10 (resolved before any migration)

Full decision table with authoritative citations: `docs/proof/phase-20f.md`.

| Gate | Decision | Authority |
|---|---|---|
| F1 | `compensation_model` ∈ `commission_only` / `salary_plus_commission` / `salary_only`; kept strictly separate from `staff_profiles.employment_type` (no overload) | Plan §59; Scope §12.2 |
| F2 | Branch-owned (`merchant_id` + `branch_id` + `staff_profile_id`); one active plan **per personnel per branch** | Plan §59; Scope §12.9; Scope §18.3 |
| F3 | `effective_from` (not null) / `effective_to` (nullable = ongoing); half-open `[from, to)`; gist EXCLUDE over (`staff_profile_id`, `branch_id`, daterange) partial on `active`/`scheduled` | Plan §59; `preferred_personnel_fee_rules` precedent |
| F4 | Integer minor units only; `percentage_basis_points` XOR `fixed_amount_minor`+`currency`; bp bound 0–10000; no ledgers | Plan §59; ADR-005; Scope §12.7 |
| F5 | `commission_rules` is a **sibling** table referenced by `personnel_compensation_plans.commission_rule_id` (nullable); configuration only | Scope §18.3 (decisive); Scope §12.7 Step 3A |
| F6 | `applies_to_preferred_personnel_fee` boolean (default `false`) = **basis-inclusion** flag consumed by Phase 20G | Plan §59; Scope §969 |
| F7 | Active/effective monetary terms immutable; supersede-not-edit + history row + audit; prior rules **ended, not deleted** | Plan §59; Scope §12.7 Step 3C |
| F8 | Backdated ⇔ `effective_from` < current `Africa/Nairobi` business date; requires submit → approve, mandatory reason, impact preview, maker/checker, fresh step-up, **critical** audit | Plan §59; permission matrix |
| F9 | Activate 8 `compensation.*` keys (HR, branch); retire legacy `commissions.manage` → `compensation.plan.update_draft` and `commissions.view` → `compensation.history.view` | `docs/auth/permission-matrix.yaml`; Plan §10.2 |
| F10 | Exactly one Phase 20F frontend surface: **HR Compensation** (`hr.compensation`, permission `compensation.plan.view`) | `docs/frontend/screens/inventory.yaml`; `role-navigation.yaml`; Scope §12.6 |

**F4 residual (non-blocking):** Scope §12.7 line 2436 requires the commission percentage not to
exceed "the configured merchant/platform maximum", but no such configuration exists anywhere in
the repository, the Plan, or elsewhere in the Scope, and Plan §59 (higher authority) does not
require one. Phase 20F enforces the structural bound `percentage_basis_points BETWEEN 0 AND 10000`
(0–100%) at the DB CHECK + Form Request, following the merged `preferred_personnel_fee_rules`
precedent, rather than inventing a new settings surface (which would be Phase 20A scope creep).
Recorded as a residual risk, not a blocker.

**Inventory/navigation correction (F10):** `docs/frontend/navigation/role-navigation.yaml` tagged
`merchant.compensation-summary` as `phase: Phase 20F`, but its permission
`merchant.compensation_summary.view` is `owning_phase: Phase 20H` in the plan-parity-tested
permission matrix, and Plan §80/§63 place the Merchant-Administrator compensation summary in
Phase 20H. The matrix + Plan win over the navigation tag. Retagged to **Phase 20H**; the screen is
**not** built in Phase 20F. Same class of inventory mistag already recorded in Phase 20A.

### Work inherited into Phase 20F

- compensation-plan setup and configuration
- commission-rule setup (sibling configuration referenced by the plan)
- preferred-personnel-fee applicability configuration (`applies_to_preferred_personnel_fee`)
- effective dating + overlap exclusion
- configuration immutability (supersede-not-edit) + compensation change history
- backdated-change approval (maker/checker + fresh step-up + critical audit)
- HR-authorized setup surface + role-scoped read/update authorization
- Phase 20F permission activation + legacy `commissions.*` retirement

### Work skipped and owner phases

| Skipped work | Owner phase |
|---|---|
| Wallet payment / settlement / collections | **20D-W** (after Gate W opens) |
| salary accrual + `salary_ledger` | **20G** |
| commission earning at Finance validation + `commission_ledger` | **20G** |
| compensation adjustments (`compensation_adjustments`) | **20G** |
| refund/void commission reversal ledger | **20G** |
| payout runs + payout items | **20H** |
| earnings statements + earnings queries + mark-paid | **20H** |
| Merchant Administrator compensation summary (`merchant.compensation_summary.view`) | **20H** |
| Refer & Earn runtime | **21R-A / 21R-B** |
| notifications + scheduled reports | **21N** |
| personnel bulk SMS | **21S** |
| search indexing | **22** |
| release hardening | **23** |
| performance optimization | **24** |
| deployment / centralized alert transport / runbooks | **25** |

### Increments

- **Increment 1 (specification + reconciliation) — COMPLETE:** Phase 20E → `verified_complete`;
  Gate W CLOSED recorded; F1–F10 decision table (`docs/proof/phase-20f.md`); data-dictionary
  entries for the three Phase 20F tables; `personnel-compensation-plan` state-machine spec;
  migration **plan** recorded in the proof (manifest entries are registered in Increment 2 with the
  migration files, because `MigrationManifestTest` forbids manifest entries without on-disk files —
  the Phase 20E precedent); traceability row `SRV-COMPENSATION-001`; inventory/navigation
  correction; permission activation/retirement plan; test plan. **No migration in this increment.**
- **Increment 2 (migrations/enums/models/factories/guards):** pending.
- **Increment 3 (domain actions, resolvers, state machines):** pending.
- **Increment 4 (permissions, API, audit, OpenAPI, TypeScript):** pending.
- **Increment 5 (HR Compensation frontend + browser coverage):** pending.
- **Increment 6 (full local gates, single completion commit + push):** pending.

### Pending work

- Phase 20F Increments 5–6 (HR Compensation frontend + Vitest/Playwright; completion + PR).

### Phase 20F Increment 2 (schema) — COMPLETE, green

Three forward-only migrations in FK order (`commission_rules` → `personnel_compensation_plans` →
`compensation_plan_history`), 7 enums, 3 models, 3 factories, `TenantOwnership` branch-owned
registration. PostgreSQL guards: F1 model-shape, F4 value-shape/basis-points/salary, F8
maker-checker + approval-status (backdating fails closed), composite FKs (ADR-002), the F3 partial
btree_gist EXCLUDE (one active plan per personnel per branch; adjacent windows legal), 2
supersede-aware immutability triggers, 2 append-only history triggers. Gates: schema 73, enum parity
15, manifest 9, tenancy 23, **full suite 1269 passed / 7 skipped / 0 failed**, Pint 1285, Larastan L8
clean, disposable PG16 proof green (99 migrations, 3/3 tables, 0 forbidden ledger/payout tables,
0/0/0 rows, dropped).

### Phase 20F Increment 3 (domain) — COMPLETE, green

State machines (exactly 9 arrows each; 55 unlisted pairs rejected per aggregate), 12 actions, 3
resolvers, impact preview, 6 typed exceptions, append-only history writer, 19 typed audit events
populating `AuditDomain::Compensation`. Supersede/end are consequences of approval/activation inside
one transaction — no supersede permission invented. Resolvers fail closed. **Activation
history-event correction** (documentation omission): `activated` added to the enum, the DB CHECK, both
state-machine specs and the data dictionary, applied by editing the still-uncommitted Increment 2
migration (never committed on any branch; forward-only is scoped to *shipped* migrations) with the
full Increment 2 schema proof + disposable PG16 proof rerun. Gates: state machines 18, actions 50,
resolvers 24, audit/scope/parity 37, schema+manifest+provider+tenancy 97, Pint 1317, Larastan L8
clean. `openapi.json` regenerated (pre-existing audit endpoints document the AuditEvent enum) —
**196 paths / 235 operations, unchanged from the 20E baseline**; no Phase 20F API surface exists yet.

### Phase 20F Increment 4 (permissions + API + contracts) — COMPLETE, green

Permission flip landed atomically: **8 canonical HR-only branch-scoped keys activated**, **2 legacy
keys retired outright** (no alias, no compatibility grant). Counts: **active 104 → 110, planned
66 → 58, legacy-active ratchet 10 → 8** (proven from the repository). The retired `commissions.view`
took its merchant_admin/branch_manager/personnel/audit grants and finance grantable override with it —
no broad replacement (Plan §10.2); successors stay PLANNED in 20G/20H. API: **11 new paths / 13 new
operations**, all `branch_mutation`, HR-only; approve carries
`RequireFreshMfa:compensation_backdated_change`. No DELETE / generic status / manual supersede /
ledger / payout / earnings route. Idempotency correctly not required (configuration, not
`financial_mutation`); replay is blocked by the state machine + DB EXCLUDE. Audit: 8 routes mapped;
matrix `audit_event` re-derived from the live route table; backdated approval proven CRITICAL; no
success audit on denial. Contracts: OpenAPI **207 paths / 248 operations**, `api:contract:check` OK,
`permissions.ts`/`api.ts` generator-only and **deterministic on re-run**. Gates: Phase 20F suite 290;
full backend **1469 passed / 7 skipped / 0 failed**; Vitest 352; vue-tsc clean; Pint 1334; Larastan L8
clean. **No frontend/Pinia/Playwright work yet (Increment 5).**
- Phase 20D-W remains **blocked** unless/until Gate W opens.
- Phase 20G and Phase 20H are **not started**.

## Phase 20E — Percentage Platform-Fee Engine (verified_complete)

- **Lifecycle:** ✅ `verified_complete` — reconciled from `local_complete pending PR CI/review/merge`
  during **Phase 20F Increment 1**, on the evidence below. **Branch:**
  `phase-20e-percentage-platform-fees` off `origin/main` = `735f419bf72fdd9be3f95c4507e8925c1ed0859e`
  (= the Phase 20C PR #37 squash merge); never worked on `main`. **Base verified:** HEAD before commit =
  merge-base = `735f419…`; `git fsck` clean; old `phase-20c*` local + remote branches absent.
- **Merge evidence (PR #38):** **PR #38** "Phase 20E: Implement percentage platform fee engine" —
  state **MERGED**, merged `2026-07-14T06:19:43Z`,
  <https://github.com/ikrome002-design/servana/pull/38>. Implementation commit
  `f6e208a90513bf5ca1c219c456b263ea0d111c5c` (recorded exactly once on the PR); governance / final PR
  head `24d1cad60539fe40596125240391c48a1b821246` (recorded exactly once); merge commit
  `c0881993ae0c59536013c9b84e182e5000fa1e11` = `origin/main` = local `main` at Phase 20F branch creation
  (`origin/main...HEAD` = `0 0`, working tree clean, `git fsck --full` clean).
- **Final CI:** run `29310753740` — five required jobs all **SUCCESS**: Backend (Pint, Larastan, Pest);
  Frontend (ESLint, vue-tsc, Vitest, build); Docker (build images); Security (gitleaks);
  E2E — Playwright.
- **Governance:** `reviewDecision` **blank** under the documented solo-maintainer governance exception.
  This is **not** independent reviewer approval and is deliberately preserved as such — Phase 20E is
  **not** rewritten as independently reviewed.
- **Branch cleanup:** local `phase-20e-percentage-platform-fees` deleted; remote
  `phase-20e-percentage-platform-fees` deleted (both verified absent at Phase 20F Increment 1).
- **No 20E implementation logic was altered by this reconciliation** — documentation only.
- **Phase 20C reconciliation:** PR #37 MERGED (squash `735f419…`, impl `782c973…`, final head
  `efe0f74…`, merged `2026-07-12T11:50:45Z`); CI initial `29191160816` + final `29191381748` — five
  required jobs SUCCESS; `reviewDecision` blank (solo-maintainer exception, **not** independent
  approval). Reconciled `docs/proof/phase-20c.md`, this file, `docs/CHANGELOG.md`,
  `docs/traceability/servana-requirements.csv` (SRV-PROMOTION-001 + SRV-FREE-PERIOD-001) →
  `verified_complete`. No open remediation item is 20C-owned.
- **Gate W status:** **CLOSED** — `docs/integrations/wallet/gate-w-evidence.md` and the
  `docs/integrations/wallet/` directory are **absent**; no credentials / pinned OpenAPI hash / contract
  suite / sandbox STK/C2B transcript. Per the v4 graph (`20A + 17/18 → 20E`), Phase 20E is independently
  eligible. **No pivot to 20D-W.**
- **Specification gates (E1–E9):** resolved before any migration — see `docs/proof/phase-20e.md`
  decision table. E1 ledger lifecycle = Plan §13.10 canonical (`earned`/`pending`→`aggregated`→`invoiced`;
  additive `reversal`/`adjustment`; `settled` excluded = 20D-W); created at **Finance validation**
  (billability authority), config snapshot at P17 finalization. E2 fee-basis vocabulary =
  `{merchant_client_invoice_service_subtotal, merchant_client_invoice_total, net_after_discount,
  invoice_item_subtotal, validated_paid_amount}`. E4 tier = `customer_centric/shared/business_centric`
  with deterministic `split_tier→shared` mapping of the shipped `merchants.service_fee_tier` seam;
  fail-closed when missing in a percentage mode. E9 reconciles legacy `platform_fees.view` /
  `platform_fees.dispute`.
- **Increment 1 (specs + reconciliation) — COMPLETE:** 20C reconciliation, Gate W record, E1–E9 decision
  table (`docs/proof/phase-20e.md`), data-dictionary entries (4 tables + invoice/item expands), 3 state
  machines, traceability row `SRV-PLATFORM-FEE-001`. Migration **plan** recorded in the proof; the 7
  manifest entries are registered in Increment 2 with the migration files (repo `MigrationManifestTest`
  forbids entries without on-disk files). **No migration until Increment 2.**
- **Tests:** `MigrationManifestTest` **9 passed** (validates the doc edits left the manifest lint green);
  no product code this increment.
- **Increment 2 (migrations/enums/models/factories/guards) — COMPLETE + green:** 6 forward-only migrations
  (4 tables + 2 invoice expands; inline immutability triggers; gist overlap exclusion), 7 enums, 4 models,
  4 factories, `TenantOwnership` registration (config EXEMPT; ledger/adjustment/dispute TENANT_OWNED). Gates:
  `Phase20ESchemaTest` 24 pass, `Phase20EEnumParityTest` 12 pass, `TenantColumnCoverageTest`+
  `ModelTenancyTraitCoverageTest` 21 pass, `MigrationManifestTest` 9 pass, Pint clean, Larastan L8 clean.
  Disposable PG16 `servana_p20e_proof` migrate:fresh --seed = 94 migrations + seed clean, 4 tables + 5
  triggers, 0/0/0/0 fee rows (no backfill), dropped. Fixed DEF-20E-001 (test-defect: escalated CHECK via
  raw UPDATE). **Manifest now registered (94 entries).**
- **Increment 3 (resolvers + arithmetic engine) — COMPLETE + green:** `CalculatePlatformFee` (+
  `CalculatedPlatformFee` VO), `AllocatePlatformFeeByLargestRemainder` (+ `AllocatedPlatformFeeItem` VO),
  `ResolveMerchantServiceFeeTier` (split_tier→shared, fail-closed), `ResolveEffectivePlatformFeeConfiguration`
  (find/require), `PlatformFeeConfigurationStateMachine` + `PlatformFeeDisputeStateMachine`,
  `PlatformFeeException`. Gates: `PlatformFeeCalculationTest` 21 pass, `PlatformFeeConfigurationResolutionTest`
  6 pass, Pint clean, Larastan L8 clean. Fixed DEF-20E-002 (allocator deterministic output ordering).
- **Increment 4 pre-work — COMPLETE + green:** partial-payment billability reconciled against the actual
  `ValidatePaymentRecordingGroup` (group-level atomic validation → `PaymentValidationEvent` is the
  validation-source identity). Rule: snapshot bases release proportionally per event (residual on final
  validation); `validated_paid_amount` per event. **Migration correction** (uncommitted
  `2026_07_13_000002`): added `source_validation_event_id` FK → `payment_validation_events` (+ index, in
  the immutable tuple); data dictionary/model/manifest updated. Gates: `Phase20ESchemaTest` 25 pass;
  enum/manifest/tenancy 50 pass combined; Pint clean. No product-owner question needed.
- **Increment 4A (finalization integration) — COMPLETE + green:** Gate 4.2 (validated_paid_amount →
  customer_centric only; DB CHECK + resolved-tier guard), Gate 4.1 (non-circular total), Gate 3.7
  (structural unique on validation source). `RecordPlatformFeeAtFinalization` + `ResolvePlatformFeeBasis` +
  `PlatformFeeFinalizationResult` wired into `FinalizeInvoice` (fee before number alloc; client-shifted
  added to total; fixed-only inert). Constraint-expand migration `2026_07_13_000007` widened
  `invoices_total_arithmetic_check` (DEF-20E-003). `PlatformFeeFinalizationTest` 9 pass; Phase 17
  regression (FinalizeInvoice + InvoiceCorrection) + schema + manifest = 47 pass combined; Pint + Larastan
  L8 clean. Manifest now 7 migrations (95 entries).
- **Increment 4B (validation billability) — COMPLETE + green:** `RecordOriginalPlatformFeeLiability`
  hooked into `ValidatePaymentRecordingGroup` step 4b (inside the txn, after `validated_paid_minor`).
  Invoice-level earned entries (one per `PaymentValidationEvent`); snapshot bases release proportionally
  with residual capture; `validated_paid_amount` per-event; zero-total fail-closed; idempotent.
  `CalculatePlatformFee::splitByTier`; `AuditEvent::PlatformFeeOriginalRecorded` (Info/Finance).
  `PlatformFeeBillabilityTest` 6 pass; phase20e group **80 pass / 210 assertions**; Phase 18B regression
  17 pass; Pint + Larastan L8 clean. **Increment 4 (finalization + billability) fully integrated.**
- **Increment 5 (aggregation + reversals/adjustments + disputes) — COMPLETE + green:**
  - *5A* `AggregatePlatformFeesIntoSubscriptionInvoice` folds the earned/pending rollup into the P20B
    `IssueSubscriptionInvoice` transaction (no second aggregate; schema forces at-issuance folding —
    plan/price NOT NULL, immutable snapshot). Eligibility earned+pending+in-period+same merchant/currency,
    not-linked; period `[start,end)` Africa/Nairobi; order `billable_at ASC, ulid ASC`; DB cycle guard
    migration `2026_07_13_000008` (partial-unique rollup-per-invoice); `pending→aggregated→invoiced`;
    rollback-safe numbering. `PlatformFeeLedgerEntryStateMachine` added.
  - *5B* `RecordPlatformFeeReversal` + `RecordPlatformFeeAdjustment` hook inside `ExecuteInvoiceVoid`
    (full reversal) and `FinalizeRefund` (full/partial proportional); append-only ledger + signed
    `platform_fee_adjustments` row; original never edited; 409 over-reversal; source idempotency; period
    lock + maker/checker inherited.
  - *5C* dispute actions `Create/Start/Resolve/Reject` on `PlatformFeeDisputeStateMachine`
    (`open→under_review→resolved|rejected`); money-changing resolve → `dispute_resolution` adjustment,
    ledger + issued subscription invoice untouched; 422 invalid transition; self-resolution blocked.
  - 8 new audit events (`platform_fee.aggregated/invoiced/reversed/adjusted/dispute_*`).
  - Tests: aggregation 10, reversal/adjustment 10, dispute 9 (+ ledger SM). Full `tests/Feature/Billing`
    **433 pass**; regression Invoicing+Refunds+validation **62 pass**; gates (manifest 95, tenancy,
    NoDirectProvider, audit coverage) **31 pass**; Pint clean; Larastan L8 clean. Fixed DEF-20E-004
    (test TZ round-trip) + DEF-20E-005 (stale `SubscriptionInvoiceTest` table-absence assertion).
- **Increment 6 (canonical permissions + HTTP API + audit/route-security + OpenAPI/TS) — COMPLETE + green;
  recovered after 2026-07-13 desktop reboot (no file loss — 104 recovery files hash-match backup):**
  16 routes (7 platform config `create/update-draft/approve/supersede/cancel/list/show`; 3 merchant masked
  reads `platform-fees[/summary/{entry}]`; 6 disputes `create/list/show/review/resolve/reject`) — thin
  controllers → Form Request → policy → context → MFA/step-up/period/idempotency → transactional action →
  masked ULID-only Resource; no reversal/adjustment/aggregation/status/DELETE/Wallet routes. **Permission
  reconciliation (Gate E9) — product-owner decision Option A (2026-07-13):** legacy plural `platform_fees.view`
  /`platform_fees.dispute` retired; four canonical keys `platform_fee.view`, `platform_fee.dispute`,
  `platform_fee.dispute.review`, `platform.platform_fee.configure` **authorized into the Plan §19.2 catalogue
  + §19.3 populated matrix** (156→160), `owning_phase` cleared to null in YAML. Merchant-side dispute model
  kept (NOT moved to Phase 20D-W `platform.billing_reconciliation.*`). Legacy-ratchet 12→**10**; all four
  parity layers (YAML/PHP/DB/TS) atomic; `permissions.ts` regenerated (byte-identical), OpenAPI 235 ops +
  `api.ts` regenerated (deterministic; recovered `openapi.json`/`api.ts` were stale — regenerated).
  **Gates:** Increment-6 API 30; full Billing 455; auth/matrix 19 (catalogue/parity 160, ratchet 10);
  security/audit/tenancy/boundary 53; regression Invoicing+Refunds+Audit 167; frontend vue-tsc clean +
  ESLint 0 errors + Vitest 321 + build ✓; composer validate valid; Pint clean; Larastan L8 clean.
  `REM-PERM-001` stays OPEN (Phase 19-owned). Proof: `docs/proof/phase-20e.md` (Increment 6 + Recovery
  incident). **NOT committed/pushed/PR'd (Increment 6 boundary).**
- **Backend closure — future-cycle correction aggregation — COMPLETE + green (post-Increment 6):** closes the
  one Phase 20E-owned financial gap. Pending `reversal`/`adjustment` corrections of ALREADY-INVOICED fees are
  swept into one signed `subscription_invoice_items.type='adjustment'` line on the next issued invoice
  (`AggregatePlatformFeesIntoSubscriptionInvoice::collectApplicableCorrections()`/`writeCorrectionLine()` +
  `IssueSubscriptionInvoice`; new VO `PlatformFeeCorrectionSelection`). Signed source = paired
  `platform_fee_adjustments.amount_minor` (idempotency linkage); consumed `pending→aggregated→invoiced`;
  negative net capped so the invoice total can never go negative (DB `total>=0`), residual carries forward
  (whole-entry, no split, no new concept); a correction of a never-invoiced original is skipped (no spurious
  credit). No migration; no route/OpenAPI change; audit reuses `platform_fee.aggregated`/`.invoiced`. Gates:
  `PlatformFeeCorrectionAggregationTest` 8/46; Billing 463; Invoicing+Refunds+Audit+NoDirectProvider+Tenancy 83;
  Pint clean; Larastan L8 clean; composer validate valid. No Wallet/provider/credit runtime. Proof:
  `docs/proof/phase-20e.md` (Backend closure). **NOT committed/pushed/PR'd.**
- **Increment 7 (frontend platform-fee surfaces) — COMPLETE + green:** Vue 3 + Pinia UI for all six roles,
  UX-gated by `useCan()` (backend authoritative). 3 stores (`platformFeeConfigStore`/`platformFeeStore`/
  `platformFeeDisputeStore`). 7B: Super-Admin **Platform fees** tab in `BillingSettings.vue`
  (`PlatformFeeConfigSection.vue`; create/edit-draft/approve/supersede/cancel; approved terms read-only;
  client validation mirrors server incl. shared-split + validated_paid/customer-centric). 7C: one shared
  `pages/billing/PlatformFees.vue` mounted per role (`{merchant,branch,finance,audit}.platform-fees`;
  server-scoped summary + entries + disputes; merchant/Finance create, Finance-only review/resolve/reject,
  Branch/Audit read-only); Front Office client-shifted **Platform fee** line in `InvoiceDetail.vue`. Backend
  contract fix (§23): added masked `platform_fee_client_shifted` to `InvoiceResource` (regenerated OpenAPI/
  api.ts; Invoicing 42 pass). Shared `SvModal` given internal scroll (§16). Screen inventory (4 entries +
  regen yaml/specs; 8/8 guard) + nav items added/synced. Gates: Vitest 352 (18 new specs); Playwright
  `phase-20e` 14 (responsive 360/768/1280, 200% zoom, keyboard/focus, axe 0 serious/critical light+dark);
  modal/nav e2e 32 (no regression); vue-tsc clean; ESLint 0 err; build ✓; api:contract:check OK;
  permission-types --check clean; backend platform-fee API 30. No Wallet/provider/settlement UI. Proof:
  `docs/proof/phase-20e.md` (Increment 7). **NOT committed/pushed/PR'd.**
- **Increment 8 (full local gate sweep + single completion commit) — COMPLETE + green (2026-07-13):** whole-phase
  local acceptance run on PG16 / PHP 8.3.32 / Laravel 12.62.0. **Backend quality:** `composer validate` valid;
  Pint 1266 files PASS; Larastan **L8** No errors (971). **Isolated fresh build:** disposable DB `servana_fresh_proof`
  (never the dev DB), `migrate:fresh --seed` green, 96 migrations (8 Phase 20E), all 4 tables present, DB dropped.
  **Backend serial** 1181 pass / 7 skip / 0 fail / 7396 assertions (578.78s); **parallel** 1181 / 7 skip / 0 fail /
  7396 (4 procs, 338.53s). **Phase 20E targeted** 138 / 431. **Contract gates** (perm/route/idempotency/audit/arch/
  no-direct-provider) 52 / 1121. **Catalogue:** §19.2 = **160**, §19.3 = **160**, legacy-active = **10** (plural
  `platform_fees.*` retired). **Determinism:** OpenAPI/api.ts/permissions.ts SHA-256 identical across baseline + 2
  regen runs, no git diff; `permission:types --check` up to date; `api:contract:check` OK — **196 paths / 235 ops**.
  **Frontend:** ESLint 0 errors (138 pre-existing warnings, no Phase 20E file); vue-tsc clean; Vitest **352 / 82
  files**; build ✓ (10.33s). **Playwright:** affected 77; full **324 / 0 fail** (Phase 20E 360/768/1280 + 200% zoom +
  keyboard/focus + axe serious/critical = 0 light+dark). **Security:** composer audit no advisories; npm audit high
  gate exit 0 (2 moderate dev-only `js-yaml`@`@redocly/openapi-core`, disclosed); gitleaks no leaks. **Docker:**
  `servana-app:dev` + `servana-app:prod` + `servana-nginx:prod` all Built. **Scope purity** clean (47 modified + 97
  untracked, all Phase 20E / 20C-reconciliation / integration seams; no Wallet/provider runtime). No gate failed on
  first run; nothing disabled/loosened/suppressed. Proof: `docs/proof/phase-20e.md` (Increment 8).
- **Next action:** product-owner-authorized Phase 20E **PR creation + CI/observe + review/governance + merge**
  workflow (then reconcile to `verified_complete`). Increment 8 stops at the pushed branch — **no PR created, no
  merge, branch not deleted, no next phase started**.
- **Inherited (to close in 20E):** P17 finalize %-fee seam; P18B validation billability hook; P19 audit +
  parity for 20E mutations; P20A config/MFA reuse; P20B `platform_fee_rollup` aggregation; P20C snapshot
  reuse.
- **Skipped (owner phases):** Wallet sync/registration/STK/PayBill/webhooks/apply/reconciliation →
  **20D-W**; compensation → **20F**; salary/commission ledgers → **20G**; payouts/earnings → **20H**;
  R&E → **21R-A/B**; notifications/reports → **21N**; personnel SMS → **21S**; search → **22**; release
  hardening → **23**; perf → **24**; deploy → **25**.
- **Next action:** complete Increment 1 docs (data dictionary + 3 state machines + migration manifest +
  traceability 20E rows), then Increment 2 (migrations/enums/models/factories/constraints + schema tests).
- **Residual risk:** partial-payment billability rule for fixed-at-finalization bases is a derived
  decision (documented E7); to be validated by aggregation + validation tests in Increments 4–5.

## Phase 20A — Plan Catalogue, Prices, Entitlements, Billing Settings (verified_complete)

- **Lifecycle:** ✅ `verified_complete` — reconciled from `local_complete` on the **PR #35** merge
  during Phase 20B Increment 1. PR #35 "Phase 20A: Implement billing catalogue settings and fee rules"
  MERGED into `main`: squash merge `6813690ef5fa9f7d782532b49e2bca43c2afc112`, implementation head
  `a31cd000f84a0a19f1d8b526a4fdf5d01aefc090`, final PR head `56a81bd305aacf3a7fb2ffa976d9a089591e3f41`,
  merged `2026-07-11T07:56:09Z`; five-gate CI (Backend/Frontend/Docker/Security/E2E—Playwright) all
  SUCCESS; `reviewDecision` blank — documented solo-maintainer governance exception, **not** independent
  reviewer approval. Increments 1–7 COMPLETE + green. **Branch:** `phase-20a-billing-catalogue-settings`
  created from `origin/main` = `85bd3e570db1436586d3d1ead17ab6b1701538d5`; never worked on `main`.
  **Proof:** [phase-20a.md](proof/phase-20a.md). (History preserved: the Phase 20A completion commit
  `a31cd00…` landed via PR #35; the earlier "single completion commit deferred / push then STOP" plan
  is superseded by the merged PR.)
- **Gate A (v4 adoption PR) — PASSED and verified:** PR **#34** MERGED, merge commit `85bd3e5…`,
  five CI checks SUCCESS, `reviewDecision` blank (solo-maintainer exception, not independent approval),
  merge contained in `origin/main`; Phase 19 merge `7ef259e2` contained; `git fsck` clean.
- **Increments 5–7 — COMPLETE + green (frontend, E2E, full gates):** single screen
  `platform-billing-settings` (route `platform.billing-settings`, `PlatformAdminLayout`) — accessible
  tabbed surface (general/billing settings, plans, prices, entitlements, preferred-fee), 5 generated-
  type-backed Pinia stores, `Idempotency-Key` on effective-dated creates, MFA/step-up guidance on
  server rejection, three billing modes + five intervals mirrored, integer-minor-unit money.
  Branch-Manager effective-fee read-only card integrated into `branch.services` (no new top-level
  screen; §5.9). Nav reconciled (4 labels → the one live route; `platform.merchants`/
  `registration-monitoring` phase `20A→20B`, kept planned). Inventory → `implemented` + route + full
  §27.1 spec `docs/frontend/screens/platform/platform-billing-settings.md`. **Contract-truth fix:**
  `effective_to`/`approved_at` made explicitly nullable in 3 Resources (Scramble missed `?->`) →
  regenerated OpenAPI/TS (`["string","null"]`). **Vitest 279** (+31); **Playwright** `phase-20a-billing.spec.ts`
  **17/17** + full **e2e 269** (+17); axe serious/critical=0 light+dark, no overflow 360/768/1280,
  keyboard tablist. **Backend serial 1164 pass / 7 skip / 0 fail + parallel 1164/7**; Pint 1040 clean;
  Larastan L8 no errors (784); OpenAPI 188 routes / 157 paths / 188 ops + TS + permission-types + contract
  all up-to-date; composer audit clean; npm audit 2 moderate (below high gate, pre-existing dev dep);
  gitleaks no leaks; php-dev + nginx-prod images build. **Two E2E defects root-caused + fixed** (nav
  strict-mode scope; broad `/plans**` stub hang → precise list regex). **Permission counts unchanged:**
  9 canonical 20A keys active, 3 legacy retired; 94 active / 77 planned; YAML/PHP/DB/TS parity green.
- **Inherited work closed here:** Phase-15A `preferred_personnel_fee_rules` (delivered Increment 2/3);
  Phase-17 legacy `PreferredPersonnelFeeResolver` seam replaced by `RuleBasedPreferredPersonnelFeeResolver`
  (future finalization resolves the effective rule; finalized snapshots unchanged); Phase-19 billing
  audit emissions for every 20A mutation (13 AuditEvent cases, 12 mutating routes covered); Phase-19
  legacy→canonical permission reconciliation for the 20A-owned keys (9 activated, 3 retired).
- **Deferred (exact owner phase, nothing fabricated):** merchant subscriptions/billing-status projection/
  subscription invoices → **20B**; promotions/free periods → **20C**; Wallet billing payments + provider
  integration → **20D-W** (after Gate W); percentage platform-fee ledger/adjustments/disputes → **20E**;
  compensation config → **20F**; earned commission + salary ledgers → **20G**; personnel payouts/earnings →
  **20H**; R&E capture/outbox → **21R-A**; R&E qualification/reconciliation → **21R-B**; notifications/
  reports → **21N**; personnel SMS → **21S**; search → **22**; release-wide security/export/responsive/
  dark/a11y hardening → **23**; performance → **24**; centralized alert transport/runbooks/deployment → **25**.
- **Scope (Plan §13.9/§13.10/§20/§47/§49/§50; ADR-011/ADR-005):** five platform tables
  (`platform_billing_settings`, `subscription_plans`, `subscription_plan_prices`, `plan_entitlements`,
  `preferred_personnel_fee_rules`), canonical `BillingMode`(3)/`BillingInterval`(5) enums, legacy
  preferred-fee expand-and-contract, entitlement resolver/gate substrate, Super-Admin platform_mutation
  API + the single `platform-billing-settings` screen, audit events, and 20A permission activation/
  reconciliation. **Only owned inventory screen:** `platform-billing-settings`.
- **Source-of-truth reconciliation (DONE, recorded):** `inventory.json` (+`inventory.yaml` snapshot)
  entries `platform-registration-monitoring` and `plan-management` were mistagged Phase 20A; retagged
  **20A → 20B** to match Plan §47 (which excludes registration monitoring/merchant governance from 20A)
  and the permission matrix (`platform.registration_monitor.view`, `platform.merchant.*`,
  `merchant.subscription.plan_change` are all `owning_phase: Phase 20B`). No permission-matrix authority
  changed. Both depend on `merchant_subscriptions`/`scheduled_plan_changes` (20B, excluded from 20A §7).
- **Increment 1 — spec-first gate (COMPLETE; runtime guards pending Docker):**
  data dictionary `billing-and-wallet.md` expanded with full column/type/CHECK/FK/EXCLUDE/index/ULID/
  immutability/locking/backfill/audit/retention/factory/test entries for all five tables; state
  machines `preferred-personnel-fee-rule.md`, `plan-price.md`, `platform-billing-settings.md`,
  `subscription-plan.md`; enums `app/Domain/Billing/Enums/BillingMode.php` + `BillingInterval.php`.
  Legacy preferred-fee cutover policy recorded as **prospective** (migration-date `effective_from`,
  not retroactive; no product-owner decision required — derived from Plan §13.10 + Phase-17 note).
- **Phase-20A permission keys to ACTIVATE (planned → active in Increment 5):** `platform.billing_settings.view/update`,
  `platform.plan.view/manage`, `platform.plan_price.manage`, `platform.preferred_personnel_fee.manage`,
  `platform.settings.view/update`, `preferred_personnel_fee.view_branch_rule`. **No** `platform.entitlement.manage`
  (absent from the Plan — entitlements managed under `platform.plan.manage`). Legacy 20A-owned keys to
  reconcile carefully (no prior-phase rename): `platform.billing.configure`, `platform.fee_rules.manage`,
  `platform.settings.manage` (+ operational legacy keys parked to 20A — activate canonical successors
  only where a real 20A route consumes them).
- **Docker/PG16 blocker — recorded and CLOSED:** initial failure — Docker Desktop's Linux engine
  returned **HTTP 500** and PG16 verification could not run (Increments 2–7 blocked). Root cause — the
  `docker-desktop` WSL distro was running but the Docker Linux engine was not serving its API. Recovery
  (product owner) — external checkpoint backup, Docker Desktop + WSL shutdown, Docker Desktop restart,
  waited for `docker info`, started Compose, verified Laravel/PostgreSQL. Resolution proof (resume) —
  engine healthy; 11 services up; `servana-app-1` + `servana-postgres-1` healthy on `postgres:16-alpine`;
  Laravel 12.62.0 executable; `migrate:status` OK; branch/HEAD unchanged; 11 Increment-1 paths preserved;
  `git diff --check` passes. Increment 1 re-verified — enums lint + 3/5 canonical values; Pint 956 clean;
  vue-tsc clean; screenInventory 8/8. Remaining risk: none from the incident. **The initial failure is
  retained above, not erased.**
- **Exact next action (resume on the same branch, dirty tree preserved, NOT committed):** once
  `docker info` succeeds — Increment 2: author the five forward-only migrations
  `2026_07_10_000001_create_platform_billing_settings_table` … `_000005_create_preferred_personnel_fee_rules_table`
  (CHECKs + `btree_gist` EXCLUDE + indexes; platform-scoped, no merchant_id/branch_id), add their
  `docs/architecture/migrations/manifest.yaml` entries (data_dictionary → `billing-and-wallet.md`) and
  `TenantOwnership` PLATFORM_OWNED registration, add `BillingEnumParityTest`, then
  `docker compose exec -T app php artisan migrate:fresh --seed` + schema/parity tests on PG16.
- **Work skipped → owner phase:** merchant_subscriptions/billing-status projection/subscription
  invoices → 20B; promotions/free periods → 20C; Wallet billing/provider integration → 20D-W (Gate W);
  %-fee ledger/adjustments/disputes → 20E; compensation → 20F; commission/salary ledgers → 20G;
  payouts → 20H; R&E runtime → 21R; notifications/reports → 21N; SMS → 21S; search → 22; release-wide
  hardening → 23; performance → 24; deployment → 25.

## Phase 20B — Subscription Lifecycle and Subscription Invoices (verified_complete)

- **Lifecycle:** ✅ `verified_complete` — reconciled from `local_complete pending PR CI/review/merge`
  on the **PR #36** merge during Phase 20C Increment 1. **PR #36** "Phase 20B: Implement subscription
  lifecycle and invoices" MERGED into `main` (base `main`, head branch
  `phase-20b-subscription-lifecycle-invoices`, merged `2026-07-12T06:57:28Z`). **Implementation commit:**
  `6790081bace7efb2a659ec8254e6eda53d3d5935`. **Governance / final PR head:**
  `4a998dc6e4c0f8259c8d6c179c076f8b8496aec9`. **Squash merge commit:**
  `3dd528a2779a44d13b9fe105ac9ee49e688e84c6` (= current `origin/main`). **CI:** initial run
  `29183137798` (head `6790081…`) SUCCESS; final run `29183286205` (head `4a998dc…`) SUCCESS — five
  required jobs all SUCCESS: Backend (Pint, Larastan, Pest), Frontend (ESLint, vue-tsc, Vitest, build),
  Docker (build images), Security (gitleaks), E2E — Playwright. `reviewDecision` **blank** under the
  documented PR-specific solo-maintainer governance exception — **not** independent reviewer approval.
  Local and remote `phase-20b-subscription-lifecycle-invoices` branches were deleted after merge.
  **Branch (pre-merge):** `phase-20b-subscription-lifecycle-invoices` off `origin/main` =
  `6813690ef5fa9f7d782532b49e2bca43c2afc112` (the Phase 20A PR #35 squash merge). **Proof:**
  [phase-20b.md](proof/phase-20b.md). (History preserved: the earlier "single completion commit / push
  then STOP" plan is superseded by the merged PR #36. All Phase 20B implementation, test totals,
  defects, corrections, deferrals and scope boundaries below are retained verbatim.)
- **Phase 20A reconciliation (done):** PR #35 MERGED (squash `6813690…`, impl `a31cd00…`, head
  `56a81bd…`, merged 2026-07-11 07:56:09Z, five-gate CI SUCCESS, blank reviewDecision = solo-maintainer
  exception, not independent approval). Reconciled proof-20a/PROGRESS/CHANGELOG/register(REM-ENUM-001)/
  traceability(SRV-BILLING-CAT-001) → `verified_complete`. 20E percentage ledger stays a separate 20E item.
- **Specification gates (decision table in proof-20b.md):**
  - **B1 (trial anchor)** — RESOLVED from evidence: `trial_started_at` = Merchant-Admin creation time;
    subscription bound at first-time setup; `trial_days_snapshot` from effective `default_trial_days`;
    idempotent; settings changes never rewrite an existing trial. (§48, §13.9 l.907, §25.2)
  - **B2 (terminal projection)** — RESOLVED by product-owner decision: `cancelled`/`expired` →
    `merchants.billing_status = suspended_billing`, distinct reasons `subscription_cancelled`/
    `subscription_expired`, projected only at the effective terminal boundary; no auto terminal→active.
    (`merchants.billing_status` CHECK has 5 values, no cancelled/expired.)
  - **B3 (invoice numbering)** — RESOLVED (owned): expand `invoice_number_sequences.scope` to add
    `subscription_invoice`; independent per-merchant gap-free counter; shipped migration not edited.
  - **B4 (escalation idempotency)** — RESOLVED (owned): `billing_escalation_events.period_boundary date
    NOT NULL` + `UNIQUE(merchant_subscription_id, event_type, period_boundary)`; never `created_at`.
  - **B5 (non-fixed mode)** — RESOLVED (derived): `IssueSubscriptionInvoice` fails closed on a
    percentage-requiring mode (`billing_mode_not_supported` 422); never a silent undercharge. (§50/§51/§52)
- **Schema/migrations planned (Increment 2):** `2026_07_11_000001..000005` create
  `merchant_subscriptions`, `scheduled_plan_changes`, `subscription_invoices`,
  `subscription_invoice_items`, `billing_escalation_events`; `_000006` expands
  `invoice_number_sequences.scope`. All merchant-owned (`merchant_id`, no `branch_id`). Data dictionary
  entries complete in `billing-and-wallet.md` (§20B section, column-level).
- **State machines (Increment 1, done):** `merchant-subscription.md`, `merchant-billing-status.md`
  (projection), `scheduled-plan-change.md`, `subscription-invoice.md`, `billing-escalation.md`.
- **Enums planned:** `MerchantSubscriptionStatus`(7), `MerchantBillingStatus`(5),
  `ScheduledPlanChangeStatus`(3), `SubscriptionInvoiceStatus`(9), `BillingEscalationEventType`(5),
  `WalletRegistrationStatus`(4); reuse Phase 20A `BillingInterval`(5)/`BillingMode`(3).
- **Permissions:** activate `merchant.subscription.{view,plan_change,invoice.view,invoice.download}`,
  `platform.registration_monitor.view`, `platform.merchant.{view,suspend,reactivate,deactivate}`;
  reconcile legacy `merchant.tier.update`→`merchant.subscription.plan_change` and
  `platform.merchants.govern`→`platform.merchant.suspend`. Do NOT activate the 20D-W pay/attempt keys.
- **Screens (inventory phase == 20B):** `subscription-dashboard`, `plan-management`,
  `subscription-invoices` (merchant), `platform-registration-monitoring` (platform). Each needs a §27.1 spec.
- **Inherited-and-owned closed here:** the five 20A-created-but-20B-owned tables + billing-status
  projection; `plan-management`/`platform-registration-monitoring` inventory (retagged 20A→20B); Phase 17
  billing read-only seam → real `merchants.billing_status`; Phase 10F boolean seam → real billing status;
  Phase 19 typed audit + `AuditMutationCoverage` for every 20B mutation.
- **Exclusions (owner phases):** promotions/free-periods → 20C; Wallet runtime/outbox/STK/PayBill/
  webhooks/payments/reconciliation/credits → 20D-W (Gate W); percentage & fixed-plus-% ledger/
  adjustments/disputes → 20E; compensation → 20F; commission/salary → 20G; payouts → 20H; R&E → 21R;
  notifications/reports → 21N; SMS → 21S; search → 22; hardening → 23; performance → 24; deployment → 25.
  `mpesa_offline` merchant-client terminology preserved. 20B ships nullable Wallet projection columns only.
- **Increments:** 1 (reconciliation + gates + data dictionary + state machines + traceability) — **DONE**;
  2 (migrations + enums + schema tests + PG fresh-build) — **DONE + green**; 3 (calculator + lifecycle +
  projection + gate + scheduled changes + onboarding trial-wiring + scheduler) — **DONE + green** (245
  onboarding+billing tests); 4 (invoice issuance + numbering + items + PDF/10F + escalation) — **DONE + green**
  (253 billing tests); 5 (permissions + policies + requests/resources/controllers/routes + audit + OpenAPI/TS) — next;
  6 (frontend + nav + inventory + §27.1 specs + Vitest); 7 (Playwright + a11y + full gates + single commit + push).
- **Increment 7 (COMPLETE + green — E2E + full local gates):** `tests/e2e/phase-20b.spec.ts` (23 tests,
  all pass) drives the real frontend against stubbed `/me` + `/api/v1`: dashboard across trialing/active/
  read_only_grace/overdue/suspended + terminal cancelled/expired + MFA-challenge redirect; no-proration
  schedule/cancel; billing-read-only control removal; structured 409; invoice detail + exact
  payment-reference-pending; new-PDF blocked in read-only vs existing-PDF downloadable; registration
  monitoring; merchant directory/detail with operational vs billing separated; suspend with mandatory
  reason + fresh-step-up 403 guidance; reactivate not clearing billing suspension; forbidden-UI absence;
  merchant role denied platform route; axe serious/critical=0 (light+dark) on dashboard + governance
  dialog; no overflow at 360/768/1280; dialog focus management + restoration. Two initial failures fixed
  (membership role enum value `merchant_admin`; `text-primary`→`text-heading` AA-contrast links).
  **Full local gate battery green:** contracts (openapi 203 ops / api.ts / permission-types --check /
  contract:check) clean; `composer validate --strict` valid; Pint PASS (1129); Larastan L8 clean;
  backend `php artisan test` 1348 pass / 7 skip / 0 fail + `--parallel` 1348 / 0 fail; frontend ESLint 0
  errors, vue-tsc clean, Vitest 308 pass, build ✓, full Playwright 292 pass; security composer audit no
  advisories, npm audit clean at high (2 moderate dev-dep advisories reported truthfully), gitleaks no
  leaks; Docker dev + prod images built (sequential, not during Playwright). Phase 20B →
  **local_complete pending PR CI/review/merge** (NOT verified_complete).
- **Increment 6 (COMPLETE + green — frontend):** four Phase 20B screens on the existing layouts/router/
  Pinia/design-system, driven by the regenerated generated API + permission types, server `can` maps and
  structured errors (UX only; backend authoritative). Stores: `subscriptionStore` (dashboard/plans/
  scheduled-change + schedule/cancel), `subscriptionInvoiceStore` (list/detail + generatePdf mutation +
  downloadLink read + exact payment-reference copy), `platformMerchantStore` (registration-monitor/
  merchants/detail + suspend/reactivate/deactivate). Screens: `SubscriptionDashboard` (status + separate
  billing status + plan/price/dates + scheduled/latest-invoice summaries + read-only explanation),
  `PlanManagement` (plans + effective prices; schedule/cancel no-proration next-cycle change; server
  effective date; controls removed in read-only; 409/422 surfaced), `SubscriptionInvoices` (list/detail;
  payment-reference-pending; Generate PDF mutation blocked in read-only vs Download existing PDF read),
  `RegistrationMonitoring` (consolidated tabs: monitoring + merchant directory/detail with operational/
  billing separated + suspend/reactivate/deactivate via reason modal + confirmation + step-up surfacing +
  focus restoration). Navigation (`roleNavigation.ts` + `role-navigation.yaml`) 5 items planned→live;
  inventory (`inventory.json` + `.yaml`) 4 screens planned→implemented + 4 regenerated §27.1 specs.
  Gates: lint 0 errors, typecheck clean, **308 vitest pass** (7 new specs), build ✓. No merchant-create/
  first-admin/impersonation/payment/Wallet UI.
- **Increment 5 (COMPLETE + green — atomic permission/API/security/contract flip):** activated the nine
  canonical §19.2 keys (`merchant.subscription.{view,plan_change,invoice.view,invoice.download}` →
  merchant_admin; `platform.{registration_monitor.view,merchant.view,merchant.suspend,merchant.reactivate,
  merchant.deactivate}` → super_admin) and RETIRED the two dead legacy keys (`merchant.tier.update` →
  successor `merchant.subscription.plan_change`, dead `MerchantPolicy::updateTier()` deleted;
  `platform.merchants.govern` **truthfully split** into suspend/reactivate/deactivate — no 1:1 successor).
  **Counts (computed):** active 93→100, planned 77→68, legacy-active 14→12. Merchant API (existing tenant
  group; `TenantMutation`): subscription dashboard/plans/scheduled-change reads + schedule/cancel plan
  change (`merchant.subscription.plan_change` + `EnsureBillingMutable`, server-computed `effective_at`, no
  proration) + invoice list/detail + PDF generate (mutation, billing-read-only-blocked) + existing-PDF
  download-link (read, allowed in read-only, reuses `FileAccessService`). Platform API (existing platform
  group; `ResolvePlatformContext` + `EnsurePrivilegedMfa`; `PlatformMutation`): registration-monitor +
  merchant list/detail + `SuspendMerchant`/`ReactivateMerchant`/`DeactivateMerchant` (mandatory reason +
  `RequireFreshMfa:merchant_governance`; mutate `merchants.status` only, never `billing_status`; validate
  transition → 422; reactivate never clears a billing suspension). Three typed audit events
  `merchant.{suspended:high,reactivated:high,deactivated:critical}` + six routes mapped in
  `AuditMutationCoverage`. Contracts regenerated: OpenAPI 203 ops, `api.ts` (contract:check OK),
  `permissions.ts` (`--check` clean). Tests: `Phase20BPermissionActivationTest` (4),
  `MerchantSubscriptionApiTest` (13), `PlatformMerchantGovernanceApiTest` (10); updated matrix/legacy/
  planned/no-creation guards. Atomic guard battery (62) green; **full backend 1350 pass / 7 skip / 0 fail**;
  Pint clean (1129); Larastan L8 clean. `merchants.status` (operational) ⟂ `merchants.billing_status`.
- **Increment 4 (COMPLETE + green):** `SubscriptionInvoiceStateMachine` (`void`-only terminal);
  `AllocateSubscriptionInvoiceNumber` (row-locked independent `subscription_invoice` scope, `SUB-000001…`,
  Gate B3); `IssueSubscriptionInvoice` (Gate B5 fail-closed for non-fixed mode — no rows/audit; immutable
  `plan_fee` item = captured price; Wallet nulls; idempotent per period); `VoidSubscriptionInvoice`,
  `MarkSubscriptionInvoiceOverdue`; `RecordBillingEscalationEvent` (append-only per
  `(subscription,event_type,period_boundary)`, Gate B4). Invoice + item immutability guards. **PDF:**
  `GenerateSubscriptionInvoicePdf` via Phase 10F (`GeneratedFileWriter`, purpose `billing_invoice_pdf`,
  dependency-free `MinimalPdf` renderer), migration `2026_07_11_000009` (`file_id`/`pdf_version`);
  billing-status gate (403 `billing_read_only`, blocked in read_only_grace/suspended, existing
  downloadable); exact "Payment reference pending — see your billing dashboard"; versioned regeneration
  (prior revoked); `subscription_invoice.pdf_generated` audit. 9 new invoice/escalation audit events.
  Tests: `SubscriptionInvoiceTest` (11), `BillingEscalationTest` (4), `SubscriptionInvoicePdfTest` (8),
  `SubscriptionInvoicePdfDownloadTest` (4); `--group=billing` 253 pass / 539 assertions; disposable PG16
  fresh-build (82 migrations) green; Pint + Larastan clean.
- **Increment 3 onboarding+scheduler (green):** first-time setup now selects an active plan + effective
  price (`subscription_plan_ulid`/`subscription_plan_price_ulid`; `ResolveSetupPlanPrice` validates
  active/belongs/effective) and binds the trial via `CreateTrialSubscription` atomically (rollback on
  failure; §7.4 — no existing completed merchants, only PermissionSeeder; guard test added).
  `ProcessSubscriptionLifecycle` command registered daily/Nairobi/withoutOverlapping/onOneServer, driving
  trial-expiry→grace/expire, grace→suspend, and due scheduled-change application via existing actions
  (scope-free bounded scan + per-item tenant context + row lock + redacted failure). Tests:
  `CompleteFirstTimeSetupSubscriptionTest` (7), `Phase20BSchedulerRegistrationTest` (2),
  `ProcessSubscriptionLifecycleTest` (7), `FirstTimeSetupTest` (7 updated) — 245 onboarding+billing pass;
  Pint + Larastan clean.
- **Increment 3 result (domain core, green on PG16):** `BillingIntervalCalculator` (Nairobi, 5 intervals,
  drift-free clamps; 14 tests); `MerchantSubscriptionStateMachine` + `MerchantBillingStatusReason` enum +
  `projectedBillingStatus()` (Gate B2); `ProjectMerchantBillingStatus` (sole transactional projection,
  atomic rollback); 11 lifecycle/scheduled actions (`CreateTrialSubscription` [B1 anchor = founding admin
  membership, idempotent] … `ApplyScheduledPlanChange` [exactly-once, no proration]); `EnsureBillingMutable`
  gate (403 `billing_read_only`, reads only `merchants.billing_status`); Merchant `billing_status` cast +
  `billingBlocksMutations()`; 13 typed AuditEvent cases + severities. Tests: state-machine, lifecycle (14),
  scheduled (7), gate (6), calculator (14) — **full Phase 20B suite 114 pass / 227 assertions**; Pint +
  Larastan L8 clean. Dev DB: all 8 migrations `Ran` (verified via migrate:status; no migrate:fresh on dev).
- **Increment 2 result (green on PG16):** 8 forward-only migrations (5 create + 3 expand:
  `subscription_plan_prices` composite-key unique, `merchants.billing_status`/`billing_status_reason`,
  `invoice_number_sequences` scope); 7 backed enums (6 canonical + `SubscriptionInvoiceItemType`);
  5 models + 5 factories; `TenantOwnership` TENANT_OWNED + MODELS registration; 8 manifest entries.
  Tests: `Phase20BSchemaTest` 46, `Phase20BEnumParityTest` 9, coverage guards 18, billing/phase20a 142 —
  all green. Disposable `servana_p20b_check` `migrate:fresh --seed` (81 migrations) green, dropped, dev
  untouched. Pint clean (1067); Larastan L8 clean; `git diff --check` clean. Failures fixed: Pint FQCN
  docblocks; Larastan `rawSqlConcat` (hardcoded literal CHECKs, parity-guarded); Larastan `missingType`
  (added `@return list<string>`).
- **Exact next action:** Increment 7 — Phase 20B Playwright E2E (dashboard across trialing/active/
  read_only_grace/overdue/suspended + terminal cancelled/expired; next-cycle plan change + cancel +
  no-proration; invoice list/detail + payment-reference-pending; new PDF blocked in read-only vs existing
  downloadable; registration monitoring + governance with reason + fresh step-up; merchant roles denied
  platform routes; no merchant-create/impersonation/payment-Wallet UI) across 360/768/1280 × light/dark +
  keyboard + visible focus + dialog focus restoration + error summaries + status announcements + axe
  serious/critical=0 + no page-level horizontal overflow; then the full local gate battery (regenerate
  contracts; backend validate/pint/stan/test/parallel + targeted guards; frontend lint/typecheck/test/
  build/e2e; security composer/npm audit + gitleaks; sequential docker dev+prod builds) and the SINGLE
  completion commit `phase-20b: implement subscription lifecycle and invoices`. **(Historical planning
  note — superseded.)** Phase 20B reached local completion, was committed (`6790081…`), opened as PR #36,
  passed CI on both the implementation and governance heads, and was squash-merged (`3dd528a…`) on
  2026-07-12; it is now **`verified_complete`** (see the reconciled lifecycle entry at the top of this
  section). Phase 20C then began on branch `phase-20c-promotions-free-periods` off `3dd528a…`.

## Phase 20C — Promotions and Free-Period Offers (verified_complete)

- **Branch / base / lifecycle:** 🚧 `in_progress` on `phase-20c-promotions-free-periods`, created off
  `origin/main` = `3dd528a2779a44d13b9fe105ac9ee49e688e84c6` (the Phase 20B PR #36 squash merge).
  HEAD == merge-base == `3dd528a…` at branch creation; `git fsck` clean. **Proof:**
  [phase-20c.md](proof/phase-20c.md). Single completion commit `phase-20c: implement promotions and
  free periods` at local completion, then push + STOP (no PR/merge, no Phase 20D-W).
- **Phase 20B reconciliation (done, Increment 1):** PR #36 MERGED (squash `3dd528a…`, implementation
  `6790081…`, governance/final head `4a998dc…`, merged 2026-07-12T06:57:28Z; CI initial `29183137798`
  + final `29183286205` five required jobs all SUCCESS; blank reviewDecision = solo-maintainer
  exception, not independent approval; branches deleted). Reconciled PROGRESS/CHANGELOG/proof-20b/
  traceability(SRV-SUBSCRIPTION-001 + SRV-PLATFORM-GOVERNANCE-001)/register(REM-PERM-001 comment) →
  `verified_complete`. No fabricated remediation item.
- **Specification gates (full decision table in proof-20c.md):**
  - **C1** — `effective_from`/`effective_to` (date), **no** `starts_at`; both target tables carry an
    immutable unique `ulid` tie-break key; global candidates have no target row (parent
    `effective_from` then parent `ulid`).
  - **C2** — normalized targets: scopes `all_new_merchants`/`selected_merchants`/`selected_plans`/
    `billing_mode`; types `merchant`/`plan`/`billing_mode`; exactly-one field matching type; no JSON;
    duplicate parent/target forbidden.
  - **C3** — free-period resolves at the Merchant-Admin creation anchor (Gate B1) + setup plan/billing
    mode; promotion resolves at invoice issuance business date; issued invoices never re-resolved.
  - **C4** — forward-only snapshot expands; applied days in `trial_days_snapshot`, applied discount in
    `discount_minor`; no backfill.
  - **C5** — **product-owner decision 2026-07-12: cap at subtotal.** `applied_discount_minor =
    min(configured_fixed_minor, subtotal_minor)`; `total = subtotal − applied` (≥ 0); snapshot both
    configured + applied; no credit/carry-forward/refund; currency matched; percentage uses bps +
    ADR-005; server-side integer minor units inside the atomic `IssueSubscriptionInvoice` txn; issued
    invoices never recalculated.
  - **C6** — reuse Phase 20A platform-config approval (Super-Admin, MFA, fresh step-up, high-severity
    audit); no maker/checker; drafts+targets editable only while draft; approved terms/targets
    immutable; pause/resume = availability only; cancel from draft/scheduled only.
- **Schema/migrations planned (Increment 2):** `2026_07_12_000001..000004` create
  `promotional_discounts`, `promotional_discount_targets`, `free_period_offers`,
  `free_period_offer_targets`; `_000005` adds promotion snapshot columns to `subscription_invoices`;
  `_000006` adds free-period snapshot columns to `merchant_subscriptions`. Parents platform-scoped
  (`TenantOwnership::EXEMPT`, no `merchant_id`/`branch_id`). Data dictionary entries complete in
  `billing-and-wallet.md` (Phase 20C section, column-level).
- **State machines (Increment 1, done):** `promotional-discount.md` (has `draft → active`) +
  `free-period-offer.md` (no `draft → active`; approval → `scheduled` only). Backed enums +
  named services planned for Increment 3.
- **Permissions:** activate `platform.promotion.manage` + `platform.free_period_offer.manage`
  (super_admin, platform scope, MFA + fresh step-up, high severity) with YAML/PHP/DB/TS parity
  (Increment 4). Both exist in the matrix as `planned`.
- **Screens (inventory phase == 20C):** `platform-promotions` (consolidated Super-Admin surface,
  Promotional-discounts + Free-period-offers sections); merchant subscription/invoice surfaces show
  read-only applied snapshots. §27.1 spec + inventory flip in Increment 5.
- **Inherited-and-closed here:** the only prior deferral 20C owns — "promotions and free periods →
  Phase 20C" (deferred by 20A + 20B).
- **Exclusions (owner phases):** Wallet sync/payments → 20D-W (Gate W); %-fee ledger/adjustments/
  disputes → 20E; compensation → 20F; commission/salary → 20G; payouts → 20H; R&E → 21R;
  notifications/reports → 21N; SMS → 21S; search → 22; hardening → 23; performance → 24; deployment →
  25.
- **Increments:** 1 (reconciliation + gates + data dictionary + state machines + traceability) —
  **DONE**; 2 (migrations + enums + models/factories + schema/constraint/parity tests + PG16 fresh
  build) — **DONE + green** (40 schema/parity pass; coverage guards 15 pass; Pint/Larastan clean;
  disposable migrate:fresh --seed green on PG16); 3 (state machines + lifecycle scheduler + resolvers +
  discount calculator + unit/concurrency tests) — **DONE + green** (86 phase20c pass; 2 state machines +
  2 resolvers + calculator + 12 actions + `billing:process-promotion-lifecycle` scheduler + 16 audit
  events; Larastan/Pint clean); 4 (subscription + invoice snapshot integration + permissions/policies/
  requests/resources/controllers/routes + audit + OpenAPI/TS) — **DONE + green** (12 snapshot tests + 12
  API tests; billing regression 362 pass; permissions 92 pass; route-security + audit-coverage green;
  OpenAPI 219 ops + api.ts + contract:check OK; Larastan/Pint clean); 5 (platform frontend + merchant
  read-only snapshot + navigation/inventory/specs + Vitest) — **DONE + green** (Promotions.vue +
  2 stores + 3 specs; nav/inventory live+implemented + §27.1 spec; invoice/subscription resources expose
  read-only snapshots; vue-tsc clean, ESLint 0 err, Vitest 317 pass); 6 (Playwright + responsive/dark/
  keyboard/axe + full local gates + single completion commit + push) — **DONE + green** (Playwright
  phase-20c 18/18 incl. axe light+dark 0, overflow 360/768/1280, 200% zoom, keyboard+focus; backend
  serial 1458/7-skip + parallel 1458/7; Pint 1189 clean; Larastan L8 no errors; composer validate valid;
  OpenAPI 219 ops byte-identical ×2 + api.ts + contract:check + permission-types --check OK; Vitest 317;
  production build ✓; composer audit clean; npm audit 2 moderate below gate; gitleaks no leaks; php
  dev+prod + nginx prod images build). DEF-20C-001 (E2E role-boundary assumption) fixed. Single
  completion commit + push next; then STOP (no PR/merge, no Phase 20D-W).
- **Exact next action:** Increment 6 — author `tests/e2e/phase-20c.spec.ts` (16-point matrix: create
  percentage/fixed promo + free-period offer; merchant/plan/billing-mode targets; approval reason + MFA
  + step-up; scheduled/active/paused rendering; merchant users cannot access platform mgmt; existing
  trial + issued invoice unchanged; new invoice shows applied discount; no Wallet/payment control; no
  overflow 360/768/1280; 200% zoom; keyboard + focus restore; light+dark; axe serious/critical=0), then
  the full local gate battery (contracts determinism, backend serial+parallel, Pint/Larastan,
  composer/npm audit, gitleaks, Docker dev+prod) and the SINGLE completion commit + push (no PR/merge).

## Phase 19 — Audit Logging Completion & Flagged Events (verified_complete)

- **Lifecycle status:** ✅ `verified_complete` — PR **#32** `Phase 19: Complete audit logging and flagged events` MERGED into `main`; merge commit `7ef259e28f51fc9bba24a16ef3945ff61ddef4ce`; merged at `2026-07-05T11:48:45Z`; head branch `phase-19-audit-flagged-events`; base `main`; local and remote Phase 19 branches deleted after merge. Commit lineage: implementation `e8c70d8` → parallel-worker DB-state isolation fix `bfba53d` → Pint import-order fix `46087fe` → governance / final PR head `d6455f3`. CI run `28736716360` (head `d6455f3`): five required checks — Backend (Pint, Larastan, Pest), Frontend (ESLint, vue-tsc, Vitest, build), Docker (build images), Security (gitleaks), E2E (Playwright) — all **SUCCESS**. `reviewDecision` blank under the documented PR-specific solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-32.md`) — **not** independent reviewer approval.
- **Branch / base:** `phase-19-audit-flagged-events` off `64bd0a1` (verified PR #31 merge). Proof: `docs/proof/phase-19.md`.
- **Phase 18B reconciliation (done):** PROGRESS/CHANGELOG/proof-18b/register(REM-PAY-001)/traceability(SRV-PAYMENT-002) → `verified_complete`; PR #31 merge `64bd0a117dcdc819a8baf4b9bec3c3eb09635edc`; initial failed CI `28694148176` preserved, corrected `28695121157`, final `28695314469`; `reviewDecision` blank under solo-maintainer exception (not independent approval).
- **Increment 1 — spec gate + `audit_flagged_events` schema (COMPLETE, green):** data-dictionary `audit-files-notifications.md` (created; was absent) + state-machine `audit-flagged-event.md`; migration `2026_07_04_000001` (branch-owned; `audit_log_id` RESTRICT to append-only source; status/resolution/assignment CHECKs; composite tenant FK; no soft-delete); `AuditFlaggedEventStatus` enum + `AuditFlaggedEvent` model + factories (+ test-only `AuditLogFactory`); `TenantOwnership` + manifest wiring; 6 `AuditEvent` cases (flagged-event workflow + `audit.exported`) severity-mapped. Tests green on PG16: `AuditFlaggedEventSchemaTest` (8), `AuditFlaggedEventStateMachineTest` (4); `AuditEventCoverageTest`/`MigrationManifestTest`/`TenantColumnCoverageTest`/`ModelTenancyTraitCoverageTest` still green; Pint clean, Larastan L8 No errors on new code. Fix: DEF-19-001 (split a schema test to avoid Postgres 25P02 transaction-abort).
- **Increment 2 — flagged-event backend (COMPLETE, green):** state machine + exception + 5 actions (Flag/StartReview/Resolve/Dismiss/Reopen; lockForUpdate, review-metadata-only, typed events, source never mutated) + `AuditFlaggedEventPolicy` + 2 Form Requests + masked `AuditFlaggedEventResource` + controller + 6 routes + `VALIDATION_EXEMPT` + policy registration. Permission reconciliation: legacy `audit.flag` → canonical `audit.flagged_event.create/update_status/resolve_metadata` + `audit.branch_events.view`. OpenAPI 159 ops/136 paths + TS. Tests: Workflow(9)/Isolation(5)/SourceMutationDenial(9) + audit group 75 + auth 142 green; Pint(899)+Larastan L8 clean. Fix: reopen clears assignee/resolver/notes to satisfy the resolution CHECK (history preserved in the audit trail).
- **Increment 3 — masked, domain-segmented audit reads + `audit.view_full` RETIREMENT (COMPLETE, green):** human-authorised source-of-truth correction (Q1 full canonical conformance; Q2 platform-governance-only for merchant-level rows). Legacy catch-all `audit.view_full` **retired entirely** (removed from registry catalogue + all default grants, DB projection via new `PermissionSeeder::prunePermissions()`, `AuditLogPolicy`, routes, `FilePurposeRegistry`, `PermissionMatrixTest`, e2e admin fixture — no alias/fallback). Canonical keys added: `audit.finance.view` + `audit.compensation.view` (Audit), `finance.audit.view` (Finance). New `AuditDomain` enum + `AuditEvent::domain()`/`actionsIn()` drive server-side domain filtering. New endpoints: `GET /audit-logs` (General, `audit.branch_events.view`), `/audit-logs/finance` (`finance.audit.view` **OR** `audit.finance.view` — `EnsurePermission` extended to variadic OR), `/audit-logs/compensation` (`audit.compensation.view`; empty until 20F–20H). All branch-scoped, `branch_id NOT NULL` (merchant-level rows excluded, Q2), masked, ULID-only, allowlisted filters/sorts, bounded pagination. **MA/BM/HR lose all direct raw audit read** (oversight via reports/dashboards). Tests green PG16: new `AuditReadSegmentationTest` (6) + `AuditRedactionTest` (2); rewritten `AuditMaskedReadTest` (5) + `AuditBranchScopeTest` (4); `PermissionMatrixTest` (3). Regression: **audit+auth+permissions groups = 224 pass / 844 assertions**; RouteSecurityContract/OpenApi/RouteBinding/FilePurpose guards green; flagged-event suite green after the OR change. Pint clean (902), Larastan L8 No errors. OpenAPI **138 paths / 161 operations** + TS synced + contract check OK.
- **Merchant-level (`branch_id` null) visibility limitation (recorded, deliberate):** merchant-level structural audit rows have **no** merchant-tier user-facing surface; not exposed to branch-scoped Audit; `platform.audit.view` scope **not broadened**; rows preserved in the immutable chain for governance. MA oversight of those events is via reports/dashboards, not the raw trail. No permission/route invented to close the gap.
- **Increment 4 — audit export: RESOLVED + IMPLEMENTED (green).** Product-owner decision 2026-07-04 (ADR-010) authorized a dedicated branch-scoped `audit_exports` table (the prior Outcome-C blocker). Plan amended (§13 inventory + §13.5 DDL + §80 Phase-19 scope; Phase 23 kept as hardening); data-dictionary entry added; REM-AUDEXP-001 `blocked` → `in_progress`. Implemented: migration `2026_07_04_000002` + `uploaded_files` purpose-expand `..._000003`; `AuditExportStatus`/`AuditExport`/`AuditExportFactory`; `AuditExport{Exception,StateMachine}`; actions `Request`/`Revoke`/`Expire`/`RecordDownload`; `GenerateAuditExport` job + `AuditExportCsvBuilder` (masked, branch-scoped, never merchant-level rows, bounded chunks); `AuditExportPolicy`; `RequestAuditExportRequest`+`AuditExportIndexRequest`; masked `AuditExportResource`; `AuditExportController`; 6 routes (store = audit.export + fresh step-up `StepUpAction::AuditExportCreate` + `EnsureBranchScope` + `BranchMutation`; download-link issues a signed link to the `GET .../download` STREAM which does the atomic accounting); `FilePurpose::AuditExport`; `audit.export` permission (Audit default, in-domain write); AuditEvent reconciliation (retired `audit.exported` → `audit_export.requested/generated/failed/downloaded/expired/revoked`); TenantOwnership+manifest+coverage-registry+policy registrations. **audit-exports group = 31 tests / 122 assertions green** (stable-ULID-before-gen, reason-422, unassigned-branch/foreign-404/wrong-branch denials, branch-null-never-exported, full transitions, idempotent gen, redacted failure, masked CSV, private file, link-no-increment, stream-increments-once, first-once/last-updates, revoked/expired 409, no path/signature/id leak, typed events). Pint clean (932), Larastan L8 clean; OpenAPI **143 paths / 167 ops** + TS + contract check OK.
- **Increment 5 — implemented-mutation audit coverage guard (COMPLETE, green):** new first-class `app/Domain/Audit/Support/AuditMutationCoverage.php` — `AUDITED` maps all 100 emitting mutating `/api/v1` routes → their typed `AuditEvent` action string(s) (from actual emission sites), `EXEMPT` maps the 3 non-emitting mutations with reasons (files.download-link, first-time-setup, service-sessions.notes). `AuditMutationCoverageTest` (5) fails CI on any unmapped/stale/overlapping mutation or unknown event; completeness assertion over the live route table = 504 assertions (all 103 non-GET routes classified). `AuditSeverityCoverageTest` (5) — every AuditEvent has a valid severity + domain, all tiers represented, registry↔enum consistency. 10 passed/504 assertions; Pint clean (905), Larastan L8 No errors. Deferred domains fabricate nothing.
- **Increment 6 — canonical permission matrix + four-way parity (REM-PERM-001) (COMPLETE, green):** created `docs/auth/permission-matrix.yaml` — the source-controlled security contract with all **151** §19.2 canonical keys (70 active + 81 `planned`) **plus** the **17** still-legacy runtime keys (168 rows; **87 active**; legacy rows carry `canonical_successor`+`owning_phase`, reconciled in owning phases per §19.1 — no prior-phase key renamed). New dependency-free `PermissionMatrix` loader (bespoke reader; `symfony/yaml` not added) + deterministic `servana:permission-types` command (composer `permission:types[:check]`) emitting `resources/spa/src/types/generated/permissions.ts` (87 active keys only). **Four-way parity GREEN:** YAML-active == PHP registry == DB projection == TS = 87; retired `audit.view_full` + legacy `audit.flag` absent everywhere; planned keys never project to DB/TS. **MFA closure:** `finance.audit.view` (MFA Y) enforced by `EnsurePrivilegedMfa` (no-assertion Finance → 403 mfa-gate before permission; read route carries no fresh step-up); `audit.export` (SU Y) guarded by `RequireFreshMfa`; `platform.audit.export` metadata-only (planned, no route). Tests: Schema/CatalogueCompleteness/Parity/TypeScriptParity/DatabaseProjection/PerKeyAllow/PerKeyDeny/Override/NonOverridable/MakerChecker/RoleBoundary/MfaCoverage/StepUpCoverage. **Full `tests/Feature/Auth` = 184 pass / 1360 assertions**; Pint clean (947), Larastan L8 No errors. REM-PERM-001 → **`local_complete`** (pending Phase 19 PR CI/review/merge).
- **Increment 6 §2 closure — full Plan-metadata verification (placeholder removal, green):** added `PermissionMatrixPlanMetadataParityTest` (independent §19.3 parse asserts all **151** canonical keys match the Plan on every Plan-encoded field: scope/entitlement_key/billing/period_lock/mfa/step_up/audit_severity/maker_checker/default_roles/override_policy), de-placeholdered `audit_event` (now **derived from live routes + AuditMutationCoverage** for active keys, `none` for reads, honest `pending` for planned — recomputed + asserted independently), assigned `owning_phase` for all planned + legacy keys per §80, and added `PermissionLegacyKeyReconciliationTest` (17 legacy → planned successor|null, valid owning phase, no duplicate authority) + `PermissionPlannedKeyIsolationTest` (81 planned absent from registry/DB/TS/routes/grants). Full `tests/Feature/Auth` **192 pass / 1670 assertions**; Pint clean; Larastan L8 no errors; `servana:permission-types --check` up to date. **REM-PERM-001 remains `local_complete`** — now with the Plan-metadata verification proven, not a residual risk.
- **Increment 7 — scheduled chain verification + bounded failure signal (COMPLETE, green):** registered `audit:verify-chain` in `routes/console.php` (`->daily()->withoutOverlapping()->onOneServer()`; Plan §67/§1610 lists it among singleton scheduler tasks with no pinned sub-daily cadence, so the established daily integrity cadence is used, matching idempotency:prune). New `app/Domain/Audit/Events/AuditChainVerificationFailed` — a readonly, bounded, redacted signal (severity=critical, category broken_link|hash_mismatch, safe chain_identifier platform|merchant:{id}, correlation_id ULID, failed_chain_count, occurred_at ISO); `VerifyAuditChain` emits it **once per failing run** + a matching redacted `Log::critical`, NO payload/context/hash/PII/SQLSTATE/stack. New `AuditChainScheduleTest` (registered/daily/withoutOverlapping/onOneServer) + `AuditChainFailureSignalTest` (no-signal-on-valid, one-signal-on-tamper, broken-link category, full redaction incl. no 64-hex-hash/record-ulid, no-mutation); pre-existing `AuditChainVerificationTest` retained. 11 pass/27 assertions; Pint + Larastan L8 clean. Centralized transport/paging/runbooks remain Phase 25.
- **Increment 8 — Audit-role frontend (IMPLEMENTATION COMPLETE, green; Playwright + gates pending):** built the full Audit-role SPA on the existing shell/router/Pinia/generated-types/nav/design-system — 3 stores (`auditEventStore`/`flaggedEventStore`/`auditExportStore`) + 8 pages (event list/detail, flagged queue/review, finance/compensation audit, export list/detail) + shared `AuditDomainEvents`; 8 routes wired; 5 nav items flipped `planned`→`live` (parity green, no dead links); 8 `implemented` screen-inventory entries + regenerated §27.1 specs + `inventory.yaml` snapshot. Server-derived `can` maps gate all controls; masked reads; source records read-only; export step-up server-enforced; no file_id/paths/signatures exposed; honest compensation empty state. 5 new frontend specs (22 tests: store routing/filters/transition/download-link-non-persistence/polling; component permission-denied-absence/no-branch/capability+state gating). **Gates: vue-tsc clean; full Vitest 59 files/244 tests pass; ESLint 0 errors; build succeeds.** (A tree-wide lint:fix reformatted 9 unrelated pre-existing files — surgically restored to HEAD; tree carries Phase-19 changes only.)
- **Increment 8 COMPLETE (green):** added the Finance-role finance-audit screen (`pages/finance/FinanceAuditView.vue`, route `finance.audit`, nav flipped live, `finance-audit` inventory + spec; reuses `AuditDomainEvents` with `mfaNote`, no endpoint/contract duplication; `finance.audit.view` + server MFA; Audit keeps `audit.finance.view`, no cross-grant; `FinanceAuditView.spec` 4). Parameterised `AuditDomainEvents` with `detailRouteName?`/`mfaNote?`. Playwright `tests/e2e/audit.spec.ts` **25 tests** (reads/masking/immutable-detail/foreign-404; flag→start-review→resolve/dismiss→reopen + invalid-transition + required-notes; finance-audit Audit+Finance+MFA-denied; compensation empty state; export step-up/reason/branch/private-download/count-refresh/revoked/failed-redacted/no-leak; **axe serious+critical = 0** light+dark + **no overflow 360/768/1280** + keyboard). **Full e2e 252 passed.** DEF-19-002 (link `text-primary`→`text-heading` AA contrast) fixed. Vitest 248.
- **Increment 9 COMPLETE (green):** OpenAPI 167 ops + `api:types` + `servana:permission-types` + `--check` + contract OK (143 paths/167 ops); `composer validate` valid; Pint 953 clean; Larastan L8 no errors; **backend serial 1062 pass/7 skip + parallel 1062 pass/7 skip (0 fail)**; `audit:verify-chain` OK; targeted guards 44; frontend typecheck/Vitest 248/lint 0-err/build; composer audit clean, npm audit 2 moderate (below high gate), gitleaks no leaks; Docker php-dev + nginx-prod build. **§9 defect log (Bug Fix Protocol, all root-caused):** (1) `FileStorageBoundaryTest` — audit signed its stream route directly (§65 boundary) → `FileAccessService::signDownloadRoute` (signing stays in the file domain; ADR-010 stream accounting preserved); (2) `FileMigrationManifestTest` — Phase-19 `_000003` uploaded_files ALTER made 3 file migrations → test updated (domain Files, owner ∈ {10F,19}, non-destructive); (3) `AuditExport*` parallel undefined-helper (14) → helpers moved to `tests/Pest.php`; (4) `MerchantSelfRegistration` serial global-count fragility → DELTA assertion (no *new* merchant; product dedup proven by isolated+parallel). All re-green.
- **Phase 19 = `verified_complete`** (was `local_complete`; promoted after PR #32 merge with all five required checks SUCCESS — see the lifecycle-status entry above). **Do not begin Phase 20A until the §1.3 v4 plan-adoption PR is reviewed and merged.**
- **PR/CI/review/merge (final):** PR #32 MERGED (merge commit `7ef259e2`, 2026-07-05T11:48:45Z); two in-PR CI corrections were required and preserved truthfully: `bfba53d` (permission-boundary tests leaked DB state across independent parallel workers — isolated) and `46087fe` (Pint import order); final head `d6455f3` CI run `28736716360` all checks SUCCESS.
- **Work skipped (owner phases):** billing audit emissions → 20A–20E; compensation audit emissions → 20F–20H; notification/report audit → 21N; SMS audit → 21S; final finance/audit export hardening → 23; centralized alert transport/runbooks → 25; search → 22; release-wide security/a11y audit → 23; performance → 24; deployment → 25. No Phase 20/21 business subsystem implemented.
- **Residual risk / handoff:** flagging is branch-scoped (branch_id NOT NULL), matching the branch-scoped Audit role; merchant-level/platform audit rows are outside the Phase 19 flag workflow (documented). Serial run surfaced a pre-existing committed-merchant test-isolation quirk (a prior test leaves a merchant row despite RefreshDatabase); worked around correctly via the delta assertion — a future test-infra hardening could locate the committing test (out of Phase-19 scope; parallel isolation is clean). Frontend E2E drives the real SPA against stubbed API (preview has no backend); genuine masking/scoping/download-accounting/MFA enforcement are proven by the Feature suite. **REM-PERM-001 = `verified_complete`** and **REM-AUDEXP-001 = `verified_complete`** (promoted on the PR #32 merge with green CI); legacy→canonical key reconciliation continues in owning phases 20A–25 per §19.1. **Owner phases (nothing fabricated here):** billing audit emissions → 20A–20E; compensation business-domain emissions → 20F–20H; notification/report emissions → 21N; SMS emissions → 21S; search → 22; final release-wide export hardening + release-wide security/a11y audit → 23; performance → 24; centralized alert transport/runbooks/deployment → 25. **Completed: PR #32 merged (`7ef259e2`); next repository task is the §1.3 v4 plan-adoption PR, then Phase 20A only after that adoption PR is reviewed and merged.**

## Phase 18A — Merchant-Client Payment Recording (verified_complete)

1. **Branch:** `phase-18a-payment-recording` (merged). **2. Base merge:** `6557469` (verified Phase 17 squash merge, PR #29; `git fsck` exit 0, only dangling blobs).
3. **Lifecycle status:** ✅ `verified_complete` — PR **#30** MERGED into `main`, squash merge commit `4a489d0` (`4a489d04156aec8348eda9a968f830da31668c87`, 2026-07-02). Commit lineage: implementation `baa3678` → local-completion documentation `24ae7e8` → CI-correction `aef8d51` → governance / final PR head `0e36641`. CI: initial run `28574550657` FAILED (Backend Pint style in `app/Http/Resources/PaymentRecordingGroupResource.php` — formatting only, no behavior/assertion weakened; E2E payment test previously asserted the page body must not contain the word "validate", but the correct pending-validation copy truthfully states Finance must validate before a receipt exists — corrected to retain the explanatory copy and instead assert no Validate button/action is available to Front Office, preserving the role boundary); corrected-head run `28575564965` SUCCESS (Docker failed once on the same head with no product-code change and passed on rerun); final governance-head run `28576226830` — five required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS. `reviewDecision` intentionally blank under the documented PR-specific solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-30.md`) — **not** independent reviewer approval. **REM-PAY-001 remains truthfully open** (it spans Phase 18A recording and Phase 18B validation/receipts/refunds/cash-up/period-locks; Phase 18A is recorded as verified evidence but the item closes only when Phase 18B merges with green CI).
4. **Phase 17 lifecycle reconciliation (done first):** PR #29 MERGED `6557469` (impl `c0fdd83`, initial CI `28516753439` five checks SUCCESS; governance/final head `3c4e309`, final CI `28517236474` five checks SUCCESS); Phase 17 promoted to `verified_complete` across PROGRESS/CHANGELOG/proof-17/traceability; solo-maintainer governance limitation preserved (reviewDecision blank, not independent approval); REM-PERM-001 kept open (Phase 19).
5. **Proof / specs:** [docs/proof/phase-18a.md](proof/phase-18a.md); data dictionary [invoicing-and-payments.md](architecture/data-dictionary/invoicing-and-payments.md) (Phase 17 `invoicing.md` renamed/consolidated to the Plan-canonical path §13.8); state machine [payment-recording-group.md](architecture/state-machines/payment-recording-group.md).
6. **Runtime verified:** Docker healthy; PHP 8.3.31; Laravel 12.62.0; PostgreSQL 16.14; Redis 7; all Phase 17 migrations Ran.
7. **Specification-gate resolutions (A–F):**
   - **A (invoice source):** record only against `issued` / `partially_paid` invoices; `validated_balance = total_minor − validated_paid_minor` (from the Phase 17 `Invoice::balanceMinor()`); `draft/paid/void_pending/voided/adjusted/refund_pending/adjustment_required` rejected; reuses `FinancialPeriodGuard` + billing gate.
   - **B (`split_payment`):** §41 — group = the split; a single-method payment is a group of one, a split/multi-method payment is one group with multiple component `payment_records` carrying concrete methods; `split_payment` retained in the component `method` CHECK for schema fidelity but **never written as a component method** in 18A (no synthetic component duplicating amounts). Group total = Σ(components).
   - **C (durable duplicate + uniqueness):** partial unique index `payment_reference_checks (merchant_id, method, reference_normalized) WHERE result='unique' AND reference_normalized IS NOT NULL`; the first accepted reference occupies the `unique` slot; `duplicate_suspected` and `override_approved` rows are outside the predicate so every attempt persists; race-safe (one concurrent first-insert wins `unique`, the loser is recorded `duplicate_suspected`); original reference never edited.
   - **D (Finance notification seam):** masked Laravel mail Notification to eligible Finance users (permission-resolved, tenant/branch scoped, idempotent per group event); no full/normalized reference, no unmasked contact; **no Phase 21N `notifications` table**.
   - **E (period-lock/billing):** reuse Phase 17 `FinancialPeriodGuard` + `PeriodLockRepository` (always-open binding) + billing mutation gate; **no `financial_period_locks` table**; locked → 423.
   - **F (cash evidence):** cash records with optional reference and no duplicate check; branch/day cash-up persistence + approval remain Phase 18B (no cash-up table created here).
8. **Migrations (4, forward-only, in manifest):** `2026_07_01_000001_create_payment_recording_groups_table`, `..._000002_create_payment_records_table`, `..._000003_create_payment_allocations_table`, `..._000004_create_payment_reference_checks_table`. Apply on PG16; `invoice_number_sequences` reused (not recreated). Registered in `TenantOwnership` (BRANCH_OWNED + COMPOSITE_CONSISTENCY + MODELS) + manifest.
9. **Columns/constraints/indexes:** groups (ulid, merchant/branch/invoice/maker, total>0, currency CHECK, idempotency_key_id nullable, 7-state status CHECK, timestamp-coherence CHECKs, `UNIQUE(id,merchant_id)`, indexes (merchant,branch)/(branch,status)/(invoice,status)); records (ulid, group/invoice/recorded_by/payer nullable, method CHECK 7, amount>0, currency, reference_normalized `$hidden`, reference_display_encrypted, paid_at, 6-state status, validated_amount nullable, method/reference coherence CHECK, `UNIQUE(id,merchant_id)`, NON-unique (merchant,method,reference_normalized)); allocations (no ulid, amount>0, simple invoice_item FK only — item merchant-consistency deferred to 18B); reference_checks (ulid, result CHECK 3, matched/override coherence CHECKs, **partial unique WHERE result='unique' AND reference_normalized IS NOT NULL**). All composite-merchant FKs verified via `\d`.
10. **Group/component statuses:** group draft/**recorded**/**pending_validation**/validated/rejected/correction_required/reversed (18A reaches recorded→pending_validation only); component always created `pending_validation`.
11. **Method rules:** cash optional-ref/no-dup-check; mpesa_offline required+uppercase+format(`^[A-Z0-9]{8,15}$`)+dup; bank_transfer/card_terminal/voucher/other required+dup; split_payment rejected as a component method (Gate B, app-enforced).
12. **Split/multi-method:** group = the split; ≥1 concrete component; group.total = Σ(components); single currency; no synthetic split_payment component.
13. **Duplicate model:** `PaymentReferenceDuplicateChecker` — pre-check + savepoint-guarded `unique` insert; on unique-violation/existing reservation → durable `duplicate_suspected` (matched record) + group held `recorded` + `409 payment_reference_duplicate_suspected` (masked). Override via `ApproveDuplicatePaymentReference`.
14. **Allocation/locking/pending:** one invoice-level allocation per component (item_id null 18A seam); `PaymentPendingBalanceCalculator` available = (total−validated_paid) − Σ(recorded+pending_validation groups) under the invoice `FOR UPDATE` lock.
15. **Overpayment:** group total > available → `422 payment_overpayment` (rolls back).
16. **Idempotency:** recording + override are `financial_mutation` + R4 `EnsureIdempotentRequest`; missing key 422; replay caches (incl. the 409 hold); reused-key-different-payload 409.
17. **Finance notification:** `NotifyFinanceOfRecordedPayment` → queued masked `FinancePaymentRecordedNotification` (mail) to active Finance in merchant+branch, after commit, none on rollback; no Phase 21N table.
18. **Period/billing:** reuse `FinancialPeriodGuard`/`PeriodLockRepository` (locked→423) + `financial_mutation` billing gate; no `financial_period_locks` table.
19. **Permissions/denials:** FO `customer_payment.record`; Finance `customer_payment.view/.duplicate_override/.record_exception`; BM/MA/HR/Personnel/Audit/Super-Admin denied; reconciled 5 legacy `payments.*` keys + 7 auth tests + regenerated `phase8-matrix.txt`. REM-PERM-001 stays open (Phase 19).
20. **Maker/checker:** `PaymentMakerCheckerGuard` — group maker ≠ override actor (`403 maker_is_checker`); registry keeps `record`⊥`validate` (different roles); exception maker cannot self-clear (proven).
21. **Routes (5):** `POST invoices/{invoice}/payment-recording-groups` (+`/exception`), `POST payment-reference-checks/{paymentReferenceCheck}/override` (financial_mutation + idempotency; override adds `RequireFreshMfa:payment_duplicate_override`), `GET payment-recording-groups` + `/{paymentRecordingGroup}` (branch-scoped read). No validate/reject/receipt/refund/cash-up/status/delete route.
22. **Audit (4):** `customer_payment.recorded`(info)/`.recorded_exception`(high)/`.duplicate_suspected`(warn)/`.duplicate_override_approved`(high) — safe masked context; no success on rollback.
23. **Branch/day-close:** active (recorded/pending_validation) groups reuse the `BranchClosureGuard` seam (documented); no cash-up table.
24. **Frontend:** FO `payments/RecordPaymentEntry.vue` (recordable invoices) + `RecordPayment.vue` (single/split builder, method-aware fields, available/total, confirmation, idempotency, pending-validation success, duplicate warning, no validation/receipt); Finance `PaymentGroupList.vue` + `PaymentGroupDetail.vue` (masked, capability-gated duplicate override); `paymentStore`; nav `front-office.payments`+`finance.payment-records` planned→live; get-started `record-a-payment` deep-linked; inventory (+4) + role-navigation YAML regenerated; OpenAPI(110)/TS regenerated.
25. **Backend tests:** payment group **62** (schema 14, api 29, duplicate/override 10, audit 5, notification 4); auth 142; coverage/contract 22; OpenAPI+parity 14; full parallel **952 pass / 7 skip / 0 fail**.
26. **Frontend tests:** Vitest `RecordPayment.spec` (available amount/record→pending/no-receipt, split total, method-aware reference, overpayment guard, duplicate warning, no validate/receipt) — see item 32.
27. **Playwright:** `tests/e2e/payment.spec.ts` — Linux CI authoritative (local Windows not claimed).
28. **Quality gates:** Pint clean; Larastan L8 No errors (544); OpenAPI deterministic (110 routes) + TS parity; migrate PG16; contract/coverage green. (Docker/gitleaks/browser Linux-CI-authoritative.)
29–33. **Failures/root causes/fixes/reruns:** (a) 18 initial Larastan issues on new code (nullable relation access in the override action + resources, missing iterable types, `Money::formatted`→`format`, CarbonImmutable→`now()` on typed Carbon props) — fixed, re-run clean; (b) full-suite `OpenApiTypeParityTest`×2 failed (stale generated TS after +5 routes) → `composer api:openapi` + `npm run api:types`; a second `OpenApiContractTest` staleness after the `duplicate_checks` resource field → regenerated both; (c) two duplicate-test expectation fixes (override returns 201 fresh-resource; step-up test needed a confirmed credential + stale assertion via `statefulMfa`); `package-lock.json` platform churn reverted twice.
34. **Skipped/owning phase:** validation/rejection/reference-correction/`payment_validation_events`/validated-paid update/invoice payment-state/receipts+numbering/refunds/disputes/cash-up/`financial_period_locks` persistence → 18B; commission → 20G; M-Pesa Daraja → 20D; notifications platform → 21N; full permission closure (REM-PERM-001) → 19; search/audit/perf/deploy → 22/23/24/25.
35. **PR/CI/review/merge (final):** PR **#30** MERGED, squash merge commit `4a489d0`; final governance-head CI run `28576226830` five required checks all SUCCESS (see item 3 for the full commit/run lineage and the corrected initial failures). No new remediation item was created merely because CI passed.
36. **Residual risks:** local Windows Playwright was not claimed at build time (Linux CI is authoritative and passed on merge); concurrency proven via the DB partial-unique + invoice row lock + savepoint (sequential tests + index behavior) rather than true parallel processes; item-level allocation is a nullable 18B seam (now consumed in Phase 18B).
37. **Phase 18B handoff:** consume a `pending_validation` group as the validation unit → one `payment_validation_events` per group, set components validated, increase `invoices.validated_paid_minor`, transition invoice, auto-issue one receipt, earn commission — all atomic, maker≠checker (guard + `record`⊥`validate` preserved).

## Phase 17 — Invoicing (verified_complete)

1. **Branch:** `phase-17-invoicing`. **2. Base merge:** `ffe37cc` (verified Phase 16C merge, PR #28).
3. **Lifecycle status:** ✅ `verified_complete` — PR **#29** `Phase 17: Implement invoicing` MERGED into `main` (squash merge commit `6557469`, 2026-07-01T12:29:08Z; implementation commit `c0fdd83`, governance commit / final PR head `3c4e309`, base `main`). Initial CI run `28516753439` (head `c0fdd83`) — five required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all **SUCCESS**; governance commit `3c4e309` then became the final PR head with final CI run `28517236474` (head `3c4e309`) — same five required checks all **SUCCESS**. `reviewDecision` blank under the documented PR-specific solo-maintainer governance exception — **not** independent reviewer approval. All Phase 17 technical decisions and deferrals below are preserved; **REM-PERM-001 remains open** (Phase 19). **Proof:** [docs/proof/phase-17.md](proof/phase-17.md).
4. **Phase 16C reconciliation (done first):** PR #28 MERGED `ffe37cc` (impl `1d2aee5`, remediations `81506da`+`ac5751a`, final head `79746bb`, final CI run `28449140384` all five checks SUCCESS); promoted to `verified_complete` across PROGRESS/CHANGELOG/proof-16c/traceability + register `last_updated`; both failed E2E runs + both root causes preserved; no new remediation item; REM-PERM-001 kept open (Phase 19).
5. **Proof / specs:** [phase-17.md](proof/phase-17.md); data dictionary [invoicing-and-payments.md](architecture/data-dictionary/invoicing-and-payments.md) (renamed from `invoicing.md` at Phase 18A start — Plan §13.8 canonical path); state machine [invoice.md](architecture/state-machines/invoice.md).
6. **Specification-gate resolutions (A–F):** (A) `invoice_items.service_session_id` NOT NULL composite FK to `service_sessions(id,merchant_id)`; only `completed` sessions invoiceable; one invoice may carry multiple sessions (same merchant/branch/client/currency); `UNIQUE(service_session_id)` prevents duplicate invoicing (re-invoicing a voided session = documented correction workflow, deferred). (B) void/adjust represented with additive `invoices` columns (`previous_status`, `voided_*`, `adjusted_*`, `adjustment_of_invoice_id` self-FK) — non-destructive, snapshots/number never mutated; `paid → refund_pending|adjustment_required` defined+tested but Phase-18B-driven. (C) `FinancialPeriodGuard` + `PeriodLockRepository` contract; Phase 17 binds `UnlockedPeriodLockRepository`; `423 financial_period_locked` proven when a repository reports a lock; Phase 18B swaps persistence. (D) `PreferredPersonnelFeeResolver` — legacy `services.preferred_personnel_fee_minor` when honoured, else none; immutable snapshot; replaceable by Phase 20A. (E) `percentage_fee_config_snapshot` jsonb = null until Phase 20E. (F) `tax_minor`/`discount_minor` retained, integer, default 0, non-negative, no editable control; deferred.
7. **Migrations (3, forward-only, in manifest):** `2026_06_30_000002_create_invoice_number_sequences_table`, `..._000003_create_invoices_table`, `..._000004_create_invoice_items_table`. Apply on PG16; all coverage tests green.
8. **Final columns:** see [invoicing-and-payments.md](architecture/data-dictionary/invoicing-and-payments.md). `invoices` (branch-owned, ULID, 9-state CHECK, additive void/adjust columns, validated_paid_minor Phase-18B-written); `invoice_items` (branch-owned, ULID, service_session_id source); `invoice_number_sequences` (tenant-owned, gap-free counter).
9. **Constraints & indexes:** status CHECK (9 states); uppercase-ISO currency CHECK; non-negative money + `validated_paid <= total`; draft/finalization coherence; total arithmetic coherence; void/void_pending/adjusted coherence; partial `UNIQUE(merchant_id,invoice_number) WHERE invoice_number IS NOT NULL`; `UNIQUE(id,merchant_id)`; `invoice_items` line-total arithmetic + `UNIQUE(service_session_id)`; composite-merchant FKs throughout.
10. **Invoice/session relationship:** one invoice → many items → each item one completed session; multi-session invoices supported.
11. **Duplicate-invoicing rule:** `UNIQUE(service_session_id)` + in-action `FOR UPDATE` pre-check → `409 service_session_already_invoiced`; re-invoicing a voided session deferred (documented correction workflow).
12. **State machine:** `InvoiceStatus` enum + `InvoiceStateMachine`; 9 states; transitions per Plan §25.3; invalid → `422 invalid_state_transition`; no generic `PATCH status`/`mark-paid`. **40 unit tests** cover every valid + invalid pair.
13. **Draft + finalization:** `CreateInvoiceDraft` (validates completed sources, derives price/personnel/preferred-fee under lock, no number) and `FinalizeInvoice` (re-derives under lock, allocates gap-free number `{branch.code}-INV-{padded6}`, snapshots, `draft → issued`, audit). **9 feature tests** prove determinism, snapshot immutability, numbering, preferred-fee fallback, duplicate prevention, double-finalization rejection, and rollback (no number consumed).
14. **Numbering:** `InvoiceNumberAllocator` — `insertOrIgnore` + `lockForUpdate` on the per-merchant sequence row inside the finalization transaction; never `MAX+1`; voided numbers retained.
15. **Money:** integer minor units via `Money` + `InvoiceTotalsCalculator`; total = subtotal + preferred-fee + tax − discount; DB arithmetic CHECK mirrors it.
16. **Draft mutation:** `UpdateInvoiceDraft` (Front Office; draft-only, `422 invalid_state_transition` on issued; clears+re-composes items via shared `InvoiceDraftComposer`; recomputes totals; no number/finalized_at; `invoice.updated_draft` audit).
17. **Finance void/adjust (Gate B, additive/non-destructive):** `RequestInvoiceVoid` (issued|partially_paid → void_pending, previous_status preserved, reason), `ExecuteInvoiceVoid` (void_pending → voided, voided_at/by set, previous_status cleared), `RejectInvoiceVoid` (restores previous_status), `AdjustInvoice` (issued|partially_paid → adjusted, adjusted_at/by/reason). Original snapshots + number never mutated; nothing deleted.
18. **Idempotency:** `FinalizeInvoice` route classified `financial_mutation` + `EnsureIdempotentRequest` (Phase R4 infra); missing key → 422 `idempotency_key_required`; replay returns the stored success (no second number/item/audit; sequence advances once); reused key + different payload → 409. Proven in `InvoiceApiTest`.
19. **Audit events (7, all emitted + tested):** `invoice.created`/`invoice.updated_draft`/`invoice.finalized` (info/notice) + `invoice.void_requested`/`invoice.voided`/`invoice.adjusted` (high) + `invoice.void_rejected` (warning); safe context only (ULIDs, number, integer minor totals, sanitised reason) — no contact/blind-index/raw key/bigint. `AuditEvent` enum + severity match updated.
20. **Permissions:** reconciled the legacy placeholder `invoices.*` keys (which mis-granted invoice creation to Branch Manager + Merchant Admin, violating Plan §10.2) to canonical `invoice.view`/`invoice.create`/`invoice.void.request_or_execute_as_policy`/`invoice.adjustment.manage`. Grants: Front Office view+create; Finance view+void+adjust; MA/BM/Personnel/Audit hold NO invoice key. `PermissionRegistry` + `PermissionMatrixTest` fixture + e2e ADMIN_PERMISSIONS fixture + `FilePurposeRegistry` invoice_pdf perm updated; `phase8-matrix.txt` regenerated. REM-PERM-001 stays open (Phase 19).
21. **Routes (9) + classification:** `GET/POST /api/v1/invoices`, `GET/PATCH /invoices/{invoice}`, `POST .../finalize` (financial_mutation + idempotency + step-up-free), `.../void` + `.../void/execute` + `.../void/reject` (branch_mutation; void request/execute carry `RequireFreshMfa:invoice_void` step-up), `.../adjust` (branch_mutation). All auth+tenant+branch+EnsurePermission; bodiless mutations in `VALIDATION_EXEMPT`. No DELETE/status/mark-paid/payment/receipt route.
22. **HTTP layer:** `InvoicePolicy`, Form Requests (allowlisted bodies), thin `InvoiceController`/`InvoiceVoidController`/`InvoiceAdjustmentController`, masked `InvoiceResource`/`InvoiceItemResource` (ULIDs only; money as {amount,currency,formatted}; state-aware `can`). No authoritative field accepted from the browser.
23. **Billing/period-lock boundary:** invoice routes inherit `EnsureMerchantActive` (suspended → 403). `read_only_grace`/`suspended_billing` billing states arrive with Phase 20B subscriptions (not in the current merchant status enum); Phase 17 documents the handoff and does not fabricate them. Period-lock 423 proven at the HTTP boundary via an injected locked repository.
24. **Frontend:** shared `pages/invoicing/InvoiceList.vue`/`InvoiceCreate.vue`/`InvoiceDetail.vue` (Front Office + Finance; capability-map-gated finalize/void/adjust with confirmation + reason modals + irreversible warnings); `invoiceStore` (idempotent finalize); FO+Finance routes; nav `front-office.invoices`/`finance.invoices` activated (live); get-started `Create an invoice` deep-linked; `types/models` Invoice types; screen inventory (5 new + 1 planned→payment) + role-navigation YAML regenerated.
25. **Backend test totals:** invoicing group **80** — `InvoiceStateMachineTest`+`InvoiceTotalsCalculatorTest` (unit 40), `FinalizeInvoiceTest` (9), `InvoiceApiTest` (15), `InvoiceSchemaTest` (10), `InvoiceCorrectionTest` (6). Coverage/contract green (TenantColumn/ModelTenancy/MigrationManifest/DataDictionary/TenancyStatic 20; PermissionMatrix 3; RouteSecurityContract+FinancialRouteIdempotencyCoverage 13; OpenApiContract+Parity 14; RouteBindingTenantSafety/ForbiddenRouteAbsence/AuthorityBoundaries 12). Full backend suite **892 pass / 7 skip / 0 fail** (non-parallel; 7 skips = ClamAV-profile files tests). Two obsolete 16B/16C `Schema::hasTable('invoices')->toBeFalse()` lifecycle assertions updated to row-level `DB::table('invoices')->count()===0` (the table now legitimately exists; the invariant — queue/session completion creates no invoice — is preserved and strengthened).
26. **Frontend test totals:** Vitest **191** (+ invoice util 2, InvoiceDetail 6); nav + inventory snapshots regenerated. vue-tsc clean; ESLint 0 errors.
27. **Playwright:** `tests/e2e/invoice.spec.ts` (FO create→finalize number-timing, issued read-only, no payment/receipt control, Finance void/adjust + irreversible warning, 360/768/1280 no-overflow, 200% zoom, light+dark axe serious/critical=0) — Linux CI authoritative (local Windows not claimed).
28. **Quality gates:** migrate:fresh --seed on PG16 green; Pint clean (684 files); Larastan L8 **No errors** (501 files); composer validate --strict OK; OpenAPI deterministic (105 routes, byte-identical on regen) + TS parity; vue-tsc/ESLint(0)/Vitest 191/SPA typecheck clean; composer.json valid; npm audit 0 high/critical (2 moderate). Docker images + gitleaks + browser gate are Linux-CI-authoritative.
29. **Explicit deferrals:** payments/records/allocations/reference-checks + recording UI → 18A; validation/receipts/refunds/disputes/cash-up + period-lock persistence & management → 18B; preferred-fee rules → 20A; platform settings/plans/subscriptions → 20A–20B; promotions → 20C; M-Pesa → 20D; percentage-fee ledger/adjustments/disputes → 20E; compensation plans/rules → 20F; earned commission ledger → 20G; payouts → 20H; audit dashboard + REM-PERM-001 → 19; notifications/reports → 21N; SMS → 21S; search → 22; release audits → 23; perf → 24; deploy → 25; queue-linked in-progress abort (unresolved scheduling correction); `read_only_grace`/`suspended_billing` differentiated allowlist → 20B.
30. **Pending PR/CI/review/merge:** ✅ resolved — PR #29 MERGED (squash `6557469`); implementation `c0fdd83` (initial CI `28516753439` all five checks SUCCESS); governance/final head `3c4e309` (final CI `28517236474` all five checks SUCCESS); `reviewDecision` blank under the solo-maintainer governance exception (not independent approval). **Phase 18A: in progress** — see Phase 18A section.

## Phase 16C — Service Sessions and Preferred Personnel (verified_complete)

1. **Branch:** `phase-16c-service-sessions`. **2. Base:** `af79b56` (verified Phase 16B merge, PR #27).
3. **Lifecycle status:** ✅ `verified_complete` — PR **#28** `Phase 16C: Implement service sessions` MERGED into `main` (squash merge commit `ffe37cc`, 2026-06-30; implementation commit `1d2aee5`, final pre-merge governance head `79746bb`). Initial CI run `28445709595` (head `1d2aee5`) **FAILED** E2E — Playwright: ambiguous `My sessions` text locators resolved to multiple elements, failing the accessibility and own-scope browser cases with **no** backend, business-rule, or accessibility-gate relaxation. First remediation `81506da` (`test: disambiguate service session headings`). Second CI run `28446579933` **FAILED** E2E — the Personnel read-only assertion counted every page button rather than the session workflow controls, so global layout controls caused a false failure. Second remediation `ac5751a` (`test: scope personnel session controls assertion`). Corrected CI run `28448569188` (head `ac5751a`) SUCCESS. Final CI run `28449140384` (head `79746bb`) — five required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all **SUCCESS**. `reviewDecision` blank under the documented PR-specific solo-maintainer governance exception — **not** independent reviewer approval. **REM-PERM-001 remains open** (Phase 19). The full local implementation record below is preserved. **Proof:** [docs/proof/phase-16c.md](proof/phase-16c.md).
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
39. **Phase 17 handoff:** invoicing reads completed `service_sessions` (and `invoice_items.service_session_id` references `service_sessions(id,merchant_id)`); the resolved preferred-personnel fee + commission ledger + earned/payable status are Phases 17/20A/20G after validated payment. **Phase 17: in progress** (foundation verified) — see Phase 17 section.

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
