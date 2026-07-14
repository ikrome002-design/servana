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
| 20E | Percentage platform-fee engine (financial; Corrections 2/4/8) | 🧪 `local_complete pending PR CI/review/merge` — branch `phase-20e-percentage-platform-fees` off `735f419…`; single completion commit `phase-20e: implement percentage platform fee engine` pushed. All local gates green (Increment 8): backend serial+parallel 1181/7-skip/7396; Larastan L8 + Pint + composer validate clean; catalogue 160/160/10; OpenAPI 196 paths/235 ops deterministic; Vitest 352; Playwright full 324 (axe 0, 200% zoom, 360/768/1280, light/dark); composer audit clean; npm high gate exit 0; gitleaks clean; docker dev+prod+nginx built. **No PR/merge yet** (product-owner-authorized). Gate W CLOSED (evidence absent) ⇒ 20E was the next executable phase per the v4 dependency graph. See Phase 20E section. |
| 20D-W / 20F–20H | Wallet billing (20D-W, blocked on Gate W), compensation (20F), commission/salary (20G), payouts (20H) | ⬜ Not started |
| 21N / 21S | Queues/notifications/reports / personnel bulk SMS | ⬜ Not started |
| 22 | Search | ⬜ Not started |
| 23 | Security hardening + responsive/dark/a11y release audit + threat-model | ⬜ Not started |
| 24 | Performance optimization | ⬜ Not started |
| 25 | Deployment pipeline & production readiness | ⬜ Not started |

## Phase 20E — Percentage Platform-Fee Engine (in_progress)

- **Lifecycle:** 🧪 `local_complete pending PR CI/review/merge` (Increment 8 gates all green; single completion
  commit pushed; NOT `verified_complete`/`ci_passed`/`merged`/independently reviewed). **Branch:**
  `phase-20e-percentage-platform-fees` off `origin/main` = `735f419bf72fdd9be3f95c4507e8925c1ed0859e`
  (= the Phase 20C PR #37 squash merge); never worked on `main`. **Base verified:** HEAD before commit =
  merge-base = `735f419…`; `git fsck` clean; old `phase-20c*` local + remote branches absent.
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

## Phase 20C — Promotions and Free-Period Offers (in_progress)

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
