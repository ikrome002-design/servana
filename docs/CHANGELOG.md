# Changelog

All notable changes to Servana by Citrus. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); phases now map to the active v4
roadmap (Plan §§79–80), which supersedes the old §27 roadmap.

## [Unreleased]

### Phase 20H — Payout Runs and Earnings (`phase-20h-payout-runs-earnings`) — in_progress

Off `main` = `1879110de6cb1d73ef82403dd7007cca447f8c5c` (the PR #42 dependency-remediation squash merge;
originally branched from the Phase 20G PR #41 squash merge `dcdbfb6…` and **refreshed** onto the
remediated `main` with `git reset --keep origin/main` during Increment 7 — no code churn). Financial
phase (Plan §62/§63/§13.12/§25.4/§25.5/§65/§80; Correction 19.6–19.9). **Consumes** the 20G ledgers
(`salary_ledger`, `commission_ledger`, `compensation_adjustments`) into internal payout workflow +
personnel earnings surfaces. **Servana moves no money** — no Wallet/provider runtime, no
STK/PayBill/Till/C2B/Daraja/callbacks, no settlement; mark-paid records an **external** payment and
never depends on Gate W (CLOSED). Specification-first: H1–H18 resolved before any migration
(`docs/proof/phase-20h.md`).

- **Increment 1 (verification + reconciliation + specification) — in progress.** Verified PR #41
  MERGED (`dcdbfb6` == `origin/main`; five CI checks SUCCESS; solo-maintainer exception, not
  independent approval); **reconciled Phase 20G `local_complete pending PR → verified_complete`**
  (PROGRESS + roadmap row + this file), preserving the governance truth. Gate W confirmed **CLOSED**
  → 20H is the next executable phase. Created branch `phase-20h-payout-runs-earnings` off the verified
  20G merge.
- **Key resolutions:** H6 high-value threshold = existing `merchant_subscriptions.high_value_payout_threshold_minor`
  (Phase 20A) — snapshot source, no new substrate, nothing hardcoded (null ⇒ ordinary approval).
  Tables `personnel_payout_runs`/`personnel_payout_items`/`earnings_queries` (Plan §13.12) + three
  **expand** FKs adding `payout_item_id → personnel_payout_items` to the 20G ledgers. Schema-completion
  D-H3-1 adds `currency` to runs/items (single-currency run) to honour the no-cross-currency
  invariant. Ledger claim at **submit/freeze** (D-H3-2), released on reject/cancel, `paid` at
  mark-paid. Earnings statements reuse the existing 10F `earnings_statement` file purpose (no schema
  change). Earnings-query **respond = Finance** per the authoritative matrix (D-H12-1). 16 planned
  Phase 20H permission keys activate in Increment 5 (active 112→128, planned 56→40 — live count).
- **No blocking conflict; no product-owner decision required.** No migration, no commit, no push, no
  PR yet.
- **Increments 2–4 COMPLETE + green (backend):** schema (6 migrations + 3 expand FKs), enums, models,
  factories, state machines, freeze guard; payout domain (10 actions + eligibility selector + snapshotter
  + 14 audit events; reversal netting verified; no money movement); earnings domain (`PersonnelEarningsReadModel`
  own-scope overview/tabs/history/terms; on-demand idempotent immutable earnings statements via the 10F
  file subsystem; earnings queries with correction-via-`compensation_adjustment` only + `…000007`
  statement-link migration). Pint clean; **Larastan L8 0 errors** throughout; targeted + affected
  regressions green.
- **Increment 5 COMPLETE + green (API surface + generated contracts; no commit):** 16 canonical keys
  activated across YAML/PHP registry/DB/`permissions.ts`/`phase8-matrix.txt` — **active 112→128, planned
  56→40, no legacy retirement** (the Inc1 estimate 113→129 reconciled to the authoritative live count).
  2 new `StepUpAction` cases (`PayoutVerify`, `PayoutHighValueApprove`); `PersonnelPayoutRunPolicy` +
  `EarningsQueryPolicy`; 16 Form Requests (server-owned fields prohibited; `paid_date` not future;
  correction only on resolve); masked Resources (presence-only external ref, source counts not ledger ids,
  integer minor money); 6 thin controllers + `MerchantCompensationSummaryReadModel`; **25 routes** (HR
  branch-mutation draft workflow; Finance/MA financial-mutation verify/approve/reject/mark-paid/high-value +
  query-respond, MFA group + fresh step-up on verify/approve/mark-paid/high-value + Idempotency-Key on
  every financial route; Personnel own-scope reads + statement generation `tenant_mutation` +
  `EnsureBillingMutable`, download via the existing 10F `files.*` endpoints). Audit map extended (12
  mutation routes). OpenAPI **235 paths / 280 operations** (23 new); generated `api.ts`/`permissions.ts`
  regenerated; contract + permission-type checks green; **deterministic (hash-verified) second
  generation.** Gates: Phase20H API 14+11+6; `--group=auth` 76, `--group=compensation` 478,
  `--group=security --group=audit` 205, file/receipt/manifest/tenancy 30; OpenApiContract byte-current.
  Pint clean; **Larastan L8 0 errors (1145 files)**; git diff --check clean. **No frontend, no Wallet/
  provider, no money movement, no notification center, no scheduled report delivery.**
- **Increment 6 COMPLETE + green (frontend; no commit):** 5 screens (HR/Finance payout runs, Merchant
  compensation summary + high-value approval, Personnel earnings/statements/queries, Finance earnings-query
  responder) + 4 Pinia stores typed from generated `api.ts`; routes + 4 nav placeholders flipped live +
  `finance.earnings-queries` added; 4 inventory entries flipped implemented + 1 added; §27.1 specs +
  `inventory.yaml`/`role-navigation.yaml` regenerated. Financial UX: Idempotency-Key mint/reuse/remint;
  step-up-required safe states; float-free money parser; HR never verifies/approves/marks-paid; Finance
  mark-paid records an EXTERNAL payment (moves no money; raw ref never re-displayed); MA never marks-paid;
  Personnel own-scope (no staff selector; authorised signed statement link); responder additive-correction
  only; no Wallet/provider wording. **DEF-20H-001** (authorized contract-truth fix): `paid_at`/
  `responded_at` made genuinely nullable in api.ts (JSON byte-identical; OpenAPI unchanged 235/280).
  Gates: **Vitest 435→481**, ESLint 0 / vue-tsc clean / build PASS; **Playwright 397→416** (responsive
  360/768/1280, 200% zoom, keyboard+Escape, axe serious/critical 0 light+dark; role denial); backend
  contract reruns 76 passed (OpenApiContract byte-current; Phase20H API; route-security; idempotency;
  audit; permission parity; NoDirectProvider; file download); **Pint clean; Larastan L8 0 errors**. No
  frontend Wallet/provider/money-movement/notification/scheduled-report. Lifecycle **in_progress**.
- **Increment 7 gates RUN + green; completion HELD (no commit):** composer validate; Pint 1474; Larastan
  L8 0; backend serial 1663/7skip/0fail + parallel 1663/7/0; disposable PG16.14 proof (90 tables/111
  migrations; all 20H tables/triggers/FKs; no forbidden provider/wallet/notification tables; dropped; dev
  DB intact); contract determinism (byte-stable; checks green 235/280); ESLint 0, vue-tsc clean, Vitest
  481, build PASS; Playwright 416 (axe 0); gitleaks clean (one proof false-positive reworded); Docker
  dev+prod app+prod nginx built. **BLOCKER (product-owner Option 1):** `npm audit --audit-level=high`
  fails on **inherited** advisories (`axios` HIGH GHSA-gcfj-64vw-6mp9 fixed ≥1.18.0 + transitive
  `brace-expansion`/`js-yaml`; composer guzzle medium-only) — `package-lock.json`+`composer.lock`
  byte-identical to origin/main, so origin/main fails the same gate. Per CLAUDE.md §7 the prod axios HIGH
  blocks the commit; remediation is isolated to a separate branch off origin/main. **Phase 20H not
  committed/pushed; dirty tree preserved; lifecycle in_progress.**
- **Increment 7 (resumed) — dependency remediation merged, branch refreshed, closure gates re-run:**
  **PR #42** ("Security: Remediate inherited dependency audit advisories") **MERGED** into `main`
  (squash merge `1879110de6cb1d73ef82403dd7007cca447f8c5c`; head-before-squash `caa7161…`; final CI run
  **29838903181** all SUCCESS; `reviewDecision` blank under
  `docs/governance/solo-maintainer-review-exception-pr-42.md`; changed only `composer.lock`,
  `package-lock.json`, `package.json`, that governance file). Remediation branch deleted local+remote.
  Phase 20H branch **refreshed `dcdbfb6…` → `1879110…`** via `git reset --keep origin/main` (all 139
  Phase 20H dirty entries preserved; PR #42 files absent from the Phase 20H diff). Post-refresh:
  `npm audit --audit-level=high` → **0 vulnerabilities**; `composer audit --locked` → **no advisories**.
  All Increment 7 closure gates **re-ran GREEN** (in-container Composer deps resynced first — guzzle
  7.12.1→7.15.1): composer validate valid; Pint 1474; Larastan L8 0; **backend serial 1663/7/0 +
  parallel 1663/7/0**; disposable **PG16.14** proof (111 migrations/90 tables; all 20H tables/triggers/
  FKs; forbidden-table scan empty; dev DB intact); contracts byte-stable **235 paths/280 operations**
  (permission-types --check + api:contract:check green); ESLint 0; vue-tsc clean; **Vitest 481**; build
  PASS; **Playwright 416/0** (axe serious/critical 0); gitleaks clean; Docker dev+prod app+prod nginx
  built. Two CPU-contention flakes (Vitest forks worker-start; one Phase-18A `payment.spec.ts` load
  timeout) cleared on isolated re-run — no code change. Lifecycle remains **in_progress**; single
  completion commit + branch push follow; no Phase 20H PR yet.
- **Excluded (owners):** Wallet/provider/settlement → 20D-W; scheduled report delivery + notification
  center → 21N; bulk SMS → 21S; search → 22; release-wide audits → 23; performance → 24;
  deployment/alerting/runbooks → 25.

### Phase 20G — Salary Accrual and Commission Processing (`phase-20g-salary-commission-ledgers`) — ✅ verified_complete (PR #41 merged `dcdbfb6`, 2026-07-20; reconciled during Phase 20H Increment 1)

Off `main` = `57dce1031ce10c37977540a0e63b1491d444b877` (the post-20F hardening PR #40 merge). Financial
phase (Plan §60/§61/§13.12/§80; Correction 19). Creates the earned/accrued facts 20F configured:
`salary_ledger`, `commission_ledger`, `compensation_adjustments`, plus the `commission_rule_services`
selected-services substrate (§9.1, product-owner decision). Specification-first: G1–G12 resolved before
any migration (`docs/proof/phase-20g.md`). Two product-owner decisions of record: **Actual/Actual
calendar-day salary proration** (G8) and **build the `commission_rule_services` substrate** (§9.1).
Commission is earned **only** at Finance validation via the pre-built durable idempotent
`commission_handoff_events` outbox; reversals are exact-negative append-only rows; already-paid
reversals become negative compensation adjustments; salary_only plans never earn commission;
daily/hourly/per_shift salary fails closed (no approved attendance source exists). **No Wallet/provider
runtime, no payout runs/items, no earnings/mark-paid — those are 20D-W/20H.** Increments 1–3 complete:
schema (4 migrations + the `suspension_salary_policy` expand `2026_07_17_000005` that shipped 20F
omitted), enums/models/factories/tenancy; and the full domain layer — salary segmenter + proration +
accrual action + `compensation:accrue-salary` scheduler; commission basis resolver + earning consumer of
the `commission_handoff_events` outbox + exact-negative reversals + additive/already-paid adjustments + 2
ledger state machines + 7 typed audit events. Increment-3 tests green (SalaryAccrual 10, SalaryProration 7,
CommissionEarning 10, CommissionReversal 5, CompensationAdjustment 3, StateMachine 2, TenantIsolation 4,
Schema 16, EnumParity 7). **Increment 4 complete: Finance-source integration + reversal semantics.** A
Plan-vs-continuation-prompt conflict on partial-refund reversal was surfaced and resolved **in favour of
the Plan** (ADR-005 / §61 / data-dictionary / state-machine spec): a reversal is the **exact negative of
the original (never recomputed), one reversal per original** — the proportional-partial-recompute
language was withdrawn and is NOT implemented (no schema change). Because there is no immutable item-level
refund attribution, `CommissionHandoffConsumer` reverses a validation event's earned rows **only once the
whole validated allocation is refunded** (cumulative finalized refunds = validated amount, derived from
immutable rows); a partial refund is a valid no-effect consumed event; an impossible over-refund fails
closed (`cumulativeReversalExceedsValidatedAllocation`). Invoice void does **not** invalidate the
validated allocation → no commission reversal (proven, not assumed); pre-validation reject/correction earn
nothing. Increment-4 tests green (CommissionRefundReversal 10, CommissionReversalSeam 2; CommissionEarning
full-refund case updated to a finalized refund). Two committed Phase 20F guards
(`CompensationPlanApiTest`, `CompensationPlanActionTest`) reconciled: the 20G ledger tables now exist, so
they assert **zero rows** written by a 20F flow (20H payout/earnings tables still asserted absent).
Compensation group 362 passed; Payments+Refunds 98; NoDirectProvider+Invoice+20G schema/enum/isolation 95;
manifest+tenancy 15; Pint clean; Larastan L8 0 errors. **Increment 5 complete: permission activation +
Finance liability/adjustment API.** Activated the canonical Finance keys `compensation.liability.view` +
`compensation.adjustment.create` (planned→active across matrix/registry/DB/permissions.ts/phase8-matrix;
active 110→112, planned 58→56; legacy retirement none; Finance-only). New `StepUpAction
::CompensationAdjustmentCreate`. `CompensationLiabilityReadModel` derives per-currency net liabilities
(earned-unpaid balance; salary net = status≠paid, commission net = status∉{paid,cancelled}, + adjustments;
never combines currencies). Route family `compensation/*`: masked liability summary + entries + adjustments
reads (`compensation.liability.view`); `POST compensation/adjustments` is a financial mutation
(`compensation.adjustment.create` + fresh step-up + Idempotency-Key + high-severity `compensation
.adjustment.created` audit; append-only standalone `manual` — the schema forbids a Finance source-linked
row; branch server-derived from the staff). 3 policies, 3 Form Requests (server-owned fields rejected),
2 masked Resources, 2 thin controllers. OpenAPI 211 paths/253 ops (deterministic); api.ts + permissions.ts
regenerated (`--check` green); `api:contract:check` OK; vue-tsc clean. Report definitions seeded in
`docs/reporting/report-catalogue.md` (delivery = 21N). Tests: Phase20GCompensationApiTest 13; auth 196;
route-security/idempotency/audit-coverage/OpenAPI 30; full compensation group 375; Pint clean; Larastan L8
0 errors. **Increment 6 complete: Finance compensation-liability frontend.** ONE new Finance screen —
**Compensation liabilities** (`finance.liabilities` → `/finance/liabilities`, `FinanceLayout`, Finance,
`compensation.liability.view`) reusing the roadmap's reserved nav slot (`planned → live`); it consumes the
**existing** Increment-5 generated contract only — **no backend/route/permission/contract change**
(`OpenApiContractTest` re-run confirms `openapi.json` byte-current). `compensationLiabilityStore` (Pinia,
typed from generated `api.ts`; summary/entries/adjustments/detail reads + `createAdjustment`; filters send
only non-empty declared keys; 403 → forbidden state; **Idempotency-Key minted-on-submit / reused-on-retry /
re-minted-on-change / retired-on-success**; local state written only from server responses; no authoritative
money computed). `CompensationLiabilities.vue` — per-currency summary cards (never combined), filter bar,
paginated liability-entry + adjustment lists with safe detail modals, and a capability-gated Record-adjustment
dialog (**direction selector + positive amount → signed `amount_minor` via a float-free parser**; live
"not a payment" preview; only the four contract fields sent; safe `step_up_required`/period-lock/validation/
forbidden copy; focus restore + status announce). Nav + inventory flipped in **source** files with
`role-navigation.yaml`, `inventory.yaml` and the §27.1 screen spec regenerated by their own tests/generator.
**Selected-services HR UX = State C (§8): the §9.1 `commission_rule_services` API/Resource/OpenAPI contract
was never wired** (substrate exists, earning path consumes it) → **stopped and reported per §8; no
client-only list built**; smallest correction recorded as a tracked follow-up. Gates: ESLint 0 errors,
vue-tsc clean, Vitest **404 → 428** (+24: store 12, component 12), build PASS, affected Playwright
`phase-20g.spec.ts` **18 passed** (axe serious/critical = 0, page + dialog, light + dark; 360/768/1280;
200% zoom; keyboard). Backend contract reruns serial (Phase20GCompensationApiTest 13; permission parity;
RouteSecurity; idempotency; audit mutation + severity; OpenAPI byte-current; NoDirectProvider) **55 passed /
0 failed**. **Increment 6A complete: selected-services contract + HR UX closure** (product-owner authorized
to close the State-C gap before 20G local completion). Wired `selected_service_ulids` into the HR
commission-rule draft API: Store/Update requests (required + ≥1 + distinct for `selected_services`,
prohibited otherwise); `CommissionRuleController` resolves the ULIDs to services **inside the acting
merchant+branch** (foreign/cross-branch → 404 no-leak; archived → 422); `CreateCommissionRuleDraft` /
`UpdateCommissionRuleDraft` persist/replace `CommissionRuleService` memberships **transactionally, draft-only**
(the DB triggers freeze them once the rule leaves draft); `CommissionRule::selectedServices()` relationship;
`CommissionRuleResource` returns `selected_service_ulids: string[]` + `selected_services:[{ulid,name}]`;
`CompensationAdjustmentResource.has_source` cast to a truthful `boolean`. **Product-owner service-options
decision:** HR cannot hold `service.view` (Branch-Manager-only, not grantable — verified), so a NARROW
read-only `GET /commission-rule-service-options` was added, gated by the existing `compensation.plan.view`
(never `service.view`), returning only the acting branch's ACTIVE services as `{ulid,name}` — thin
`CommissionRuleServiceOptionController` + `CompensationSelectableServiceResource`; no new permission, no
matrix change. HR `Compensation.vue` gains a branch-scoped checkbox multi-select (options from that endpoint,
**never** `/services`; ≥1 required; removable named chips; server hydration on edit; clears stale + hides on
`applies_to` change; non-draft read-only line) via `compensationStore.fetchServiceOptions`. Observed but NOT
changed: the Phase-15A HR Service Eligibility page references `/services` though HR lacks `service.view` —
recorded as a separate pre-existing mismatch (no widening). OpenAPI **211/253 → 212/254** (+1 route;
deterministic 2×; `permissions.ts` unchanged). Tests: backend affected **198 passed / 0 failed** serial
(CommissionRuleSelectedServices 17; CommissionRuleServiceOptions 7 incl. Branch-Manager-with-service.view
denied; CommissionRuleApi 36; CommissionEarning; Phase20GSchema/Enum/TenantIsolation; Phase20GCompensationApi
13; OpenApiContract byte-current; RouteSecurity; idempotency; audit; parity) + manifest/tenancy 15; Pint
clean; Larastan L8 0; ESLint 0 errors; vue-tsc clean; **Vitest 435/87** (+7); build PASS; **full Playwright
397 passed** (+11 HR selected-services; axe 0 light+dark; responsive; 200% zoom; keyboard). No new permission,
no `service.view` grant, no new screen, no migration. **Increment 7 complete: full local acceptance + closure.**
composer validate; Pint 1398 clean; Larastan L8 0; **full backend serial 1578 passed / 0 failed / 7 skipped**
and **`--parallel` identical 1578/0/7 (4 processes, isolated worker DBs)**; ESLint 0 errors; vue-tsc clean;
Vitest 435/87; build PASS; **full Playwright 397 passed** (axe 0 light+dark); OpenAPI **212/254** deterministic
(`openapi.json`/`api.ts`/`permissions.ts` byte-identical on 2nd generation); `permission-types --check` +
`api:contract:check` green; composer audit clean; npm audit 2 moderate (below high gate); gitleaks no leaks;
Docker dev app + prod app + prod nginx built. **Disposable PostgreSQL 16.14 proof:** `servana_p20g_i7_proof_*`
— 104 migrations from zero, 87 tables, all 4 Phase 20G tables + `suspension_salary_policy`, append-only +
immutability + selected-services-membership triggers, idempotency uniques, composite-consistency FKs, **0
forbidden payout/earnings/Wallet tables**, dropped, dev DB untouched. One reconciliation fix:
`ServiceSessionCouplingTest.php` stale cross-phase guard (`commission_ledger` table-absence → zero-rows; the
same fix Increment 4 applied to two compensation guards) — test-only, no product change. Scope-purity audit
clean. Phase 20G lifecycle → **local_complete pending PR CI/review/merge** at one Phase 20G completion commit
+ push (no PR created; branch retained; Phase 20H / 20D-W not started).

### Post-Phase-20F Deferred Hardening (`hardening/resource-contracts-and-accessibility-tokens`) — verified_complete (PR #40 merged)

Merged via **PR #40** "Hardening: Fix resource contracts and accessibility tokens" (implementation
`cdcb83fc89d89b2139063ce0c099ec1a84ee7748`; governance/final head `53a595bba86e94521279a2c9258bc349a54bfcfc`;
merge commit `57dce1031ce10c37977540a0e63b1491d444b877` == `origin/main`; merged 2026-07-17T14:43:17Z;
required CI Backend/Frontend/Docker/Security/E2E all SUCCESS; `reviewDecision` blank under the documented
solo-maintainer governance exception, not independent approval; local + remote branches deleted).
Started off `main` = `f4bc664b7ba77476f9db01dcb0ec1a526dc20538` (the Phase 20F PR #39 **squash** merge —
which is why the Phase 20F implementation commit `a42e13e6…` is not an ancestor of `main`). Discharges
the two follow-ups Phase 20F recorded as deferred. **Not a feature phase:** no migrations, no new
permissions, no new API routes, no new money/billing logic, no Phase 20D-W / 20G / 20H work.

**A. Repo-wide nullable Resource/OpenAPI truth sweep.** The audit was re-run rather than inherited:
124 non-comment `?->` lines across 38 of 56 Resources, 110 field assignments published non-null.
**104 confirmed defects fixed across 34 Resource files**; **20 deliberately kept** where null is
unreachable (relations on non-null FKs; `QueueEntryResource`'s `(string)` cast, which yields `''`).
A post-fix audit re-run reports **0** remaining genuine defects.
Every candidate was decided against the model's own `@property` docblock (attributes) or the FK
column's nullability (relations) — no blanket rewrite, and no nullability invented where the
database and resource cannot emit null. The Phase 20E `PlatformFeeConfigurationResource` drift that
20F recorded out of scope is fixed here, as are `FreePeriodOffer`/`PromotionalDiscount`
`targets[]` (a target names exactly one of merchant/plan/billing_mode, so two are always null yet
all three were published required non-null). **Runtime JSON is byte-identical** — `$x?->m()` and
`$x === null ? null : $x->m()` are semantically identical; only the published contract changed, from
a lie to the truth. OpenAPI stays at **207 paths / 248 operations**; generator determinism proven by
three byte-identical passes. New finding beyond 20F's note: a `fn (): ?string` closure **return type
hint does not create nullability** — the explicit ternary must sit inside the closure. The truthful
types exposed exactly **3** `vue-tsc` errors, each a genuine latent bug: `platformFee.ts` pushed
`null` into a terms label (would render a literal "null"), and `PlatformFeeConfigSection.prefill()`
assigned a nullable tier/basis into a `string` form field — now prefilled blank so a legacy null
cannot silently propose a different fee behaviour on edit. Fixed with real null handling: no
non-null assertions, no casts, no null→`''` in any API Resource.

**B. Repo-wide dark-mode heading / warning-badge contrast sweep.** `--color-brand-deep` and
`--color-warning` are deliberately not dark-overridden, so `text-brand-deep` headings on adaptive
surfaces rendered at ~1.07–1.28:1 and `bg-warning/15 text-warning` at ~2.14:1. Of **128**
`text-brand-deep` occurrences, **105 changed** to the adaptive `text-heading` and **23 kept**: 16 sit
on non-adaptive orange/cream backgrounds where brand-deep is the correct CTA/badge foreground
(ADR-009), 3 are comments, and 4 already carry their own `dark:text-text` override and are therefore
proven safe rather than failing. Four occurrences a background heuristic flagged as "probably on
cream" were read individually and **all four proved to be failures** — the `bg-cream` belonged to a
sibling icon div or badge, not the heading. `text-warning` exists **twice in the whole repo**, both
in `StaffList.vue`, fixed to `bg-warning/15 text-text` (the pair 20F proved). `success`/`error`
badges are untouched: their tokens *are* dark-overridden. **`StaffList` had no e2e coverage at
all** — exactly how its warning badges stayed invisible to every axe run; new
`tests/e2e/hardening-accessibility.spec.ts` renders all four membership statuses in light and dark
with axe serious/critical = 0, and a **negative control proves the spec fails on the pre-fix
classes**. No axe rule suppressed or narrowed.

Phase 20F is reconciled to `verified_complete` in this branch, per the established convention that
the next branch reconciles the previous phase. Proof: `docs/proof/post-20f-deferred-hardening.md`.

### Phase 20F — Compensation Plan Setup and Commission Rules (`phase-20f-compensation-plan-commission-rules`) — verified_complete (PR #39 MERGED, squash merge `f4bc664…`, 2026-07-17)

Started off `main` = `c0881993ae0c59536013c9b84e182e5000fa1e11` (the Phase 20E PR #38 merge commit).
**Gate W remains CLOSED** — `docs/integrations/wallet/gate-w-evidence.md`, `docs/integrations/wallet/`,
and `docs/integrations/` are absent (no Collections Slice evidence, sandbox credentials, pinned Wallet
OpenAPI hash, contract suite, STK/C2B/webhook/reconciliation transcripts, or PASS status) ⇒ **20D-W stays
blocked** and Phase 20F, which depends only on merged HR/staff (Phase 8/15B) and Phase 20A
preferred-personnel-fee substrate, is the next executable phase per the v4 dependency graph. No pivot to
20D-W; **no Wallet runtime is introduced**.

Phase 20F implements Plan §59 + §80 (Correction 19; HR) and Scope §12.1–§12.9/§18.3 as **HR/admin
configuration only**: it defines *how personnel will earn* and creates **none** of the earned financial
facts owned by Phase 20G (`salary_ledger`, `commission_ledger`, `compensation_adjustments`, earning at
Finance validation) or Phase 20H (payout runs/items, earnings statements/queries, mark-paid, the Merchant
Administrator compensation summary).

**Increment 1 (specification-first):** Phase 20E reconciled to `verified_complete` (PR #38 MERGED, merge
`c088199…`, implementation `f6e208a…`, governance head `24d1cad…`, merged `2026-07-14T06:19:43Z`, final CI
run `29310753740` five required jobs SUCCESS, `reviewDecision` blank under the documented solo-maintainer
governance exception — **not** independent approval; both 20E branches deleted). Gate W CLOSED recorded.
Specification gates **F1–F10** resolved in `docs/proof/phase-20f.md` before any migration:
**F1** `compensation_model` ∈ `commission_only`/`salary_plus_commission`/`salary_only`, kept separate from
`staff_profiles.employment_type` (Scope §12.2 forbids overloading);
**F2** branch-owned (`merchant_id`+`branch_id`+`staff_profile_id`), one active plan per personnel per branch;
**F3** `effective_from`/nullable `effective_to`, half-open `[from,to)`, gist EXCLUDE partial on
`active`/`scheduled` (adjacent windows legal; draft/superseded/expired/rejected/cancelled never block);
**F4** integer minor units, `percentage_basis_points` XOR `fixed_amount_minor`+`currency`, bp bound 0–10000;
**F5** `commission_rules` is a **sibling** table referenced by `personnel_compensation_plans.commission_rule_id`
(Scope §18.3 decisive) — configuration only, no earned commission;
**F6** `applies_to_preferred_personnel_fee` boolean default `false` = basis-inclusion flag consumed by 20G;
**F7** active/effective monetary terms immutable → supersede-not-edit + history row + audit, prior rules
ended not deleted; **F8** backdated ⇔ `effective_from` < current `Africa/Nairobi` business date → submit →
approve with mandatory reason, impact preview, maker/checker, fresh step-up, **critical** audit;
**F9** activate 8 `compensation.*` keys (HR, branch) and retire legacy `commissions.manage` →
`compensation.plan.update_draft` / `commissions.view` → `compensation.history.view`;
**F10** exactly one Phase 20F frontend surface — **HR Compensation** (`hr.compensation`).

Added the data-dictionary entries for `commission_rules`, `personnel_compensation_plans`, and
`compensation_plan_history`; the `personnel-compensation-plan` state-machine spec (Scope §12.9 statuses
`draft`/`pending_approval`/`scheduled`/`active`/`expired`/`superseded`/`rejected`/`cancelled`); the
traceability row `SRV-COMPENSATION-001`; and the migration plan (manifest entries land in Increment 2 with
the migration files, since `MigrationManifestTest` forbids entries without on-disk files — the Phase 20E
precedent). **No migration in Increment 1.**

**F4 residual (non-blocking):** Scope §12.7 mentions a "configured merchant/platform maximum" commission
percentage, but no such configuration exists in the repository, Plan, or elsewhere in the Scope, and Plan §59
(higher authority) does not require one. Phase 20F enforces the structural bound
`percentage_basis_points BETWEEN 0 AND 10000` per the merged `preferred_personnel_fee_rules` precedent
rather than inventing a settings surface (Phase 20A scope creep). Recorded as a residual.

**Documentation corrections:** `merchant.compensation-summary` was tagged Phase 20F, but its permission
`merchant.compensation_summary.view` is `owning_phase: Phase 20H` in the plan-parity-tested permission
matrix and Plan §80/§63 place that screen in 20H — retagged to **Phase 20H** and **not** built in 20F
(same class of inventory mistag recorded in Phase 20A). The retag was applied at the **sources**
(`resources/spa/src/navigation/roleNavigation.ts` and `docs/frontend/screens/inventory.json`) and
propagated into the generated vitest file snapshots `docs/frontend/navigation/role-navigation.yaml` and
`docs/frontend/screens/inventory.yaml` via `vitest -u`; both fixtures then pass without `-u`. **No
generated file was hand-edited.** The stale `## Phase 20C … (in_progress)` PROGRESS heading was corrected
to `verified_complete` to match the roadmap table and PR #37 evidence.
**Increment 2 (schema):** three forward-only migrations in FK order — `commission_rules` →
`personnel_compensation_plans` → `compensation_plan_history` (`2026_07_14_000001..000003`) — plus 7 enums,
3 models, 3 factories, and `TenantOwnership` registration (all three **branch-owned**:
`BRANCH_OWNED` + `MODELS` + `COMPOSITE_CONSISTENCY`). PostgreSQL is the authority, not the application:
F1 model-shape CHECK (`commission_only` ⇒ no salary + rule required; `salary_only` ⇒ salary required +
`commission_rule_id` NULL — the DB guarantee behind Plan §80's named "salary-only has no commission rule"
test; `salary_plus_commission` ⇒ both), F4 value-shape CHECK (percentage XOR fixed, integer minor units,
`0..10000` bp), F8 maker/checker CHECK (`approved_by <> submitted_by`) + approval/status CHECK so an
unapproved backdated change can never reach an effective state, composite FKs to `merchant_branches` /
`staff_profiles` / `commission_rules` / `personnel_compensation_plans` (ADR-002), an F3 partial
`EXCLUDE USING gist (branch_id, staff_profile_id, daterange(effective_from, effective_to, '[)'))
WHERE status IN ('active','scheduled')` (one active plan per personnel per branch; adjacent windows legal;
draft/pending/terminal never block), 2 supersede-aware immutability triggers (only `active → superseded`
may close an OPEN-ENDED window; every other non-draft term edit raises) and 2 append-only history triggers.
Gates: `Phase20FSchemaTest` 73, `Phase20FEnumParityTest` 15, manifest 9, tenancy 23, **full suite 1269
passed / 7 skipped / 0 failed**, Pint 1285, Larastan L8 clean, disposable PG16 proof (`servana_p20f_proof`,
16.14, 99 migrations, 3/3 tables, 0 forbidden ledger/payout tables, 0/0/0 rows, dropped).
**F1 finding:** `staff_profiles.employment_type` already contains `commission_only` — identical to
`CompensationModel::CommissionOnly`. This is exactly the collision F1 anticipates: the shared label does
**not** make the fields interchangeable. The parity test now pins the overlap to exactly
`['commission_only']` and proves neither column's CHECK accepts the other's exclusive values.

**Increment 3 (domain):** `PersonnelCompensationPlanStateMachine` + `CommissionRuleStateMachine`
(each implements **exactly** the nine accepted arrows; all 55 other pairs of the 8×8 matrix are rejected →
`422 invalid_state_transition`), 12 domain actions, 3 resolvers, a deterministic impact preview, 6 typed
exceptions, an append-only history writer, and **19 typed audit events** that populate the previously
empty `AuditDomain::Compensation` (`audit.compensation.view`) read segment reserved by Phase 19.
Backdated approval (F8) requires fresh step-up + maker/checker + reason + impact preview and emits
**CRITICAL** `compensation.plan.backdated_change_approved`; ordinary approval is **high**. **Supersede is
a consequence, not a permission**: approving/activating a successor closes the incumbent
(`active → superseded`, `effective_to` := successor's `effective_from`) in the SAME transaction, adjacent
not overlapping, with the incumbent's terms byte-identical afterwards; a previously active rule is
**ended, not deleted**. Commission rules have **no independent lifecycle** — every non-draft transition is
driven by the referencing plan inside that plan's transaction, and is skipped when another non-terminal
plan still depends on the rule. Resolvers return configuration only and **fail closed**
(`effective_plan_conflict` rather than guessing; `salary_only` → **null always**;
`effective_commission_rule_missing` rather than a silent "no commission").

**Activation history-event correction (product-owner authorized):** the accepted data dictionary omitted
`activated` from the `compensation_plan_history.event` CHECK while the accepted state machine defines
`scheduled → active` and already lists the symmetric `expired` — a **documentation omission** that
propagated into the Increment 2 CHECK. Resolved by editing the **still-uncommitted** Increment 2 migration
(`git log --all` proves it was never committed on any branch; Guardrail 12 and the manifest header scope
forward-only to **shipped** migrations, and a create-then-expand pair inside one never-merged PR would
permanently pollute `main`), with the **full Increment 2 schema proof + disposable PG16 proof rerun**.
Enum, DB CHECK, both state-machine specs and the data dictionary are now in parity.

Increment 3 gates: state machines 18, actions 50, resolvers 24, audit/scope/parity 37, schema+manifest+
provider+tenancy 97, Pint 1317 clean, Larastan L8 clean (1014 files), disposable PG16 rerun green with
`activated` in the CHECK. `docs/api/openapi.json` was regenerated because the **pre-existing** Phase 19
audit-read endpoints document the `AuditEvent` action enum — paths/operations stay at **196/235**
(identical to the 20E baseline) and the only compensation path remains the pre-existing
`/api/v1/audit-logs/compensation`. **No Phase 20F route, controller, Form Request, Resource, policy, or
frontend exists yet** (Increments 4–5).

**Pre-existing defect surfaced and fixed (not a Phase 20F regression): `CashUpWorkflowTest` had a
~3-hour-per-day red window in CI.** The Phase 18B test helper `cashUpComponent()` set
`paid_at => CarbonImmutable::now('Africa/Nairobi')` — a Nairobi **wall-clock**. `paid_at` is cast
`datetime`, so Laravel drops the `+03:00` offset and PostgreSQL reads the naive string in the UTC
session; `CashUpExpectedTotalCalculator`'s `(paid_at AT TIME ZONE 'Africa/Nairobi')::date` then adds
+03:00 **again**, landing the row on tomorrow's business date and collapsing `expected_minor` to 0.
It fires only when the suite runs between **21:00–23:59 Nairobi (18:00–20:59 UTC)**, which is why the
Increment 2 suite was green (1269/0) earlier the same day and the Increment 3 runs were not. A direct
probe proved the double conversion. **Production is correct and untouched** — the real recording path
(`PaymentRecordingGroupController` → `CarbonImmutable::now()`) stores the instant; only the helper and
one refund `finalized_at` (whose neighbouring `approved_at` already used `now()`) passed a wall-clock.
Both now use `CarbonImmutable::now()`, matching production and making the tests time-independent. **No
production financial calculation, constraint, or timezone rule was changed.**

**Increment 4 (permissions, API, security wiring, contracts):** the Phase 20F permission flip landed
atomically — **8 canonical keys activated** (`compensation.plan.view/create/update_draft/submit/
approve/reject/cancel` + `compensation.history.view`; HR-only, branch scope) and the **2 legacy keys
retired outright** (`commissions.manage → compensation.plan.update_draft`;
`commissions.view → compensation.history.view`) with no alias and no compatibility grant, the Phase 19
`audit.view_full` precedent. Counts moved **active 104 → 110, planned 66 → 58, legacy-active ratchet
10 → 8** (arithmetic proven from the repository, not asserted). Because the successor of
`commissions.view` is HR-only, its old **merchant_admin / branch_manager / personnel / audit** grants
and the **finance** grantable override are all retired rather than carried over (Plan §10.2: the
Merchant Administrator never configures commissions); no broad replacement key was added — Finance's
`compensation.liability.view` (20G), the Merchant Administrator's `merchant.compensation_summary.view`
(20H) and Personnel's `earnings.*` (20H) stay PLANNED, and Audit keeps reading the domain through the
masked `audit.compensation.view` that 20F now populates. `phase8-matrix.txt` (regenerated by
`PermissionMatrixTest`) shows a single ✓ in the `hr` column for all 8 keys.

**API (11 new paths / 13 new operations, all `branch_mutation`, HR-only):** `commission-rules`
index/store/show + `/draft` PATCH; `compensation-plans` index/store/show + `/draft` PATCH +
`/submit`, `/approve`, `/reject`, `/cancel` POST + `/history` GET. **No DELETE, no generic status
route, no manual supersede route** (supersede stays a consequence of approval), no ledger/payout/
earnings/summary route, no platform or provider context. Approve carries
`RequireFreshMfa:compensation_backdated_change` — the §18 designated *compensation* step-up action
that already existed, now a live route (removed from the harness `businessActions()` per the
BillingConfiguration/InvoiceVoid precedent and proven on the real route instead). Missing AND stale
assertions both 403 `step_up_required`; the submitter approving their own plan gets 403
`maker_checker_violation`; a backdated approval without an acknowledged, SERVER-BUILT impact preview
fails closed with 422 `backdated_approval_requires_impact_preview`. The preview is never accepted
from the client. Server-owned fields (status, is_backdated, merchant/branch, actors, timestamps,
ulid, supersedes_plan_id) are all proven to come from the server.

**Idempotency (reasoned, recorded):** these are configuration mutations that create no money fact, so
they are `branch_mutation` and carry no idempotency key — the repository only requires it for
`financial_mutation`. Replay is safe by construction: `pending_approval` is the only legal source for
approve, so a replayed approve is `422 invalid_state_transition`, never a duplicate history/audit row,
and the DB EXCLUDE blocks a duplicate active window regardless.

**Audit:** all 8 mutating routes mapped in `AuditMutationCoverage`, including the same-transaction
rule events and the incumbent supersede (approve maps to five events). The matrix `audit_event`
metadata is **re-derived from the live route table** by `PermissionMatrixPlanMetadataParityTest`, so
it is repository evidence rather than a hand-written claim. Backdated approval proven **critical** at
the API level; a denied approval writes no success audit.

**Contracts:** OpenAPI **196 → 207 paths / 235 → 248 operations**; `api:contract:check` OK;
`permissions.ts` + `api.ts` regenerated through their generators only (never hand-edited); a second
regeneration of all three produced **identical hashes** (determinism proven). Gates: Phase 20F suite
**290 passed**, full backend **1469 passed / 7 skipped / 0 failed**, Vitest **352/352**, vue-tsc clean,
Pint 1334, Larastan L8 clean (1029 files). **No frontend view, Pinia store, Vitest UI spec or
Playwright spec was written — those are Increment 5.**

**Increment 5 (HR Compensation frontend, generated-contract integration, browser proof):** exactly one
Phase 20F screen — **HR Compensation** (`hr.compensation` → `/hr/compensation`, `BranchLayout`,
`compensation.plan.view`, HR, branch-scoped), hosted under the existing HR/branch shell. The nav item
flipped `planned → live` in `roleNavigation.ts` and the inventory entry flipped `planned → implemented`
in `inventory.json`; `role-navigation.yaml`, `inventory.yaml` and the §27.1 screen spec
(`docs/frontend/screens/hr/hr-compensation.md`) were regenerated through their own generators/tests —
**no generated file was hand-edited**. The nav-fixture regeneration also flushed a pre-existing
Increment-1 drift (`merchant.compensation-summary` fixture said `Phase 20F`; the source already said
`Phase 20H`). `router/guards.ts::requiresPermission` had **no caller** in the SPA before this increment;
`hr.compensation` is its first use — UX only, the API stays the security boundary.

`stores/compensationStore.ts` (Pinia) types every response from the **generated** `api.ts` and exposes
one named transition per verb (`submit`/`approve`/`reject`/`cancel`) — **no generic status setter and no
supersede action**; a test proves no `supersede` URL is ever constructed. Local state is written **only**
from the server's response, so a refused submit/approve (validation, forbidden, `step_up_required`,
`maker_checker_violation`, `invalid_state_transition`, `compensation_plan_overlap`) can never leave the
screen showing a state the backend did not grant. `$reset()` + a `branchIds` watch drop stale
branch/tenant data. **No authoritative money is computed in JavaScript** — integer minor units in,
display formatting out.

The screen provides list (+status/staff/model filters), detail with the append-only history rendered
verbatim, the commission-rule draft form (percentage XOR fixed+currency, 0–10000 bp, category only when
`applies_to = service_category`, preferred-fee **basis-inclusion** toggle), the plan draft form (F1 model
shape: `salary_only` forbids a rule, `commission_only` forbids salary, `salary_plus_commission` requires
both), and the four transitions each behind a mandatory reason. Approval states the fresh-step-up
requirement and surfaces a stale step-up without faking success or storing any step-up secret; submit
states the maker/checker rule up front; a **backdated** plan shows a warning + impact-preview summary and
blocks approval until the acknowledgement is ticked, sending `acknowledge_impact_preview: true` so the
**server** builds and records the authoritative preview (never composed or sent client-side).

**Contract-truth fix (DEF-20F-015):** `vue-tsc` proved the published contract lied — the OpenAPI
generator infers nullability from an explicit `=== null ? null : …` ternary but **not** through the
nullsafe `?->` operator, so `effective_to`, `approved_at`, `submitted_at`, `rejected_at`,
`salary_period`, `from_status` and `occurred_at` were published as non-nullable `string` while the
server genuinely returns `null` (`PreferredPersonnelFeeRuleResource` writes the same fields with the
ternary and was already correct). Converted only the genuinely-nullable Phase 20F resource fields —
**no runtime behaviour change, the emitted JSON is byte-identical** — and regenerated; path/operation
counts unchanged at **207/248**, confirming a schema-nullability-only change. The identical Phase 20E
drift in `PlatformFeeConfigurationResource` is recorded as **out of scope, not fixed here**.

**No Merchant-Administrator compensation summary, no Branch-Manager compensation configuration, no
Personnel earnings statement, no Finance liability screen, no commission/salary ledger UI, no payout UI
and no Wallet/provider UI was built** — and this is enforced rather than asserted: the store test fails
on any action key matching `earning|payout|ledger|liability|settle|wallet|accrual`, the component test
fails if the rendered screen contains `earned|payout|ledger|payable|settled|wallet|settlement`, and the
E2E fails if a `Supersede`/`Payout`/`Earnings`/`Ledger`/`Liability`/`Wallet` control exists. Gates:
ESLint **0 errors** (new files add no warning), vue-tsc **clean** (strictness not lowered, no ignores),
Vitest **352 → 404** (+52; `compensationStore` 20, `Compensation` 32), `api:contract:check` **OK
207/248**, `npm run build` PASS.

**Accessibility corrections (DEF-20F-019, serious / WCAG 2 AA 1.4.3).** `--color-brand-deep` and
`--color-warning` are deliberately **not** dark-overridden (brand-deep is the CTA-text-on-orange
colour, ADR-009), so using them as foregrounds on backgrounds that *do* adapt renders dark-on-dark
(1.07–1.28:1) or amber-on-white (2.14:1). Fixed HR Compensation's own headings and status badges, and
the shared `SvModal` title — the latter a **pre-existing** defect that Phase 20F's dialogs are simply
the first to axe-scan in dark mode, fixed here only because §16 forbids suppressing the rule and the
**full** Playwright suite (364) proves no regression to any other dialog. The `draft` badge correctly
**keeps** `bg-cream text-brand-deep` (cream has no dark override either). The axe *coverage* gap that
hid this was fixed too: the suite now renders all five status badges and scans all three dialogs in
both themes — which caught the `Scheduled` badge on the next run. **No axe rule was suppressed.**

**Increment 6 (full local gates, documentation finalization, scope-purity audit, completion commit):**
no new feature work. Two real defects discovered during Phase 20F are recorded as **deferred
follow-ups** rather than executed here — each is repo-wide, touches unrelated domains, and would fold
foreign cleanup into the Phase 20F completion commit, breaking one-phase/one-reviewed-branch
(CLAUDE.md §5) and this diff's reviewability. Each needs its own product-owner-authorized branch/PR
after Phase 20F local completion.

1. **Repo-wide nullable Resource/OpenAPI truth sweep — deferred.** Phase 20F corrected the Phase 20F
   Resources required for HR Compensation; a broader sweep affects many Resources and generated
   frontend consumers across unrelated domains. Evidence: the generator infers nullability from an
   explicit `$x === null ? null : …` ternary but **not** reliably from `?->`. Known example:
   `PlatformFeeConfigurationResource` (Phase 20E). A read-only Increment 6 audit measured the blast
   radius — **92 nullable Resource expressions across 29 Resources**, every one declared
   `"type": "string"` *and* `required: true` while the server can emit `null`; correcting them would
   change `api.ts` for unrelated SPA consumers and likely cascade into `vue-tsc` failures elsewhere.
   **0 of 92 were fixed in this branch**, `PlatformFeeConfigurationResource` explicitly included —
   fixing only it would still fold a Phase 20E change into the Phase 20F commit while leaving the
   other 88 wrong. **Not a Phase 20F blocker:** the Phase 20F screen, OpenAPI, `api.ts`, vue-tsc and
   contract gates are green.
2. **Repo-wide dark-mode heading/warning-badge contrast sweep — deferred.** Phase 20F corrected HR
   Compensation and the shared `SvModal` issue its dialogs depended on; a broader token audit affects
   unrelated screens and needs dedicated axe coverage. Evidence: `text-brand-deep` fails on adaptive
   dark surfaces; `text-warning` fails as a small badge foreground on light surfaces; some existing
   tests never render every badge/dialog state. A blanket replace would be **wrong** — `text-brand-deep`
   is correct on orange/cream backgrounds. **Not a Phase 20F blocker:** HR Compensation has axe
   serious/critical = 0 and the full Playwright suite passed.

**Increment 6 gates (every gate re-run, none inherited):** composer validate OK; Pint **1334 PASS**;
Larastan L8 **no errors** (1029 files); **full backend serial 1469 passed / 7 skipped / 0 failed /
8644 assertions** and **`--parallel` (4 processes) identical 1469/7/0**; ESLint **0 errors**; vue-tsc
clean; Vitest **404/84 files**; `npm run build` PASS; **full Playwright 364 passed**; axe
serious/critical **0** across the list and all three dialogs in light + dark; OpenAPI **207 paths /
248 operations**, `api:contract:check` OK, `servana:permission-types --check` up to date, and
**generator determinism proven by identical SHA-256 hashes across the baseline plus two full
regeneration passes** (openapi.json / api.ts / permissions.ts); `composer audit --locked` clean;
`npm audit --audit-level=high` 2 moderate (exit 0, below gate); gitleaks **no leaks** (16.88 MB);
Docker dev app + prod app + prod nginx all built. **Disposable PostgreSQL 16 proof re-run** on a
throwaway database (`servana_p20f_i6_proof`, PG 16.14): 99 migrations, 3 Phase 20F migrations, 3/3
tables, 1 EXCLUDE constraint, 4 triggers, 0/0/0 rows after seed, **0 forbidden 20G/20H tables**,
database dropped and the dev database untouched. **Scope-purity audit:** every changed path maps to an
authorized category, and `PlatformFeeConfigurationResource.php`, `StaffList.vue`, every other
unrelated Resource, all ledger/payout/earnings/Wallet surfaces and all build artifacts are verified
**absent** from the diff. One Phase 20F completion commit on top of `c088199…`; branch pushed. **No PR
created, no merge, branch retained.**

### Phase 20E — Percentage Platform-Fee Engine (`phase-20e-percentage-platform-fees`) — verified_complete (PR #38 MERGED, merge `c088199…`, 2026-07-14)

Started off `main` = `735f419…` (Phase 20C PR #37 squash merge); Gate W CLOSED (evidence absent) ⇒ 20E
is the next executable phase per the v4 dependency graph. Increment 1 (specification-first): Phase 20C
reconciled to `verified_complete`; Gate W status recorded; specification gates E1–E9 resolved in
`docs/proof/phase-20e.md` (ledger lifecycle = Plan §13.10 canonical `earned`/`pending`→`aggregated`→
`invoiced` with additive reversal/adjustment and `settled` excluded to 20D-W; fee created at Finance
validation; fee-basis vocabulary fixed; `split_tier→shared` tier mapping; legacy `platform_fees.*`
reconciliation planned). Increments 2–4 delivered the four tables + enums/models/factories/DB guards,
the config state machine + resolvers + integer arithmetic engine, and the P17 finalization + P18B
validation-billability integration. **Increment 5** adds: (5A) `AggregatePlatformFeesIntoSubscriptionInvoice`
folding the earned/pending rollup into the P20B `IssueSubscriptionInvoice` transaction as a single
`platform_fee_rollup` line (no second invoice aggregate; folded at issuance because the subscription
invoice requires a plan/price and is immutable once issued), with a forward-only DB cycle guard
(`2026_07_13_000008` partial-unique rollup-per-invoice), Africa/Nairobi period `[start,end)`, deterministic
`billable_at,ulid` order, and `pending→aggregated→invoiced` via a new `PlatformFeeLedgerEntryStateMachine`;
(5B) additive `RecordPlatformFeeReversal` / `RecordPlatformFeeAdjustment` hooked into `ExecuteInvoiceVoid`
and `FinalizeRefund` — append-only ledger + signed `platform_fee_adjustments` evidence, the original
earned amount never edited, 409 over-reversal, source-event idempotency, period-lock + maker/checker
inherited from the host actions; (5C) the canonical dispute workflow (`Create/Start/Resolve/Reject`
actions on `open→under_review→resolved|rejected`; a money-changing resolution creates a
`dispute_resolution` adjustment and never rewrites the ledger amount or the issued subscription invoice);
and 8 Finance audit events (`platform_fee.aggregated/invoiced/reversed/adjusted/dispute_*`). No
Wallet/provider/payment runtime is introduced. **Increment 6** adds the HTTP surface + canonical permission
reconciliation (recovered without file loss after a 2026-07-13 desktop reboot — 104 recovery files
hash-match the external backup): 16 routes (7 Super-Admin platform-fee configuration actions
`create/update-draft/approve/supersede/cancel/list/show`; 3 merchant masked scoped reads
`platform-fees[/summary/{entry}]`; 6 disputes `create/list/show/review/resolve/reject`) — each thin
controller → Form Request → policy → platform/tenant context → MFA/fresh step-up/period-lock/idempotency →
existing transactional action → masked ULID-only Resource; no reversal/adjustment/aggregation/status/DELETE
or Wallet/provider routes. Five configuration audit events
(`platform_fee.configuration_created/updated/approved/superseded/cancelled`, High severity). **Permission
reconciliation (Gate E9; product-owner decision Option A, 2026-07-13):** the legacy plural
`platform_fees.view`/`platform_fees.dispute` are retired and their canonical successors —
`platform_fee.view`, `platform_fee.dispute`, `platform_fee.dispute.review`, `platform.platform_fee.configure`
— are authorized into the Plan §19.2 canonical catalogue **and** §19.3 populated matrix (156→160 keys),
reconciled atomically across YAML/`PermissionRegistry`/DB projection/generated `permissions.ts`/policies/
routes/`PermissionMatrixTest`/`phase8-matrix.txt`. The merchant-side dispute model is retained (NOT moved to
the Phase 20D-W `platform.billing_reconciliation.*` Wallet domain). Legacy-active ratchet 12→**10**
(`PermissionLegacyKeyReconciliationTest`, not weakened). OpenAPI (235 operations) + generated TypeScript API
+ permission metadata regenerated deterministically. `REM-PERM-001` remains open (Phase 19-owned). Gates:
Increment-6 API 30, full Billing 455, auth/matrix 19, security/audit/tenancy/boundary 53, affected
regression 167, frontend vue-tsc/ESLint(0 err)/Vitest 321/build ✓, Pint + Larastan L8 clean. Frontend
screens + E2E are Increment 7.

**Backend closure — future-cycle correction aggregation** completes the one remaining Phase 20E financial
gap after Increment 6: a pending `reversal`/`adjustment` correction of an already-billed platform fee is now
swept into a single signed `subscription_invoice_items.type='adjustment'` line on the merchant's next issued
subscription invoice, so the append-only ledger and the subscription-billing projection stay consistent.
`AggregatePlatformFeesIntoSubscriptionInvoice` gains `collectApplicableCorrections()` + `writeCorrectionLine()`
(new value object `PlatformFeeCorrectionSelection`), wired into the existing `IssueSubscriptionInvoice`
transaction. Each correction's signed contribution is its paired `platform_fee_adjustments.amount_minor`
(never recomputed); consumed corrections transition `pending → aggregated → invoiced`. A correction is swept
only when its ORIGINAL earned entry was already invoiced (a correction of a never-invoiced original is
skipped — no spurious credit). The applied negative net is capped so the invoice total can never go negative
(DB `subscription_invoices.total_minor >= 0`); any un-applied correction stays `pending` and carries to a
later cycle (whole-entry carry-forward — an immutable row is never split; no Wallet credit — that is Phase
20D-W). No migration, no route or OpenAPI change; audit reuses `platform_fee.aggregated`/`platform_fee.invoiced`.
Gates: `PlatformFeeCorrectionAggregationTest` 8/46; full Billing 463; Invoicing+Refunds+Audit+NoDirectProvider+
Tenancy 83; Pint clean; Larastan L8 clean; `composer validate --strict` valid.

**Increment 7** delivers the Vue 3 + Pinia frontend for all six roles (backend stays authoritative — every
control is UX-gated by `useCan()` only; amounts are server-formatted, never recomputed in the browser; the
canonical `shared` tier label is shown, never `split_tier`; no Wallet/settlement UI). Three typed stores
(`platformFeeConfigStore`, `platformFeeStore`, `platformFeeDisputeStore`). Super-Administrator configuration
is a new **Platform fees** tab in `BillingSettings.vue` (`PlatformFeeConfigSection.vue`): list + create /
edit-draft / approve / supersede / cancel, with approved terms rendered read-only (supersede offered, no
edit control) and client validation mirroring the server (integer basis points, shared-split-required,
`validated_paid_amount` customer-centric-only, effective coherence, required change reason). Merchant
Administrator, Branch Manager, Finance and Audit share one server-scoped `pages/billing/PlatformFees.vue`
(summary cards, fee-entry list/detail, disputes): Merchant/Finance raise disputes, Finance-only start-review
/ resolve (optional signed money change → additive adjustment) / reject, Branch/Audit read-only. Front
Office sees a client-facing platform-fee line in `InvoiceDetail.vue` when the server returns a positive
`platform_fee_client_shifted`. A §23 contract fix added that masked field to the merchant-client
`InvoiceResource` (OpenAPI + `api.ts` regenerated; Invoicing Feature tests green); the shared `SvModal`
gained internal scrolling for tall dialogs. Screen inventory + navigation updated and regenerated. Gates:
Vitest 352 (18 new specs); Playwright `phase-20e` 14 (responsive 360/768/1280, 200% zoom, keyboard/focus,
axe serious/critical = 0 in light and dark); vue-tsc clean; ESLint 0 errors; production build ✓;
`api:contract:check` OK; `permission-types --check` clean; backend platform-fee API 30. Frontend only — no
backend financial logic or migration changed beyond the read-only Resource field.

**Increment 8** is the whole-phase local acceptance sweep and the single completion commit `phase-20e:
implement percentage platform fee engine` (on top of `735f419…`; pushed, **not** PR'd/merged). All gates
green on PostgreSQL 16 / PHP 8.3.32 / Laravel 12.62.0: `composer validate` valid, Pint 1266 files, Larastan
**level 8** no errors; isolated disposable-DB `migrate:fresh --seed` (96 migrations, 8 Phase 20E, 4 tables,
dropped after verification); backend serial **1181 pass / 7 skip / 7396 assertions** and parallel identical
(4 processes); Phase 20E targeted 138; permission catalogue **§19.2 = 160 / §19.3 = 160 / legacy-active =
10** (plural `platform_fees.*` retired, four canonical singular keys authorized); OpenAPI/`api.ts`/
`permissions.ts` deterministic (identical SHA-256 across two regen runs, no git diff), `api:contract:check`
OK — **196 paths / 235 operations**; ESLint 0 errors, vue-tsc clean, Vitest **352 / 82 files**, production
build ✓; Playwright affected 77 + full **324 / 0 fail** (Phase 20E 360/768/1280 + 200% zoom + keyboard/focus
+ axe serious/critical = 0 light and dark); `composer audit --locked` no advisories, `npm audit
--audit-level=high` exit 0 (2 moderate dev-only `js-yaml`@`@redocly/openapi-core`, disclosed), gitleaks no
leaks; Docker `servana-app:dev` + `servana-app:prod` + `servana-nginx:prod` all built. Scope-purity clean —
no Wallet/provider runtime introduced. No gate failed on first execution; nothing disabled, loosened, or
suppressed. Lifecycle: ✅ `verified_complete` — reconciled during Phase 20F Increment 1. **PR #38**
"Phase 20E: Implement percentage platform fee engine" MERGED into `main`: merge commit
`c0881993ae0c59536013c9b84e182e5000fa1e11`, implementation commit `f6e208a90513bf5ca1c219c456b263ea0d111c5c`,
governance / final PR head `24d1cad60539fe40596125240391c48a1b821246`, merged `2026-07-14T06:19:43Z`.
Final CI run `29310753740` — five required jobs (Backend, Frontend, Docker, Security, E2E — Playwright) all
SUCCESS. `reviewDecision` **blank** under the documented solo-maintainer governance exception — **not**
independent reviewer approval. Local + remote `phase-20e-percentage-platform-fees` branches deleted.

### Phase 20C — Promotions and Free-Period Offers (`phase-20c-promotions-free-periods`) — verified_complete (PR #37 merged `735f419…`, 2026-07-12)

Platform-governed promotional discounts and free-period (trial-length) offers (Plan §53; Correction 2).
Four platform-scoped tables (`promotional_discounts`, `promotional_discount_targets`,
`free_period_offers`, `free_period_offer_targets`) with **explicit normalized target rows** (no JSON),
immutable target ULIDs (deterministic tie-break), value/currency + scope↔target + approval/status DB
CHECKs, and forward-only snapshot expands on the Phase 20B `subscription_invoices` +
`merchant_subscriptions` tables (shipped 20B migrations untouched). Two backed state machines
(promotion allows `draft→active`; free-period does not — approval yields `scheduled`), a daily
`billing:process-promotion-lifecycle` scheduler (activate/expire, idempotent, row-locked), deterministic
resolvers (precedence merchant > plan > billing_mode > global, ties by latest `effective_from` then
target ULID), and `CalculatePromotionalDiscount` (percentage basis points + ADR-005 round-half-up;
fixed **capped at subtotal** — Gate C5 product-owner decision — never negative, never a credit). The
resolvers + calculator are wired into `CreateTrialSubscription` (free-period days snapshot at the
founding-admin anchor) and `IssueSubscriptionInvoice` (promotion snapshot on new invoices only); existing
trials and issued invoices are never re-resolved or recalculated. Super-Administrator platform API (16
routes) under `ResolvePlatformContext` + MFA + fresh step-up + `platform_mutation`, activated
`platform.promotion.manage` + `platform.free_period_offer.manage` (four-way parity), high-severity audit
for every mutation, a consolidated Super-Administrator promotions screen, and merchant read-only applied
snapshots. Gate decisions C1–C6 recorded in `docs/proof/phase-20c.md` (C5 by the narrow cap-vs-reject
product-owner question). No Wallet/provider/payment runtime, no percentage-fee ledger, no destructive
edits — those remain Phase 20D-W/20E and beyond.

### Phase 20B — Subscription Lifecycle and Subscription Invoices (`phase-20b-subscription-lifecycle-invoices`) — verified_complete

_Reconciled to `verified_complete` during Phase 20C Increment 1: **PR #36** "Phase 20B: Implement
subscription lifecycle and invoices" MERGED into `main` (squash merge
`3dd528a2779a44d13b9fe105ac9ee49e688e84c6`, implementation head
`6790081bace7efb2a659ec8254e6eda53d3d5935`, governance/final PR head
`4a998dc6e4c0f8259c8d6c179c076f8b8496aec9`, merged 2026-07-12T06:57:28Z); CI initial `29183137798`
+ final `29183286205` — five required jobs (Backend, Frontend, Docker, Security, E2E — Playwright)
all SUCCESS; `reviewDecision` blank under the documented solo-maintainer governance exception — **not**
independent approval; local + remote branches deleted after merge._

_**Increment 7 (E2E + full local gates — green):** `tests/e2e/phase-20b.spec.ts` (23 tests) drives the
real frontend against stubbed `/me` + `/api/v1` across the subscription/billing states, no-proration
plan change + cancel, invoice detail + payment-reference-pending, new-PDF-blocked-in-read-only vs
existing-PDF-downloadable, registration monitoring, merchant governance with mandatory reason + fresh
step-up, reactivation that never clears a billing suspension, forbidden-UI absence, and the merchant
role denied the platform route — with axe serious/critical = 0 (light + dark) on the dashboard and the
governance dialog, no page-level horizontal overflow at 360/768/1280, and dialog focus management +
restoration. Full local gate battery green: contracts (OpenAPI 203 operations, api.ts, permissions.ts,
contract check) clean; `composer validate --strict` valid; Pint + Larastan L8 clean; backend
`php artisan test` 1348 pass / 7 skip / 0 fail (serial and parallel); frontend ESLint 0 errors, vue-tsc
clean, 308 Vitest tests, production build, full Playwright 292 pass; `composer audit` no advisories,
`npm audit` clean at the high gate (two moderate dev-dependency advisories reported truthfully),
gitleaks no leaks; Docker dev + prod images build. Phase 20B is local_complete pending PR CI/review/
merge — not verified_complete._


_**Increment 1 (specs):** Phase 20A reconciled to `verified_complete`; specification-gate decision table
(B1–B5) with the Gate B2 product-owner decision (terminal `cancelled`/`expired` → `suspended_billing`,
distinct reasons); column-level data-dictionary entries for the five 20B tables; five state-machine specs;
traceability rows `SRV-SUBSCRIPTION-001` + `SRV-PLATFORM-GOVERNANCE-001`._

_**Increment 6 (frontend — green):** four Phase 20B screens on the existing layouts/router/Pinia/
design-system, driven by the regenerated generated API + permission types, server `can` maps and
structured errors (UX only; the backend remains authoritative). Merchant Administrator:
`SubscriptionDashboard` (subscription status + INDEPENDENT billing status, current plan/price, billing
interval, trial + current-period dates, scheduled-change + latest-invoice summaries, billing read-only
explanation); `PlanManagement` (available plans + effective prices, schedule/cancel a NO-PRORATION
next-cycle change with a server-computed effective date — no client-supplied/immediate change; mutation
controls removed in billing read-only; structured 409/422 surfaced); `SubscriptionInvoices` (list/detail
with the exact payment-reference-pending copy, Generate PDF as a mutation blocked in billing read-only vs
Download existing PDF as a read allowed in read-only; no Wallet/STK/PayBill-Till/provider/payment UI).
Super Administrator: `RegistrationMonitoring` (consolidated tabs — registration monitoring + merchant
directory/detail with operational and billing status shown separately + suspend/reactivate/deactivate via
a mandatory-reason modal with confirmation, fresh-step-up surfacing, and focus restoration; governance
controls gated by the server `can` map; no merchant-create/first-admin/impersonation/payment/Wallet UI).
Three generated-typed Pinia stores; navigation (`roleNavigation.ts` + `role-navigation.yaml`) five items
planned→live; inventory (`inventory.json` + `.yaml`) four screens planned→implemented + four regenerated
§27.1 specs. Gates: ESLint 0 errors, vue-tsc clean, 308 Vitest tests pass (7 new specs), production
build green._

_**Increment 5 (permissions + merchant & platform-governance APIs, atomic — green):** activated the nine
canonical §19.2 keys — merchant self-service `merchant.subscription.{view,plan_change,invoice.view,
invoice.download}` (merchant_admin) and platform governance `platform.{registration_monitor.view,
merchant.view,merchant.suspend,merchant.reactivate,merchant.deactivate}` (super_admin) — and RETIRED the
two dead legacy keys: `merchant.tier.update` (successor `merchant.subscription.plan_change`; the unused
`MerchantPolicy::updateTier()` deleted) and `platform.merchants.govern`, whose blunt single authority is
**truthfully split** (no 1:1 successor) into suspend/reactivate/deactivate. Verified counts: active
93→100, planned 77→68, legacy-active 14→12 (four-way YAML↔PHP↔DB↔TS parity re-proven). New merchant
subscription API (subscription dashboard, plan options with effective prices, scheduled-change read +
schedule/cancel with `EnsureBillingMutable` + server-computed `effective_at` + no proration, invoice
list/detail, new-PDF generation blocked in billing read-only, existing-PDF download-link allowed in
read-only via the Phase 10F `FileAccessService`). New platform merchant-governance API (registration
monitoring, merchant list/detail, and `SuspendMerchant`/`ReactivateMerchant`/`DeactivateMerchant` under
`ResolvePlatformContext` + mandatory MFA + fresh `merchant_governance` step-up + mandatory reason). The
governance mutations change `merchants.status` only — never `merchants.billing_status`, never a
subscription/payment row — and operational reactivation is not a billing-recovery path. Three typed audit
events `merchant.{suspended,reactivated,deactivated}` (high/high/critical) with redacted platform-chain
context; `AuditMutationCoverage`/`AuditSeverityCoverage`/`RouteSecurityContract`/`FinancialRouteIdempotency`
guards extended and green. OpenAPI (203 operations) + `api.ts` + `permissions.ts` regenerated (contract
checks clean; generated files never hand-edited). Full backend suite 1350 pass / 7 skip / 0 fail; Pint +
Larastan L8 clean. No merchant-create/first-admin/impersonation/payment/Wallet route._

_**Increment 3 (lifecycle domain core, green on PG16):** canonical `Africa/Nairobi` `BillingIntervalCalculator`
(five intervals, drift-free end-of-month/leap clamping — the sole subscription date-math source); merchant-
subscription state machine + `ProjectMerchantBillingStatus` (the sole transactional subscription→
`merchants.billing_status` projection, Gate B2 terminal mapping to `suspended_billing` with distinct
`subscription_cancelled`/`subscription_expired` reasons, atomic rollback); eleven named lifecycle/scheduled
actions (`CreateTrialSubscription` with the Gate B1 founding-admin trial anchor + idempotent replay …
`ApplyScheduledPlanChange` with exactly-once, no-proration cycle advance); `EnsureBillingMutable` gate
(403 `billing_read_only`, reads only `merchants.billing_status`, replacing the temporary Phase 17/10F seam
intent); thirteen typed subscription/billing audit events. 114 Phase 20B tests pass; Pint + Larastan L8 clean.
First-time-setup now selects an active plan + effective price (`subscription_plan_ulid`/
`subscription_plan_price_ulid`; `ResolveSetupPlanPrice` validates active/belongs/effective) and binds the
trial subscription atomically as part of completed onboarding (rollback on failure; no completed merchant
without a subscription). A daily `Africa/Nairobi` `billing:process-subscription-lifecycle` scheduler
(withoutOverlapping/onOneServer; bounded scope-free scan + per-item tenant context + row lock + redacted
failure) drives trial-expiry → read-only-grace/expiry, trial-grace expiry → suspension, and due scheduled
plan-change application via the existing named actions. 245 onboarding+billing tests pass; Pint + Larastan
L8 clean._

_**Increment 2 (schema, green on PG16):** eight forward-only migrations — five merchant-owned tables
(`merchant_subscriptions`, `scheduled_plan_changes`, `subscription_invoices`, `subscription_invoice_items`,
`billing_escalation_events`) plus three additive expands (`subscription_plan_prices` composite-key unique
for DB-level price↔plan consistency; `merchants.billing_status`/`billing_status_reason`; `invoice_number_
sequences` scope `subscription_invoice`). Seven backed enums with DB-CHECK parity. Five models + factories;
`TenantOwnership` registration (merchant-owned, not platform-exempt). Gate B3 numbering, Gate B4 escalation
idempotency (`UNIQUE(merchant_subscription_id, event_type, period_boundary)`), Gate B5 fail-closed and
ADR-014 Wallet-null coherence enforced in the schema. Ships nullable Wallet projection columns only — no
Wallet runtime, outbox, client, or table. Tests: schema 46, enum-parity 9, coverage guards 18, no billing/
phase20a regression (142). Disposable `migrate:fresh --seed` green; Pint + Larastan L8 clean._

_**Increment 4 (invoice financial core, green on PG16):** subscription-invoice state machine (`void`-only
terminal); `AllocateSubscriptionInvoiceNumber` (row-locked independent `subscription_invoice` sequence
scope, Gate B3); `IssueSubscriptionInvoice` (Gate B5 fail-closed for non-fixed billing modes — no invoice/
item/sequence/audit; single immutable `plan_fee` line = captured price; discount 0; Wallet columns null/
unregistered — no Wallet runtime; idempotent per period); `VoidSubscriptionInvoice`,
`MarkSubscriptionInvoiceOverdue`, and append-only `RecordBillingEscalationEvent` (idempotent per
subscription/event/period-boundary, Gate B4). Invoice + line-item immutability guards; nine new
invoice/escalation audit events. **Invoice PDF:** `GenerateSubscriptionInvoicePdf` renders via the
dependency-free `MinimalPdf` writer (no library added to the pinned stack) into the Phase 10F private-file
domain (purpose `billing_invoice_pdf`; migration adds `subscription_invoices.file_id`/`pdf_version`);
new generation is blocked in `read_only_grace`/`suspended_billing` (403 `billing_read_only`, driven by
`Merchant::billingBlocksMutations()`) while existing PDFs stay downloadable; unregistered invoices render
exactly "Payment reference pending — see your billing dashboard" (no fabricated reference); regeneration
writes a new file version and revokes the prior; one `subscription_invoice.pdf_generated` audit per
version. Reaffirmed Plan §49 + ADR-014: Phase 20B ships only nullable Wallet projection columns — no
Wallet outbox intent/table/consumer, `RegisterInvoicePayment` action, or Wallet API call. 253 billing
tests pass; Pint + Larastan L8 clean; disposable PG16 fresh-build (82 migrations) green._

### Phase 20A — Plan Catalogue, Prices, Entitlements, Billing Settings (`phase-20a-billing-catalogue-settings`) — verified_complete (PR #35 merged)

_Reconciled to `verified_complete` during Phase 20B Increment 1: **PR #35** MERGED into `main` (squash
merge `6813690ef5fa9f7d782532b49e2bca43c2afc112`, impl head `a31cd00…`, final PR head `56a81bd…`,
merged 2026-07-11 07:56:09Z); five-gate CI (Backend/Frontend/Docker/Security/E2E—Playwright) all
SUCCESS; `reviewDecision` blank under the documented solo-maintainer governance exception, not
independent approval._


_**Increments 5–7 complete (frontend + E2E + full gates).** Delivered the single genuine platform
screen `platform-billing-settings` (route `platform.billing-settings`, `PlatformAdminLayout`): one
coherent accessible tabbed surface for general settings, billing settings (three canonical billing
modes), subscription plans (non-price metadata; retire preserves history), effective-dated plan prices
(five intervals; overlap-rejected; only future cancellable; current/historical read-only), plan
entitlements (enable/disable/limit; no merchant-subscription binding), and preferred-personnel fee rules
(fixed⇔percentage mutually exclusive; platform-default/service scope; supersede-not-edit; approve/cancel).
Added 5 generated-type-backed Pinia stores, `Idempotency-Key` on effective-dated creates, server-enforced
MFA/step-up UX, integer-minor-unit money. Branch-Manager **effective** fee shown read-only inside the
existing `branch.services` catalogue (`BranchPreferredFeeCard`; no new top-level screen). Navigation
reconciled (four platform labels → the one live route; `platform.merchants`/`registration-monitoring`
retagged `20A→20B`, kept planned); inventory entry → `implemented` with route + full §27.1 spec
`platform-billing-settings.md`. **Contract-truth fix:** `SubscriptionPlanPriceResource.effective_to`,
`PreferredPersonnelFeeRuleResource.effective_to`/`approved_at` (+ branch resource) made explicitly
nullable so the OpenAPI generator (which does not follow `?->`) emits `["string","null"]`; regenerated
`openapi.json` + `api.ts`. **Tests:** Vitest **279** (+31: 5 store specs + host + fee-section);
Playwright `tests/e2e/phase-20a-billing.spec.ts` **17/17** + full e2e **269** (axe serious/critical=0
light+dark; no overflow 360/768/1280; keyboard tablist). **Gates:** backend serial **1164 pass / 7 skip
/ 0 fail** + parallel 1164/7; Pint 1040 clean; Larastan L8 no errors (784); OpenAPI 188 routes / 157
paths / 188 ops + TS + permission-types + contract all current; composer audit clean; npm audit 2
moderate (below high gate); gitleaks clean; php-dev + nginx-prod images build. Lifecycle:
`local_complete pending PR CI/review/merge` — **not** `verified_complete`._

_Prior increments:_

_Branch off `origin/main` `85bd3e5` (v4 adoption **PR #34** merged — Gate A satisfied). **Increment 1
(spec-first gate) complete:** `billing-and-wallet.md` data dictionary expanded with full column-level
DDL for `platform_billing_settings`, `subscription_plans`, `subscription_plan_prices`,
`plan_entitlements`, `preferred_personnel_fee_rules` (CHECKs, `btree_gist` EXCLUDE ranges, FKs RESTRICT,
ULIDs, immutability, locking, prospective legacy backfill, audit, retention, factories, tests); state
machines `preferred-personnel-fee-rule.md`, `plan-price.md`, `platform-billing-settings.md`,
`subscription-plan.md`; canonical enums `BillingMode`(3)/`BillingInterval`(5). **Source-of-truth
reconciliation:** `inventory.json`/`inventory.yaml` entries `platform-registration-monitoring` and
`plan-management` retagged **Phase 20A → 20B** to match Plan §47 + the permission matrix (their perms are
`owning_phase: Phase 20B`; both depend on `merchant_subscriptions`). **Increment 4 (backend + API) complete:** 13 typed AuditEvent cases; 2 state machines; 12 named
actions; 6 policies; 8 Form Requests; 6 masked Resources; 7 controllers; `ResolvePlatformContext`
(platform-staff-only context — `platform_mutation` forbids `ResolveTenantContext`, Plan §24.1);
`PlatformServiceLocator`; 21 platform routes + 1 branch read; `AuditMutationCoverage` +12;
`StepUpAction::BillingConfiguration` promoted to a live platform-config step-up; permissions +9
canonical −3 legacy (active 87→93, legacy-active 17→14, planned 86→77); `openapi.json` 188 ops +
`api.ts` + `permissions.ts` regenerated; `Phase20APlatformApiTest` (13). Auth suite 192, billing+
phase20a 96, RouteSecurityContract/AuditMutationCoverage/OpenApi green, Pint + Larastan clean.
Frontend/E2E/final-gates (Increments 5–7) pending. The Increment-1-time Docker/PG16
blocker (engine HTTP 500) was **resolved by a product-owner Docker Desktop + WSL restart** (external
backup taken; no HEAD/branch change, no commit, no discard) and re-verified on resume: engine + PG16
healthy, Laravel 12.62.0, `migrate:status` OK, all 11 Increment-1 paths preserved, `git diff --check`
passes, Increment 1 re-verified (enums 3/5 values, Pint 956, vue-tsc clean, screenInventory 8/8). The
fixed legacy preferred-fee cutover date is **`2026-07-10`** (product-owner decision — immutable
`DATE '2026-07-10'`, never `now()`/`today()`/`CURRENT_DATE`). Increment 2 (migrations + parity) in
progress. Nothing committed. Proof: `docs/proof/phase-20a.md`._

### v4 Plan Adoption — Architecture change (Plan §1.3; branch `docs/update-servana-development-plan`) — verified_complete (PR #34 MERGED, merge `85bd3e5`)

_Lands ADR-012–015, `billing-and-wallet.md` and `refer-earn-integration.md`, SUP-06 permission
renames (+ five Wallet/R&E planned keys → 156 canonical catalogue), `NoDirectProviderIntegrationTest`,
CLAUDE.md v4 alignment, lifecycle reconciliation (REM-WALLET-001, REM-RE-001, REM-MPESA superseded,
REM-AUDEXP-001 verified_complete), and traceability rows SRV-WAL-001 / SRV-RE-001
(`architecture_adopted`). **No Phase 20 runtime code.** Proof: `docs/proof/plan-adoption-v4.md`._

### Phase 19 — Audit Logging Completion & Flagged Events (`phase-19-audit-flagged-events`) — verified_complete (PR #32 MERGED, merge `7ef259e2`)

_PR **#32** `Phase 19: Complete audit logging and flagged events` MERGED into `main`, merge commit
`7ef259e28f51fc9bba24a16ef3945ff61ddef4ce`, merged at `2026-07-05T11:48:45Z` (head branch
`phase-19-audit-flagged-events`, base `main`; implementation `e8c70d8`, parallel-worker DB-state
isolation fix `bfba53d`, Pint import-order fix `46087fe`, governance / final PR head `d6455f3`).
CI run `28736716360`: five required checks (Backend — Pint/Larastan/Pest, Frontend —
ESLint/vue-tsc/Vitest/build, Docker — build images, Security — gitleaks, E2E — Playwright) all
**SUCCESS**. `reviewDecision` blank under the documented PR-specific solo-maintainer governance
exception (`docs/governance/solo-maintainer-review-exception-pr-32.md`) — **not** independent
reviewer approval. Local and remote Phase 19 branches deleted after merge. **REM-PERM-001** and
**REM-AUDEXP-001** promoted to `verified_complete` on this merge._

_Increments 8–9 landed and green (not committed/merged): the Finance-role finance-audit screen
(`pages/finance/FinanceAuditView.vue`, route `finance.audit`, nav live, reusing the shared
`AuditDomainEvents` panel with an MFA-required note; `finance.audit.view` + server MFA; no endpoint or
transport-contract duplication; Audit keeps `audit.finance.view`), the Playwright suite
`tests/e2e/audit.spec.ts` (25 tests — masked branch-scoped reads, immutable detail, flag→review→
resolve/dismiss→reopen with invalid-transition + required-notes, finance-audit for both roles incl.
MFA-denied, compensation empty state, and the export step-up/private-download/count-refresh/revoke/
expiry/failed-redacted/no-leak flows) with axe serious+critical = 0 (light + dark) and no page-level
overflow at 360/768/1280 plus keyboard reachability, and the full Increment-9 gate run. A link-contrast
axe failure (DEF-19-002, `text-primary`→`text-heading`) was fixed. The first full backend run surfaced
four latent failures — the audit controller signing its stream route outside the file domain (fixed via
`FileAccessService::signDownloadRoute`, preserving ADR-010 stream accounting), a Files-migration-count
assumption (updated for the Phase-19 uploaded_files ALTER), file-local audit-export test helpers invisible
to parallel workers (moved to `tests/Pest.php`), and a globally-fragile merchant-count assertion (made a
delta) — all fixed at root cause. Gates: **backend serial 1062 + parallel 1062 (0 fail)**, Vitest 60
files/248 tests, full Playwright e2e 252, OpenAPI 167 ops + TS + permission-types + contract, Pint 953
clean, Larastan L8 no errors, composer/npm audit + gitleaks clean, Docker php-dev + nginx-prod build.
**REM-PERM-001** and **REM-AUDEXP-001** were `local_complete` at this point; both were promoted to `verified_complete` when PR #32 merged (see the lifecycle entry above)._

_Increment 8 (Audit-role frontend) implementation landed and green (not committed/merged): the full
Audit SPA on the existing shell/router/Pinia/generated-types/nav/design-system — 3 Pinia stores
(`auditEventStore`, `flaggedEventStore`, `auditExportStore`) and 8 screens (branch event list +
immutable detail, flagged-event queue + gated review, finance + compensation audit reads, export
list/request + polling detail with private signed download and revoke) plus a shared
`AuditDomainEvents` panel. Controls are gated by the server-derived `can` capability map; reads are
masked and branch-scoped; source records are read-only; the export request enforces a server-side
fresh step-up and blocks unassigned/merchant-level exports; `file_id`/paths/signatures are never
exposed; compensation shows an honest empty state (no fabricated events). Eight routes added; the five
Audit nav items flipped `planned`→`live` (parity fixture regenerated); eight `implemented` screen
entries added to the inventory with regenerated §27.1 specs and `inventory.yaml`. Five new frontend
specs (22 tests) cover store routing/filters/transitions/download-link-non-persistence/polling and
component permission-denied-control-absence/no-branch/capability-and-state gating. Gates: vue-tsc
clean, full Vitest 59 files / 244 tests pass, ESLint 0 errors, `vite build` succeeds. Playwright E2E +
axe/responsive/dark/keyboard proof, the Finance-role finance-audit screen, and the Increment 9
full-gate run remain._

_Increment 7 landed and green (not committed/merged): the `audit:verify-chain` verifier is now a
scheduled daily integrity check (`routes/console.php`: `->daily()->withoutOverlapping()->onOneServer()`
— Plan §67/§1610 pin no sub-daily cadence, so the established daily cadence is used) with a bounded,
redacted failure signal. New `App\Domain\Audit\Events\AuditChainVerificationFailed` carries only safe
metadata (severity, category `broken_link`/`hash_mismatch`, safe chain identifier, correlation id,
failed-chain count, timestamp); a failing run emits it **exactly once** plus a matching
`Log::critical`, never a payload, context, full hash, PII, SQLSTATE, or stack trace. New
`AuditChainScheduleTest` + `AuditChainFailureSignalTest` (11 tests / 27 assertions with the retained
`AuditChainVerificationTest`); Pint + Larastan L8 clean. Centralized alert transport, paging,
dashboards, and runbooks remain Phase 25._

_Increment 6 landed and green (not committed/merged): the canonical permission matrix + four-way
parity contract, closing **REM-PERM-001** to `local_complete`. New `docs/auth/permission-matrix.yaml`
— the source-controlled security contract carrying **all 151 §19.2 canonical keys** (70 active +
81 `planned`) **plus** the **17** runtime keys still under a pre-canonical name (168 rows; **87
active**; each legacy row records its `canonical_successor` + `owning_phase`, reconciled in the
owning phases per §19.1 — no prior-phase key is renamed). New dependency-free
`app/Domain/Auth/Services/PermissionMatrix.php` loader (bespoke reader for the fixed YAML subset;
`symfony/yaml` deliberately **not** added) and deterministic `servana:permission-types` command
(composer `permission:types` / `permission:types:check`) generating
`resources/spa/src/types/generated/permissions.ts` (the 87 active keys only — planned keys never
reach the frontend). **Four-way parity is CI-enforced (zero mismatches):** YAML-active == PHP
`PermissionRegistry` == DB `permissions` projection == TypeScript metadata = 87; the retired
`audit.view_full` and legacy `audit.flag` are absent from every projection. **Deferred MFA/step-up
enforcement proven at the backend boundary:** `finance.audit.view` (MFA Y) is blocked at the
privileged-MFA gate for a Finance principal with no assertion (403 before the permission check) and
carries no fresh step-up; `audit.export` (SU Y) is guarded by `RequireFreshMfa` on its request
route while the audit reads are not; `platform.audit.export` stays metadata-only (planned, no
route). New tests: PermissionMatrix Schema / CatalogueCompleteness / Parity / TypeScriptParity /
DatabaseProjection / PerKeyAllow / PerKeyDeny / Override / NonOverridable / MakerChecker /
RoleBoundary / MfaCoverage / StepUpCoverage. A follow-up §2 closure removed every best-effort
placeholder: `PermissionMatrixPlanMetadataParityTest` parses Plan §19.3 independently and proves all
**151** canonical keys match the Plan on every Plan-encoded field (scope, entitlement_key, billing,
period-lock, mfa, step-up, audit_severity, maker/checker, default_roles, override_policy);
`audit_event` is now derived from the live route table + `AuditMutationCoverage` for active keys
(`none` for reads, honest `pending` for planned) and independently re-verified; `owning_phase` is
assigned for all planned + legacy keys per §80; `PermissionLegacyKeyReconciliationTest` proves the 17
legacy keys reconcile to planned successors (or null) with valid owning phases and no duplicate
authority; `PermissionPlannedKeyIsolationTest` proves all 81 planned keys stay out of the registry,
DB, TypeScript, routes, and grants. Full `tests/Feature/Auth` suite 192 passed / 1670 assertions
(PG16); Pint clean; Larastan L8 No errors._

_Increment 5 landed and green (not committed/merged): an enforced mutation→audit coverage guard.
New first-class `app/Domain/Audit/Support/AuditMutationCoverage.php` maps every implemented
mutating (non-GET) `/api/v1` route to the typed `AuditEvent`(s) it emits (100 routes, from the
actual emission sites) or to an explicit EXEMPT reason (3 non-emitting mutations). New
`AuditMutationCoverageTest` fails CI on any unmapped/stale/overlapping route or unknown event
(completeness over the live route table = 504 assertions across all 103 non-GET routes); new
`AuditSeverityCoverageTest` proves every `AuditEvent` case has a valid severity + read-segment
domain, all tiers represented, registry↔enum consistent. 10 passed / 504 assertions; Pint clean
(905 files); Larastan L8 No errors. Deferred domains (billing/compensation/notifications/SMS)
fabricate nothing._

_Increment 4 (audit export) landed and green (not committed/merged) — the prior Outcome-C blocker is
**RESOLVED** by the 2026-07-04 product-owner decision authorizing a dedicated branch-scoped
`audit_exports` table (**ADR-010**; REM-AUDEXP-001 `blocked` → `in_progress`). Plan amended narrowly
(§13 launch-table inventory + §13.5 DDL + §80 Phase-19 DB scope; Phase 23 preserved as final
release-wide export **hardening**). New forward-only migration `2026_07_04_000002_create_audit_exports_table`
+ expand/contract `..._000003` (adds `audit_export` to the `uploaded_files` purpose CHECK). New
`FilePurpose::AuditExport`; `AuditExportStatus`; `AuditExport` model + factory; `AuditExportStateMachine`
+ exception; actions `RequestAuditExport`/`RevokeAuditExport`/`ExpireAuditExport`/`RecordAuditExportDownload`;
`GenerateAuditExport` (TenantAwareJob, reports-exports, idempotent) + `AuditExportCsvBuilder` (masked via
`AuditValueMasker`, branch-scoped, **never merchant-level rows**, bounded chunks); `AuditExportPolicy`;
`RequestAuditExportRequest`+`AuditExportIndexRequest`; masked `AuditExportResource` (no `file_id`/path/
signature/internal id); `AuditExportController` + 6 routes (store = `audit.export` + fresh step-up
`StepUpAction::AuditExportCreate`; download accounting on the authorized `GET .../download` STREAM, not
link issuance). The unused `audit.exported` event is retired in favour of the Finance-convention
`audit_export.requested|generated|failed|downloaded|expired|revoked`. New `audit.export` permission
(Audit default, in-domain write). audit-exports test group **31 passed / 122 assertions**; Pint clean
(932 files); Larastan L8 No errors; OpenAPI **143 paths / 167 operations** + TS synchronized + contract
check OK._

_Increment 3 landed and green (not committed/merged): masked, domain-segmented merchant
audit reads and the **retirement of the legacy catch-all `audit.view_full`** as a
source-of-truth correction (Plan §19.1/§19.2/§19.3 controls; human-authorised — Q1 full
canonical conformance, Q2 platform-governance-only for merchant-level rows). **As-built
conflict recorded:** the legacy registry + R2 tests granted `audit.view_full` (and full
merchant-trail read including `branch_id` null rows) to Merchant Admin / Branch Manager /
HR / Finance / Audit; this conflicts with the canonical branch-scoped, domain-segmented
matrix. **Correction:** `audit.view_full` removed everywhere — registry catalogue + every
default grant, DB projection (new `PermissionSeeder::prunePermissions()`), `AuditLogPolicy`,
routes, `FilePurposeRegistry` (`AuditEvidence` → `audit.branch_events.view`),
`PermissionMatrixTest`, and the e2e admin fixture (no alias/compat/fallback). Canonical keys
activated: `audit.finance.view` + `audit.compensation.view` (Audit), `finance.audit.view`
(Finance). New `AuditDomain` enum + `AuditEvent::domain()`/`actionsIn()` classify events; new
`GET /audit-logs` (General branch events), `/audit-logs/finance` (`finance.audit.view` OR
`audit.finance.view`; `EnsurePermission` extended to a variadic OR of keys), and
`/audit-logs/compensation` (empty until 20F–20H) — all branch-scoped, `branch_id NOT NULL`
(merchant-level rows excluded), masked, ULID-only, allowlist-filtered, bounded-paginated.
**Merchant Admin / Branch Manager / HR lose all direct raw audit read** (oversight via
reports/dashboards). Merchant-level (`branch_id` null) rows have no merchant-tier surface — a
deliberate, documented limitation (no permission/route invented). OpenAPI **138 paths / 161
operations** + TS synchronized + contract check OK. Tests green on PG16: new
`AuditReadSegmentationTest` (6) + `AuditRedactionTest` (2); rewritten `AuditMaskedReadTest`
(5) + `AuditBranchScopeTest` (4); `PermissionMatrixTest` (3); audit+auth+permissions groups
**224 passed / 844 assertions**; route-security/OpenAPI/route-binding/file-purpose guards and
the flagged-event suite green; Pint clean (902), Larastan L8 No errors. `REM-PERM-001` stays
open (Increment 6 owns full matrix closure, incl. per-key MFA enforcement deferred here)._

_Increment 2 landed and green (not committed/merged): the state-machine-controlled
flagged-event review workflow — `AuditFlaggedEventStateMachine` + exception + five actions
(Flag/StartReview/Resolve/Dismiss/Reopen; `lockForUpdate`, review-metadata-only, typed
`AuditEvent`s, the immutable `audit_logs` source never touched), `AuditFlaggedEventPolicy`,
Form Requests, masked `AuditFlaggedEventResource`, thin controller, six branch-scoped
routes. Permission reconciliation: legacy `audit.flag` → canonical
`audit.flagged_event.create`/`update_status`/`resolve_metadata` + `audit.branch_events.view`.
OpenAPI regenerated to 159 operations / 136 paths + TS synchronized. Tests green on PG16:
`AuditFlaggedEventWorkflowTest` (9), `AuditFlaggedEventIsolationTest` (5),
`AuditSourceMutationDenialTest` (9); audit group 75, auth suite 142, route-security/OpenAPI
26 — all green; Pint (899) + Larastan L8 clean._

_Increment 1 landed and green (not committed/merged): specification-first gate
(`docs/architecture/data-dictionary/audit-files-notifications.md` created;
`docs/architecture/state-machines/audit-flagged-event.md` created) and the
`audit_flagged_events` schema slice — forward-only migration `2026_07_04_000001`
(branch-owned; `audit_log_id` FK ON DELETE RESTRICT to the append-only, hash-chained
`audit_logs`; status/resolution/assignment CHECKs; composite tenant FK; no soft-delete),
`AuditFlaggedEventStatus` enum, `AuditFlaggedEvent` model, factories (+ test-only
`AuditLogFactory`), `TenantOwnership` + migration-manifest wiring, and six severity-mapped
`AuditEvent` catalogue cases for the flagged-event workflow + masked export. Green on
PostgreSQL 16: `AuditFlaggedEventSchemaTest` (8), `AuditFlaggedEventStateMachineTest` (4);
`AuditEventCoverageTest`/`MigrationManifestTest`/tenant-coverage still green; Pint + Larastan
L8 clean on the new code. Remaining: flagged-event workflow HTTP, masked audit reads, async
audit export, mutation→event coverage guard, permission-matrix closure (REM-PERM-001,
`docs/auth/permission-matrix.yaml` absent), scheduled chain verification + failure signal,
and the Audit frontend. REM-PERM-001 stays **open**._

### Phase 18B — Validation, Receipts, Refunds, Disputes, Cash-Up, Period Locks, Finance Exports (`phase-18b-financial-validation-controls`) — verified_complete (PR #31 MERGED, merge `64bd0a1`)

_PR **#31** MERGED into `main`, merge commit `64bd0a117dcdc819a8baf4b9bec3c3eb09635edc` (implementation `ed07c8b`, CI-correction `a0d4dede7ce62e5dbcb7a27467b15ba592ccf6d3`, governance `a8f988b68872eb3e352bc7f70dbb362bfb320cf3`). CI: initial run `28694148176` FAILED, corrected-head run `28695121157` SUCCESS, final governance-head run `28695314469` SUCCESS. `reviewDecision` blank under the documented PR-specific solo-maintainer exception (`docs/governance/solo-maintainer-review-exception-pr-31.md`) — not independent reviewer approval. **REM-PAY-001** closed `verified_complete` on this merge; **REM-PERM-001** stays open (Phase 19)._

Completes the auditable money lifecycle on merged Phase 18A: **whole-group payment
validation** (one atomic decision → one gap-free original receipt; no partial-component
validation; maker ≠ checker), **rejection / correction / component reference correction /
resubmit** (mandatory reason, no receipt), **receipts** (immutable, automatic on
validation, reissue = new gap-free number referencing the original, authorized signed
Phase-10F download — no manual issue), **external refunds** (component-allocated,
maker/checker + fresh step-up, non-destructive/irreversible finalize, per-component 20G
reversal handoff), **finance disputes** (create/review/resolve/reject; disputed source
never mutated; private evidence), **branch cash-up + day-close guards** (server-derived
expected totals (Gate H), maker = Branch Manager / checker = Finance, day close blocked
until the cash-up is approved/locked with no pending validations and complete receipt
generation), **database-backed financial period locks + exceptional reopen**
(`DatabasePeriodLockRepository` replaces the always-open stub → `423
financial_period_locked` enforced everywhere; Finance creates + executes reopen with fresh
MFA; Merchant Administrator approves an exceptional reopen only, requester ≠ approver), and
**finance exports** (async `TenantAwareJob` on `reports-exports`, masked + scoped CSV via
the Phase-10F file domain, atomic download accounting, only invoices/payments/receipts/
cash_up/refunds/disputes; compensation/payouts/billing rejected `422
unsupported_export_type`; `PL n/a`).

Canonical permissions reconciled from the legacy placeholders (`periods.lock`,
`cashup.submit`, `cashup.review_approve`, `periods.reopen`, `exports.finance`) to
`branch.cash_up.submit`, `cash_up.view/approve/reject/request_correction`,
`period_lock.create/reopen`, `merchant.period_reopen.approve_exception`,
`finance_export.create/download`; incompatibilities `branch.cash_up.submit ⟂
cash_up.approve` and `period_lock.reopen ⟂ merchant.period_reopen.approve_exception`
enforced. `REM-PERM-001` stays open (Phase 19 owns full matrix closure).

Frontend (Phase-11 shells, Pinia + generated TypeScript contract): Finance task inbox,
pending-validations + whole-group decision detail, receipts list/detail (reissue gated),
external refunds list/detail (approve/reject/finalize with an irreversible warning),
disputes list/detail (source read-only), cash-up review list/detail (approve/reject/
request-correction/lock), financial periods (create + reopen request/execute), exports
(request/download/revoke, supported types only); Branch Manager cash-up (server expected
read-only, counted entry, variance, submit/resubmit, no approve); Merchant Administrator
exceptional-reopen approval only; Front Office receipts (view + download only). Navigation
flipped 9 planned Phase-18B items → live + added Merchant-Admin period-reopen approvals;
88 §27.1 screen specs + `role-navigation.yaml` + `inventory.yaml` regenerated.

Local gates (recorded in `docs/proof/phase-18b.md`): backend **serial 1065 passed / 7
skipped / 5175 assertions** and **parallel 1065 passed / 7 skipped / 5175 assertions** on
PostgreSQL 16; Pint clean (877 files) + Larastan L8 **No errors** (667 files);
`composer validate --strict` valid; `audit:verify-chain` clean (dev DB has no audit rows;
append-only/hash-chain invariants proven by the feature suite); OpenAPI **152 operations /
130 paths** + TypeScript regenerated + contract check OK; frontend **Vitest 222 / 54
files**, ESLint 0 errors, vue-tsc clean, `npm run build` OK. Playwright: the four Phase 18B
specs (`payment-validation-receipt`, `refund-dispute`, `cash-up-period-lock`,
`finance-export`; 360/768/1280 + light/dark + keyboard + axe serious/critical = 0) pass
**29/0**, and full `npm run e2e` is **227 passed / 0 failed**. Two defects fixed this
checkpoint: **DEF-18B-003** (360px page-overflow on the branch cash-up table → stacked
method cards ≤767px / table ≥768px) and **DEF-18B-004** (cash-up component test coupled to
the real clock → derive the business date like the component). Security: composer audit no
advisories, npm audit high-gate exit 0 (two moderate transitive `js-yaml` advisories below
the gate), gitleaks no leaks; both Docker images (php `dev`, nginx `prod`) build. Linux CI
remains the authoritative browser/Docker/gitleaks gate at merge. **REM-PAY-001** stays open
until Phase 18B merges with green CI.

### Phase 18A — Merchant-Client Payment Recording (`phase-18a-payment-recording`) — verified_complete (PR #30, merge `4a489d0`)

Merged: PR **#30** `Phase 18A: Implement payment recording` squash-merged into `main`
as `4a489d04156aec8348eda9a968f830da31668c87` (2026-07-02). Commit lineage:
implementation `baa3678` → local-completion documentation `24ae7e8` → CI-correction
`aef8d51` → governance / final PR head `0e36641`. CI: initial run `28574550657` FAILED
(Backend Pint style in `app/Http/Resources/PaymentRecordingGroupResource.php` —
formatting only, no behavior/assertion weakened; E2E payment test asserted the page
body must not contain "validate" while the correct pending-validation copy truthfully
states Finance must validate before a receipt exists — corrected to keep the copy and
assert no Validate action is available to Front Office, preserving the role boundary);
corrected-head run `28575564965` SUCCESS (Docker failed once on the same head with no
product-code change, passed on rerun); final governance-head run `28576226830` — five
required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS.
`reviewDecision` intentionally blank under the documented PR-specific solo-maintainer
governance exception (`docs/governance/solo-maintainer-review-exception-pr-30.md`) — not
independent reviewer approval. **REM-PAY-001 remains open** (it spans Phase 18A and
Phase 18B; closes when Phase 18B merges).

### Phase 18A — Merchant-Client Payment Recording (build detail)

Merchant-client payment recording on merged Phase 17 (`6557469`, PR #29): the four
branch-owned tables `payment_recording_groups` + `payment_records` +
`payment_allocations` + `payment_reference_checks` (§13.8/§13.15/§41); the
payment-recording-group state machine (recorded → pending_validation); Front Office as
the default maker recording single and split/multi-method groups against an issued/
partially-paid invoice under the invoice row lock; method-aware evidence rules;
encrypted + masked references; durable, concurrency-safe duplicate detection (Gate C
partial unique index) with a masked `409` and a Finance override (permission + MFA +
fresh step-up + mandatory reason, original reference never edited, maker≠checker); an
overpayment guard against `(total − validated_paid) − active_pending`; group-level
idempotency (R4); period-lock (423) + billing gates reused; a masked, branch-scoped
Finance mail-notification seam; four typed audit events; and the Front Office +
Finance frontend. **No** validation, receipt, refund, dispute, cash-up, period-lock
persistence, or commission is introduced (deferred to 18B/20G). The invoice
`validated_paid_minor` and status are never changed. See
[docs/proof/phase-18a.md](proof/phase-18a.md).

- **Specification gates (resolved):** (A) record only against issued/partially_paid;
  (B) group = the split, concrete component methods, `split_payment` never a component
  method; (C) durable duplicate via partial unique index WHERE result='unique'; (D)
  masked Finance mail-notification seam (no Phase 21N table); (E) reuse
  `FinancialPeriodGuard`/`PeriodLockRepository` (no `financial_period_locks`); (F) cash
  optional-ref/no-dup-check (no cash-up table).
- **Permissions:** reconciled legacy `payments.*` → canonical `customer_payment.record`
  (Front Office) + `.view`/`.duplicate_override`/`.record_exception` (Finance); 18B
  checker keys not introduced; REM-PERM-001 stays open (Phase 19).
- **Docs:** consolidated the data dictionary to the Plan-canonical
  `invoicing-and-payments.md`; added the payment-recording-group state machine and
  `docs/proof/phase-18a.md`. Phase 17 reconciled to `verified_complete`.
- **Tests:** 62 payment backend tests (schema/api/duplicate/audit/notification); full
  parallel suite 952 pass / 7 skip / 0 fail; Pint + Larastan L8 clean; OpenAPI (110
  routes) + TS parity; Vitest `RecordPayment`; Playwright `payment.spec` (Linux CI).

### Phase 17 — Invoicing (`phase-17-invoicing`) — verified_complete (PR #29, merge `6557469`)

> Reconciled at Phase 18A start: PR **#29** MERGED into `main` (squash merge `6557469`,
> 2026-07-01; implementation `c0fdd83`, initial CI `28516753439` five checks SUCCESS;
> governance/final head `3c4e309`, final CI `28517236474` five checks SUCCESS).
> `reviewDecision` blank under the solo-maintainer governance exception — not
> independent approval. REM-PERM-001 remains open (Phase 19).

Merchant-client invoicing on merged Phase 16C (`ffe37cc`, PR #28): the branch-owned
`invoices` + `invoice_items` tables and the tenant-owned gap-free `invoice_number_
sequences` counter; the nine-state Merchant-Client Invoice machine (Plan §25.3); Front
Office draft creation/update + deterministic finalization (gap-free per-merchant number
`{branch.code}-INV-{padded}` allocated under a row lock, price/preferred-fee/percentage-
config snapshots, `financial_mutation` idempotency); Finance's additive, non-destructive
void (request → execute → reject) and adjustment workflow; and the invoice-side
period-lock enforcement contract (`423`). Money is integer minor units via the `Money`
value object. **No** payment, receipt, commission ledger, preferred-fee rule, or
percentage-fee ledger is introduced (deferred to 18A/18B/20A/20E). See
[docs/proof/phase-17.md](proof/phase-17.md).

- **Specification gates (resolved):** (A) `invoice_items.service_session_id` NOT NULL
  composite FK to `service_sessions(id,merchant_id)`; only `completed` sessions
  invoiceable; multi-session invoices (same merchant/branch/client/currency);
  `UNIQUE(service_session_id)` prevents duplicate invoicing. (B) additive `invoices`
  void/adjust columns — no new table, snapshots/number never mutated, no deletion;
  `paid → refund_pending|adjustment_required` defined+tested but Phase-18B-driven. (C)
  `FinancialPeriodGuard` + `PeriodLockRepository` contract; Phase 17 binds
  `UnlockedPeriodLockRepository`; `423` proven; Phase 18B swaps persistence. (D)
  `PreferredPersonnelFeeResolver` — legacy `services.preferred_personnel_fee_minor` when
  honoured, else none; immutable snapshot; Phase 20A replaces the binding. (E)
  `percentage_fee_config_snapshot` jsonb = null until Phase 20E. (F) `tax_minor`/
  `discount_minor` retained, integer, default 0, deferred.
- **Schema:** three forward-only migrations; nine-state + currency + non-negative +
  arithmetic + draft/finalization + void/adjust coherence CHECKs; composite-merchant
  FKs; partial `UNIQUE(merchant_id,invoice_number)`; `UNIQUE(id,merchant_id)`;
  `UNIQUE(service_session_id)`. Manifest + `TenantOwnership` updated.
- **Domain:** `InvoiceStatus`/`InvoiceStateMachine`, `InvoiceTotalsCalculator`,
  `InvoiceNumberAllocator`, `InvoiceDraftComposer`, `LegacyPreferredPersonnelFeeResolver`,
  `FinancialPeriodGuard`; actions `CreateInvoiceDraft`/`UpdateInvoiceDraft`/
  `FinalizeInvoice`/`RequestInvoiceVoid`/`ExecuteInvoiceVoid`/`RejectInvoiceVoid`/
  `AdjustInvoice`; seven `invoice.*` audit events.
- **Permissions:** reconciled the legacy placeholder `invoices.*` keys (which
  mis-granted invoice creation to Branch Manager + Merchant Admin, violating Plan §10.2)
  to the canonical `invoice.view`/`invoice.create`/`invoice.void.request_or_execute
  _as_policy`/`invoice.adjustment.manage`. Front Office: view + create; Finance: view +
  void + adjust. No other role holds an invoice key. `REM-PERM-001` stays open (Phase 19).
- **HTTP:** `InvoicePolicy`, Form Requests, thin controllers, masked `InvoiceResource`/
  `InvoiceItemResource`, `/api/v1/invoices` routes (index/store/show/update/finalize/
  void/void.execute/void.reject/adjust) with idempotency (finalize) + billing +
  period-lock + route classification; void request/execute require a fresh step-up
  (`StepUpAction::InvoiceVoid`). No DELETE / mark-paid / payment / receipt route.
- **Frontend:** Front Office `InvoiceList`/`InvoiceCreate`/`InvoiceDetail` + Finance
  list/detail (shared `pages/invoicing`); `invoiceStore` (idempotent finalize);
  navigation + get-started `Create an invoice` deep-link activated; screen inventory +
  role-navigation YAML regenerated.

### Phase 16C — Service Sessions and Preferred Personnel (`phase-16c-service-sessions`) — verified_complete

Lifecycle: PR **#28** MERGED into `main` (squash merge `ffe37cc`, 2026-06-30; implementation
`1d2aee5`; final governance head `79746bb`). Initial CI run `28445709595` FAILED E2E (ambiguous
Playwright `My sessions` text locators → multiple elements; accessibility + own-scope cases failed
with no backend/business-rule/accessibility-gate relaxation), remediated `81506da`; second CI run
`28446579933` FAILED E2E (Personnel read-only assertion counted every page button rather than the
session workflow controls), remediated `ac5751a`; corrected run `28448569188` SUCCESS; final run
`28449140384` (head `79746bb`) all five required checks (Backend, Frontend, Docker, Security,
E2E — Playwright) SUCCESS. reviewDecision blank under the solo-maintainer governance exception —
not independent approval. REM-PERM-001 remains open (Phase 19).

The service-delivery unit on merged Phase 16B (`af79b56`, PR #27): the branch-owned
`service_sessions` table, the four-state Service Session machine (Plan §25.2), the
transactional queue↔session coupling (queue `called → in_service` creates+starts one
session; `in_service → completed` completes it), PostgreSQL duplicate-active-session
protection, preferred-personnel execution enforcement (no fee), the typed NON-PAYABLE
commission preview, the `BranchClosureGuard` in-progress-session blocker, and the live
`busy` projection. Front Office owns the session lifecycle; Personnel get strict
own-scope read. **No** invoice, payment, receipt, commission ledger/rule/plan,
preferred-personnel fee, notification, report, or search is introduced (deferred to
17/18/20/21N/22). See [docs/proof/phase-16c.md](proof/phase-16c.md).

- **Specification gates (resolved):** (A) no `appointment_id` — every session links via
  `queue_entry_id`; appointment provenance via `queue_entries.appointment_id` (the
  authoritative appointment machine defers `in_service`/`completed` to the Queue Entry /
  Service Session). (B) immutable `service_id` snapshotted from the locked source
  (service identity). (C) `in_progress → cancelled` is defined + unit-tested, but the
  cancel action refuses a queue-linked in-progress session (`409
  service_session_in_progress`) because the Queue Entry machine has no `in_service →
  cancelled` — workflow in-progress abort deferred to a future queue-machine extension.
  (D) typed `CommissionPreviewResult` = `not_configured` (no compensation tables yet);
  never earned/payable/zero/ledger.
- **Schema:** `service_sessions` (branch-owned, ULID) — four-state CHECK,
  status↔timestamp coherence, partial-unique `(staff_profile_id) WHERE status IN
  (pending,in_progress)` (duplicate-active), `UNIQUE (queue_entry_id)`,
  `UNIQUE (id,merchant_id)` (Phase-17 FK target), composite-merchant FKs. One
  forward-only migration; manifest + `TenantOwnership` updated.
- **Domain:** `ServiceSession`/`ServiceSessionFactory`, `ServiceSessionStatus`,
  `ServiceSessionStateMachine`, `DuplicateActiveSessionGuard`,
  `PreferredPersonnelExecutionValidator`, `CommissionPreviewService` +
  `CommissionPreviewResult` (Compensation context); `StartQueueEntry`/`CompleteQueueEntry`
  extended into transactional orchestration; `CancelServiceSession`, `UpdateServiceNotes`.
  Reuses the 16B `QueuePersonnelAssignmentValidator` → 15B `PersonnelSchedulingValidator`
  (no duplication).
- **Permissions:** legacy `sessions.manage` reconciled to canonical
  `service_session.view/start/complete/cancel` (Front Office) + `personnel.my_sessions.view`
  (Personnel); Branch Manager session grant removed (no session authority). REM-PERM-001
  stays open (Phase 19).
- **API:** 5 new routes (`service-sessions` list/detail/cancel/notes,
  `personnel/me/sessions`) + queue start/complete now also require the session
  permission; Form Request → policy → thin controller → transactional action → masked
  Resource; `service_session.started/completed/cancelled` audit events.
- **Frontend:** Front Office `ServiceSessionList` (cancel/notes + "Preview — not earned
  or payable" wording), Personnel mobile-first `MyServiceSessions` (own-scope, no
  preview); stores, router, navigation, inventory + screen specs.
- **Tests/proof:** service-session backend group 56; full backend 812 pass / 7 skip /
  0 fail; vitest 183; Pint + Larastan L8 + vue-tsc + ESLint(0) + build +
  OpenAPI-deterministic(96) + TS parity + composer/npm audit + gitleaks clean;
  Playwright Linux-CI authoritative (local Windows not claimed); no PR/CI yet.

### Phase 16B — Walk-Ins and Queues (`phase-16b-walk-ins-queues`) — verified_complete

> **Lifecycle (reconciled):** PR **#27** MERGED into `main` (squash merge commit
> `af79b56`, 2026-06-30; original implementation `6a9fbcc`, final head `6272f080`).
> Initial CI run `28420643751` FAILED Backend (8 failed / 4 skipped / 751 passed) —
> `Call to undefined function createWalkIn()`, a file-local Pest helper not reliably
> visible to independent parallel workers — corrected by moving the helper to
> `tests/Pest.php` (parallel execution preserved). Final CI run `28425875550` — five
> required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all
> SUCCESS. `reviewDecision` blank under the documented solo-maintainer governance
> exception (not independent approval). REM-PERM-001 remains open (Phase 19).

Operational queue foundation on merged Phase 16A (`404fed9`, PR #26): the
branch-owned `walk_ins` and `queue_entries` tables, the eight-state Queue Entry
machine (Plan §25.2), the forward-only appointment `checked_in → queued` expand,
atomic walk-in creation (reusing the Phase 15A client action) and atomic
appointment-to-queue conversion (one entry per source), a deterministic
`pg_advisory_xact_lock` + partial-unique active-position model, a deterministic
next-available personnel selector and wait estimator (labelled "Estimate"), Front
Office queue operations (board + walk-in wizard + entry detail), Branch Manager
read-only visibility + queue configuration, and Personnel strict own-scope
visibility (Plan §37, §19, §13.7, §69, §80). No service session, invoice, payment,
preferred-personnel fee, notification, report materialized view, or cross-domain
search is introduced (deferred to 16C/17/18/20A/21N/22). See
[docs/proof/phase-16b.md](proof/phase-16b.md).

- **Schema:** `walk_ins` + `queue_entries` (branch-owned, ULID), 13 `queue_entries`
  CHECKs (source-XOR, eight states, three assignment modes, `position > 0`,
  status↔timestamp coherence, required reasons, wait-override pairing), per-source
  UNIQUE, partial-unique `(branch_id, position) WHERE status IN
  (waiting,assigned,called)`, merchant-first + lookup indexes, composite-FK
  tenant/branch consistency; Branch Day gains `queue_is_open`/`queue_capacity`/
  `queue_default_assignment_mode`; the appointment status CHECK + `checked_in_at`
  coherence CHECK expand to include `queued` (no row loss).
- **State machine (Queue Entry):** `waiting, assigned, called, in_service,
  completed, transferred, cancelled, no_show`; invalid transitions → 422
  `invalid_state_transition`; no generic status endpoint; `in_service`/`completed`
  are queue states only (no service session in 16B).
- **Permissions:** removed the legacy `queue.operate`/`queue.transfer_entries`/
  `queue.configure`; activated Front Office `queue.view/create/assign/transfer/
  reorder` + `preferred_personnel.select` and Personnel own-scope
  `personnel.my_queue.view`. Branch Manager holds **no** operational `queue.*` —
  it configures via `branch.profile.manage` + `day.open_close` and reads via
  `branch.dashboard.view`. **REM-PERM-001 stays open** (Phase 19).
- **Backend/API:** 15 `/api/v1` routes (queue configuration, queue list/show,
  walk-in store, appointment conversion, assign/call/start/complete/transfer/
  cancel/no-show, reorder, personnel own-queue). Form Request → Policy → 11
  transactional domain actions → masked Resource; mutations `branch_mutation`;
  each authorises, acquires the per-branch advisory lock + row locks, validates
  state + capacity + (where personnel is involved) the reused Phase 15B
  `PersonnelSchedulingValidator`, recalculates estimates, and writes one coherent
  typed audit event.
- **Audit:** `queue.configuration.updated`, `walk_in.created`,
  `queue_entry.created/assigned/called/started/completed/transferred/reordered/
  cancelled/no_show/wait_estimate_overridden`, `appointment.queued` — safe context
  only (no full contact, blind index, tokens, headers, full bodies, or sequential
  ids).
- **Branch closure:** `BranchClosureGuard` now blocks branch archival **and** day
  close while any active (waiting/assigned/called/in_service/transferred) queue
  entry exists; terminal entries never block.
- **Frontend:** Front Office queue board (capability-gated actions + keyboard
  move-up/down reorder) + walk-in wizard + entry detail; Branch Manager read-only
  board + queue settings; Personnel own-queue; navigation flips (Queue/Walk-ins/My
  queue planned→live) + get-started "Start a walk-in" deep link; six §27.1 screen
  specs + regenerated inventory/navigation YAML; OpenAPI (91 routes) + TS regen.
- **Tests/gates:** queue group 62 pass; full backend 759 pass / 4 skip / 0 fail;
  Vitest 171 pass; Pint + Larastan L8 + vue-tsc + ESLint(0) + build +
  OpenAPI-deterministic + TS parity + route-security + permission-matrix +
  audit-coverage clean; Playwright `queue.spec.ts` authored (Linux CI
  authoritative); composer audit clean; npm audit 0 high/critical. Not yet
  `ci_passed`/`merged` (no PR/CI).

### Phase 16A — Appointments (`phase-16a-appointments`) — verified_complete (PR #26 MERGED `404fed9`)

Merged into `main` as **PR #26** (squash merge commit `404fed9`, 2026-06-29;
original implementation `e62da20`, CI remediation `ce04c73`, final pre-merge
governance head `794ff85`). Final CI run `28378639377` — five required checks
(Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS. The initial
CI run `28372954922` failed on E2E (broad appointments collection mock
intercepting detail/action requests → missing check-in capability and a
non-adaptive dark-mode text token → genuine axe contrast failure); remediation
`ce04c73` let detail/action requests fall through to their mocks and used the
adaptive heading token, with the axe gate, timeouts, retries and business
behaviour preserved (not a flake). reviewDecision blank under the documented
PR-specific solo-maintainer governance exception
(`docs/governance/solo-maintainer-review-exception-pr-26.md`) — not independent
approval. **REM-PERM-001 remains open** (Phase 19).

Front Office appointment foundation on merged Phase 15B (`02f4dc5`, PR #25): the
canonical `appointments` table, the Appointment state machine (Plan §25.2), Front
Office appointment creation / assignment / transfer / rescheduling / cancellation
/ check-in / no-show, PostgreSQL prevention of overlapping active appointments for
the same personnel member, branch operating-hours + calendar-exception validation,
mandatory reuse of the Phase 15B `PersonnelSchedulingValidator`, Branch Manager
branch-scoped read-only visibility, and Personnel own-scope visibility (Plan §36,
§25, §19, §13.7, §27; Corrections 16, 17, 22). No walk-in, queue, service-session,
invoicing, payment, preferred-personnel fee, or notification subsystem is
introduced (deferred to Phases 16B/16C/17/18/20/21N). See
[docs/proof/phase-16a.md](proof/phase-16a.md).

- **Schema:** `appointments` (id, ulid, merchant_id, branch_id, client_id,
  service_id, preferred_personnel_staff_profile_id nullable,
  assigned_personnel_staff_profile_id nullable, starts_at/ends_at timestamptz,
  status, cancellation_reason, transfer_reason, checked_in_at, cancelled_at,
  no_show_at, created_by, timestamps) — branch-owned, composite-FK
  tenant/branch consistency to branch + client + service + both staff profiles;
  CHECK constraints (status set, `starts_at < ends_at`, timestamp↔status
  coherence); btree_gist EXCLUDE on assigned personnel + `tstzrange(starts_at,
  ends_at,'[)')` over active statuses → deterministic 409
  `appointment_schedule_conflict`; merchant-first/branch-date/client-date/
  assigned-date/preferred-date/status indexes.
- **State machine (Phase 16A):** states `scheduled, confirmed, checked_in,
  rescheduled, cancelled, cancelled_with_reason, no_show`; transitions
  `scheduled→confirmed|cancelled`, `confirmed→checked_in|rescheduled|cancelled|
  no_show`, `checked_in→cancelled_with_reason`, `rescheduled→scheduled|confirmed`;
  invalid transitions → 422 `invalid_state_transition`; `queued`/`in_service`
  deferred to 16B/16C.
- **Permissions:** legacy `appointments.manage` → canonical `appointment.view/
  create/reschedule/cancel/check_in/assign/transfer` (Front Office defaults,
  branch scope) + `personnel.my_appointments.view` (Personnel, own scope). Branch
  Manager keeps read-only via existing `branch.dashboard.view` — **no
  `appointment.*` on Branch Manager**. No-show authorised through
  `appointment.cancel` (no new key). **REM-PERM-001 stays open** (Phase 19).
- **Backend/API:** `/api/v1/appointments` index/store/show + assign/transfer/
  reschedule/cancel/check-in/no-show, plus `/api/v1/personnel/me/appointments`
  (own scope). Form Request → Policy → transactional action → Resource; mutations
  `branch_mutation`; each mutation authorises, row-locks, validates state +
  scheduling, writes atomically with exactly one typed audit event. Branch
  operating-hours/calendar enforced by a single
  `AppointmentBranchScheduleValidator`; eligibility/availability reuse
  `PersonnelSchedulingValidator` (no duplication).
- **Audit:** `appointment.created/assigned/transferred/rescheduled/cancelled/
  checked_in/no_show` — safe context only (masked client, no full contact, no
  blind index, no sequential id).
- **Branch closure:** `BranchClosureGuard` now blocks branch archival on active
  appointments; `CloseBranchDay` blocks on same-day active appointments (Plan
  §25.2 day-close appointment guard flipped on).
- **Frontend:** Front Office appointment list/create/detail (+ assign/transfer/
  reschedule/cancel/check-in/no-show dialogs); Branch Manager read-only
  appointments; Personnel own appointments; navigation `planned→live`;
  get-started deep link; regenerated screen/navigation fixtures + OpenAPI/TS.

### Phase 15B — Personnel Availability and Eligibility Completion (`phase-15b-personnel-availability`) — verified_complete (PR #25, squash merge `02f4dc5`)

HR-controlled personnel availability (recurring + date-specific exception
schedules: working days, split shifts, breaks, days off, temporary and emergency
unavailability), the canonical `personnel_availability` table, canonical
permission `personnel.availability.manage` (reconciled from the legacy
`availability.manage`), a single deterministic availability resolver, the reusable
`PersonnelSchedulingValidator` (eligibility + availability gate), Branch Manager
branch-scoped read-only schedule visibility, and the HR availability screen
(Plan §13.7, §80 Phase 15B; Corrections 16, 17). Built on merged Phase 15A
(`81a5866`, PR #24). No appointment/walk-in/queue/session workflow is introduced
(deferred to Phases 16A–16C). See [docs/proof/phase-15b.md](proof/phase-15b.md).

- **Schema:** `personnel_availability` (id, merchant_id, branch_id,
  staff_profile_id, weekday, date, start_time, end_time, type ∈ {recurring,
  exception}, available, timestamps) — branch-owned, composite-FK tenant/branch
  consistency, CHECK constraints (type↔weekday/date polarity, weekday range,
  start<end, no cross-midnight), GiST exclusion constraints preventing same-polarity
  recurring/exception interval overlaps; merchant-first + staff-schedule indexes.
- **Permissions:** legacy `availability.manage` → canonical
  `personnel.availability.manage` (HR-only default grant); `personnel.eligibility.manage`
  preserved (HR-only). Branch Manager read-only visibility via `branch.dashboard.view`.
  **REM-PERM-001 stays open** (Phase 19 owns full permission-matrix closure).
- **Backend/API:** 3 `/api/v1` routes — `staff.availability.show` (HR + Branch
  Manager read), `staff.availability.update` (HR atomic replace),
  `staff.availability.emergency-unavailable` (HR) — Form Request → Policy → action
  → Resource; mutations `branch_mutation` (Sanctum + ResolveTenantContext +
  EnsureBranchScope + EnsurePermission); atomic schedule replacement under row
  lock; 2 typed audit events (`personnel_availability.updated`,
  `personnel_availability.emergency_unavailable`), redacted reason.
- **Validator (Phase 16A handoff):** `PersonnelSchedulingValidator` checks
  tenant/branch/staff-lifecycle/active-assignment/service-status/eligibility/
  availability/interval and returns a typed decision. No appointment workflow
  invokes it yet — Phase 16A must invoke it on every appointment create/assign/
  transfer/reschedule and add branch-open + conflict checks around it.
- **Frontend:** HR `PersonnelAvailability.vue` (weekly editor, split shifts,
  breaks, date exceptions, day-off, emergency-unavailable, required change reason,
  unsaved-changes guard, derived state, eligible-services summary) + Pinia store;
  Branch Manager read-only availability surface; navigation `hr.availability`
  `planned→live`; get-started `set-availability` deep-linked; screen specs +
  inventory regen; OpenAPI/TS regen.

### Phase 15A — Services, Catalogue, Clients (`phase-15a-services-catalogue-clients`) — verified_complete

Branch-Manager service catalogue, HR personnel-service eligibility, and
Front-Office client records with SMS consent (Plan §13.7, §35, §39, §80 Phase
15A). Built on merged Phase 11 (`d098f37`, PR #23). **MERGED as PR #24** into
`main` (merge commit `81a5866`, 2026-06-28; foundation `73c7d26`, implementation
`23aeed1`, final PR head `1fcfa40`); CI run `28338582235` — five required checks
(Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS; solo-maintainer
governance exception (reviewDecision blank — not independent approval). REM-CAT-CLI-001
→ `verified_complete`. See [docs/proof/phase-15a.md](proof/phase-15a.md).

- **Canonical permissions (§19.2/§19.3):** activated `service.view/create/update/
  archive` (Branch Manager), `personnel.eligibility.manage` (HR), `client.view/
  create/update` + `front_office.search` (Front Office), reconciled from the
  §10.3 baseline (`services.manage`/`eligibility.manage`/`clients.*`). 7 affected
  auth spec tests updated to canonical names without weakening. **REM-PERM-001
  stays open** (Phase 19 owns full permission-matrix closure).
- **Backend/API:** 16 `/api/v1` routes (catalogue CRUD+archive; eligibility
  assign/revoke; client CRUD+search; SMS consent) on the established Form Request
  → Policy → action → masked Resource architecture; mutations `branch_mutation`
  (Sanctum + ResolveTenantContext + EnsureBranchScope + EnsurePermission);
  deterministic 409s for duplicate client / existing eligibility; 422
  `invalid_state_transition` for re-archive; 12 typed audit events (masked).
- **Client search:** branch/tenant-scoped name + normalized-phone (HMAC blind
  index), masked-only, distinct `front_office.search` capability.
- **Frontend:** Branch Manager catalogue, HR eligibility, Front Office client
  create/search/detail screens (Phase 11 shell) + Pinia stores; navigation
  `planned→live` for `branch.services`/`hr.eligibility`/`front-office.clients`;
  get-started deep links; 5 §27.1 screen specs + inventory regen; OpenAPI/TS regen.
- **Decision:** the Plan §22 billing-status mutation gate is owned by the billing
  phases (20A–20E) and is not built at 15A; 15A mutations are `branch_mutation`
  and inherit it when it lands.

- **Schema (5 branch-owned tables):** `service_categories`, `services`,
  `service_personnel_eligibility`, `clients`, `client_consents` — composite-FK
  tenant/branch consistency, partial-unique constraints (branch-scoped category
  name; same-branch active client phone), CHECK-backed status enums, integer
  minor-unit money, legacy non-editable `services.preferred_personnel_fee_minor`
  seam.
- **Client contact protection (Plan §35, guardrail §6.4):** AES-256-GCM
  `encrypted` phone/email + masked display (`phone_last_four`) + keyed **HMAC
  blind index** (`phone_index`) for branch-scoped search/duplicate prevention
  without a plaintext index; index `$hidden`, never returned/logged;
  `CLIENT_CONTACT_INDEX_KEY` env (placeholder in `.env.example`).
- **Verified (PostgreSQL 16):** `migrate:fresh` OK; tenancy coverage 9; contact
  protection 7; coverage/contract regression 14 (none broken); Pint 447 PASS;
  Larastan level 8 clean.
- **Decisions:** canonical §19.2/19.3 keys (`service.*`, `personnel.eligibility.manage`,
  `client.*`, `front_office.search`) to be activated in the API slice —
  **REM-PERM-001 stays open (Phase 19 owns closure)**; **HR** owns eligibility
  management (not Branch Manager); `preferred_personnel_fee_rules` is Phase 20A.

### Phase 11 — UI Layout Foundation & Role Navigation (`phase-11-ui-layout-role-navigation`)

Finalizes all eight role layouts, scope-accurate role navigation, live role landing
pages, and resumable guided get-started entry surfaces (Plan §26–§31, §80 Phase 11;
REM-SCR-001 Phase 11 substrate). Built on merged Phase 10F (`9b493e6`, PR #22).
**PR #23** (base `main`) — **MERGED** 2026-06-28: implementation commit `0482e10`
+ CI remediation commit `bb04d87`; final pre-merge head `44cebdf`; five required checks
(Backend, Frontend, Docker, Security, E2E — Playwright) SUCCESS on CI run `28314638091`;
merge commit **`d098f37`**; reviewDecision blank under the solo-maintainer governance
exception (not an independent approval). REM-SCR-001 promoted to `verified_complete` on merge.

- **CI remediation (`bb04d87`, "fix: align Phase 11 Docker context and E2E routes"):**
  the first PR #23 run failed on **Docker — build images** and **E2E — Playwright**.
  Docker root cause — `.dockerignore` excluded `docs`, so the SPA build could not resolve
  the Phase 11 `@docs` documentation imports (`@docs/frontend/screens/inventory.json` +
  `@docs/**` markdown) in the Docker build context; fix removed the `docs` line. Playwright
  root cause — Phase 11 re-pathed role-entry routes (landing as each area's index;
  `branch.list`→`/branch/list`, `hr.staff`→`/hr/staff`; setup/login redirects → `*.landing`),
  so three pre-existing specs (`merchant-onboarding`, `branches-staff-invitations`,
  `auth-magic-link`) asserted stale routes; fix updated those specs (no product code changed).

- **Screen inventory & coverage guard:** `docs/frontend/screens/inventory.json`
  (source) → generated `inventory.yaml`; 44 §27.1 spec files for every implemented
  route + 16 Phase-11 screens + 2 access-state screens; future screens listed
  `planned` with truthful owner phases and no routes. `screenInventory.spec.ts` fails
  on missing specs, status/router conflicts, fake planned routes, missing owner phase,
  or duplicate keys/routes. Generator `scripts/generate-screen-specs.mjs`.
- **Eight role layouts:** `RoleShell` → `AppShell` (skip link, landmarks, current-route
  indication, focusable main, 44px targets, light/dark, mobile drawer with focus return).
- **Canonical navigation registry:** `navigation/roleNavigation.ts` + snapshot-enforced
  fixture `docs/frontend/navigation/role-navigation.yaml`; live→real routes, planned→owner
  phase (no dead links); PermissionGate-driven UX visibility.
- **Navigation placement rule:** Super Administrator primary nav in the header (mobile
  disclosure); all merchant roles use a desktop sidebar/rail + mobile drawer, header
  utility-only — enforced and tested.
- **Eight landing pages:** verbatim role hero + approved role images + role FAQ accordion
  + role legal footer + live actions + get-started progress + truthful "coming soon".
- **Eight get-started pages:** verbatim Scope §3.2 checklists + mandatory non-prefilled
  legal acknowledgement; persistence, dismissal, reopen.
- **Persistence:** `getStartedStore` — versioned localStorage keyed by user ULID + role;
  stores only item ids + completion/dismissal/acknowledgement + schema version (no
  tokens/permissions/contacts/secrets/paths/responses); isolated per user and role.
- **Legal:** rendered `/legal/:role/:doc` (verbatim, lazy per-document) + `LegalAcknowledgement`
  (mandatory cannot be bypassed; optional marketing consent kept separate; correct role docs only).
- **State boundaries:** loading/empty/error/no-permission/no-branch/unsupported-role.
- **Routing:** role-aware post-login destinations (Verify/MFA/first-time-setup); MFA
  ordering, pending-setup, active-merchant, and suspension routing preserved.
- **Content sourcing:** approved landing/FAQ/legal text imported verbatim from `docs/**`
  via `?raw`/`import.meta.glob` (single source of truth; legal never hand-copied; lazy legal).
- **Brand/a11y:** ADR-009 preserved (no white text on Savannah-Orange CTA); added adaptive
  `--color-heading` token for AA headings in dark mode; darkened light `--color-text-muted`
  to `#4b5563` for AA on surface-alt. Responsive 360/768/1280, axe light+dark clean.
- **Phase 10F deferral resolved:** role navigation + role entry/landing surfaces (deferred
  from 10F) delivered here.

### Phase 10F — File & Media Foundation (`phase-10f-file-media-foundation`)

Establishes the secure, private, reusable file domain before any feature stores,
generates, exports or downloads business files (Plan §65, §73; REM-FILE-001). Built
on merged Phase 10 (`4f761ff`, PR #21). **`verified_complete`** — merged as PR #22
(merge commit `9b493e6`); five-gate CI (Backend/Frontend/Docker/Security/E2E—Playwright)
all SUCCESS; the genuine ClamAV EICAR CI test passed without skipping (impl commit
`431dde2`, ClamAV CI correction `c54016d` preserved; local Windows Playwright timeout
not claimed as a pass — Linux CI authoritative). REM-FILE-001 → `verified_complete`
on merge (solo-maintainer governance exception, reviewDecision intentionally blank —
not independent approval).

- **Schema:** `uploaded_files` + `file_scan_events` (exact §13.13 fields, 11-purpose
  CHECK, scan/lifecycle CHECKs, required indexes, `available⇒clean+final_path` CHECK).
  No `download_count`; no global SHA-256 uniqueness (avoids a cross-tenant existence
  side channel). Data dictionary authored before migrations; manifest + TenantOwnership
  updated.
- **Purpose policy:** `FilePurpose`/`FilePurposeRegistry`/`config/files.php` — only
  `merchant_logo` and `profile_photo` uploadable; every export/PDF/report/statement
  purpose generated-only. Existing permission keys only.
- **Pipeline:** authorize→reject dangerous/spoofed pre-storage→stream to private
  quarantine→streaming SHA-256→server magic-byte MIME→pending row→202.
- **ClamAV:** `FileScanner` contract + INSTREAM `ClamAvScanner` (bounded timeouts,
  safe result mapping, version capture). Real EICAR integration test (runtime payload,
  no malware file committed).
- **Finalization:** clean files re-encoded (GD, metadata stripped) and promoted to a
  private final prefix only after verified storage; quarantine deleted post-promotion;
  infected/failed/rejected never downloadable.
- **Downloads:** `POST /files/{id}/download-link` issues a short-lived signed link;
  `GET /files/{id}/download` requires auth + a valid signature, streams from private
  storage (`nosniff`, safe Content-Disposition, no public cache). Authorization
  (tenant/branch/own-scope/permission/available/clean) is re-checked at issuance AND
  download by `FileAccessService`.
- **Jobs/scheduling:** `ScanUploadedFile`, `FinalizeCleanFile`, `ExpireSignedExport`,
  `DeleteExpiredQuarantineFile`, `VerifyOrphanedFileRecords` (report-only) on a
  dedicated `file-scanning` queue + worker (dev + prod compose); hourly/daily schedules.
- **Audit/redaction/boundary:** file `AuditEvent` cases; `Redactor` extended for
  signatures/paths/hash/filename/scanner payloads; `FileStorageBoundaryTest`
  statically forbids private-file writes/signing outside the file domain.
- **Billing seam:** `FileGenerationPolicy` denies new billing-gated generation when
  billing is read-only while keeping existing authorized files downloadable (no
  billing/finance_exports tables created).
- **Frontend:** accessible `SvFileUpload.vue` (selecting/uploading/scanning/available/
  rejected/error states, aria-live, 44px, light/dark, typed transport, no localStorage)
  + `useFileDownload`.
- **Contracts:** OpenAPI + generated TypeScript regenerated deterministically (47
  production routes incl. 4 file routes; `api:contract:check` OK).
- **Tests:** 52 backend file tests + 3 real-ClamAV EICAR + 6 frontend.
- **Deferrals:** finance_exports/audit dashboard/billing/M-Pesa/reports/UI nav → owning
  phases (11, 15A/15B, 16A–C, 17–18, 18B/23, 19, 20A–H, 20D, 20H/21N, 25).

### Phase 10 — API Foundation (`phase-10-api-foundation`)

Establishes the secure, generated, test-enforced API contract substrate every later
feature phase inherits (Plan §23, §24, §80; REM-ROUTE-001, REM-MIG-001; ADR-004).
Built on the merged gate-closure commit `7ac20a5` (PR #20). **Merged as PR #21**
(merge commit `4f761ff`, 2026-06-24; CI Backend/Frontend/Docker/Security/E2E—
Playwright all SUCCESS; reviewDecision blank under the solo-maintainer governance
exception — not independent approval). `verified_complete`; REM-ROUTE-001 and
REM-MIG-001 are `verified_complete`. (Backend CI initially failed on
nondeterministic OpenAPI generation — dedoc/scramble introspecting an un-migrated
parallel-worker schema — fixed in `a6b3e4c` without weakening stale-contract
enforcement; the subsequent complete five-job run passed.)

- **Route classification:** extended the R4 `RouteClass`/`RouteClassification` seam
  (not replaced) with the 8th class `liveness_readiness`, per-class required/forbidden
  middleware, and the `VALIDATION_EXEMPT` allowlist. Every production non-GET route
  declares exactly one class; health probes are `liveness_readiness`.
- **Security contract:** `RouteSecurityContractTest` + `ForbiddenRouteAbsenceTest`
  (SA merchant-creation and personnel contact-export proven absent);
  `FinancialRouteIdempotencyCoverageTest` preserved — financial routes cannot exist
  without idempotency.
- **Pagination/filter/sort:** reusable `App\Http\Api\ApiPagination` (default 25, max
  100, over-limit 422, allowlisted sorts + stable tiebreaker, validated filters);
  retrofitted the `branches`, `staff` and `staff-invitations` listings.
- **Resource `can` maps:** `HasCapabilities` concern (policy-derived, booleans,
  ULID ids only) on the branch/staff/invitation/audit resources.
- **OpenAPI generation:** the maintained **dedoc/scramble** generator (v0.13.28,
  declared in `composer.json` `require`) is authoritative for schema generation; a
  thin `App\Support\OpenApi\OpenApiGenerator` wrapper invokes it and applies
  determinism, full `/api/v1` paths, testing exclusion, operationId=route name,
  health probes, the §11.5 error envelope, the session security scheme and the
  financial `Idempotency-Key` header (`composer api:openapi` →
  `docs/api/openapi.json`, 43 operations, no test/future operations).
  `Scramble::ignoreDefaultRoutes()` keeps the docs UI out of the app.
- **TypeScript contract:** `npm run api:types` →
  `resources/spa/src/types/generated/api.ts` (openapi-typescript@7.4.4);
  `npm run api:contract:check` fails on stale/leaked/duplicate/missing contract and
  runs in CI (frontend job).
- **Migration governance:** ADR-004 (expand-and-contract, forward-repair, PITR) +
  `docs/architecture/migrations/{README.md,manifest.yaml}` (all 33 migrations) +
  `MigrationManifestTest` lint. No shipped migration edited.
- **Tooling deps:** `dedoc/scramble` (require, authoritative OpenAPI generator) +
  `spatie/laravel-package-tools`; `symfony/yaml` (dev, manifest lint);
  `openapi-typescript` (dev, pinned 7.4.4).
- **Parallel-suite fix (`1d25224`):** moved `committedSpec()`/`specOperationIds()`
  into `tests/Pest.php` so the OpenAPI tests are parallel-safe (an undefined-function
  fatal occurred only under `--parallel`). Full parallel suite: 485 passed / 4
  skipped / 2102 assertions / 4 processes.
- **Linux CI Playwright gate (`ci: enforce Phase 10 Playwright gate`):** added an
  explicit, separate `E2E — Playwright` job to `.github/workflows/ci.yml`
  (ubuntu-latest · Node 20 · `npm ci` · `npx playwright install --with-deps chromium`
  · `npm run build` · `npm run e2e` · `timeout-minutes: 20` so a stall fails the
  workflow · failure-only upload of `playwright-report/` + `test-results/`). The four
  existing jobs (Backend, Frontend, Docker, Security) are preserved unchanged; this is
  a fifth, independent job. The local **Windows** Playwright run **stalled without a
  completed run** — **no passing local E2E result is claimed**; the Linux
  `E2E — Playwright` job is the **authoritative Phase 10 browser gate**, and **PR #21
  must not merge unless it passes**. Existing Playwright tests are run unmodified — no
  skip, no extra retry, no suppression; `playwright.config.ts` is untouched.
- **OpenAPI contract determinism fix (`fix: make OpenAPI contract deterministic in CI`):**
  PR #21's first CI run (GitHub Actions run `28093861353`) failed only in
  `Backend — Pint, Larastan, Pest` → `Tests — Pest on PostgreSQL (parallel)` at
  `OpenApiContractTest:26` ("docs/api/openapi.json is stale") — 1 failed / 488 passed / 4
  skipped. The other four jobs (`E2E — Playwright`, Frontend, Docker, Security) had already
  passed on that run. **Root cause:** dedoc/scramble infers types by introspecting the live
  PostgreSQL schema, and `OpenApiContractTest` regenerated the document without
  `RefreshDatabase`, so a parallel worker whose database was not yet migrated read an empty
  schema and emitted fallback types (ULID ids → integer, booleans/counters → string,
  nullability lost) that diverged from the correctly-typed committed artifact — a real
  contract-determinism defect, not an external CI flake. **Fix:** `OpenApiContractTest` now
  `uses(RefreshDatabase::class)` (guaranteed migrated schema in serial Pest, parallel Pest
  and fresh CI), and `GenerateOpenApiCommand` (`composer api:openapi`) fails fast with a
  non-zero exit and no write if any core type-driving table (`merchant_branches`,
  `staff_profiles`, `staff_invitations`, `branch_operating_hours`, `audit_logs`) is absent.
  dedoc/scramble remains authoritative; the stale-contract assertion is not removed,
  skipped, weakened, mocked or bypassed; semantically-correct types are preserved (ULID/
  permission keys → string, weekday → integer, booleans → boolean, counters → integer,
  nullable → `string|null`). Regeneration is byte-deterministic (`composer api:openapi`
  twice → no diff); `docs/api/openapi.json` and `resources/spa/src/types/generated/api.ts`
  were already byte-current, so no artifact changed. Re-verified locally: full backend
  parallel suite 489 passed / 4 skipped / 2110 assertions; Pint, Larastan L8, composer
  validate, vue-tsc typecheck and `npm run api:contract:check` all clean.
- **Deferrals:** files/media → 10F; role nav → 11; business domains → owning §80
  phases; full per-table dict entries for audit/permissions/roles → 19.

### Pre-feature remediation gate closure (§5.4) (`docs/pre-feature-remediation-gate-closure`)

Documentation/evidence only — **no product code, migrations, routes, dependencies,
Dockerfiles, configuration, tests or frontend changed**.

- **Gate §5.4: CLOSED and effective** — the gate-closure PR #20 merged into
  `main` (merge commit `7ac20a5`, 2026-06-23; CI Backend/Frontend/Docker/Security
  all SUCCESS; reviewDecision blank under the solo-maintainer governance
  exception). All nine `PRE_FEATURE_REMEDIATION` items (Phase V + R1–R7) are
  `verified_complete`.
- **R7 finalized:** REM-OPS-001 → `verified_complete` — PR #19, merge commit
  `4f0d4f3`, CI Backend/Frontend/Docker/Security all SUCCESS, reviewDecision
  blank, governance exception `docs/governance/solo-maintainer-review-exception-pr-19.md`.
- **Register normalized:** REM-V-001 `merged` → `verified_complete`; register
  `meta.pre_feature_gate_closed: true`.
- **Completion report finalized** with the full §5.4 criteria matrix
  (`docs/remediation/pre-feature-completion-report.md`); gate-closure governance
  evidence recorded (`docs/governance/solo-maintainer-pre-feature-gate-closure-exception.md`,
  one eligible maintainer, no independent approval claimed).
- **Gate-closure proof:** `docs/proof/pre-feature-remediation-gate-closure.md`.
- **Phase 10 (API Foundation) has started** on branch `phase-10-api-foundation`
  now that the gate-closure PR #20 is merged. Stale gate-blocked and R7-pending
  status wording was replaced with the closed/effective state across PROGRESS.md,
  CHANGELOG.md, register.yaml and the completion report.

### Phase R7 — Production probes, CI isolation, environment parity (`phase-r7-production-probes-ci-parity`)

Closes REM-OPS-001 (`verified_complete`) — merged as PR #19 (squash `4f0d4f3`) —
makes production readiness truthful, isolates parallel/CI test infrastructure,
aligns runtime tooling, and records the accessible brand-token decision (Plan
§22, §24, §26, §76–77, §79 R7; ADR-009; Correction 7). Built on merged R6 `main`
(`57ae8db`, PR #18). CI Backend/Frontend/Docker/Security all SUCCESS;
solo-maintainer governance exception (reviewDecision intentionally blank — not
independent approval).

#### Probes
- **Liveness/readiness split.** `GET /health` is dependency-free liveness (200
  even when every dependency is down; no versions/hosts/secrets). `GET
  /health/deep` is strict readiness — **503** when any REQUIRED production
  dependency (`database`, `redis`, `cache`, `s3`) fails; optional dependencies
  (Meilisearch — Phase 22) only degrade (200). Mailpit is never a dependency.
- **Config-driven & redacted.** Required/optional sets and `require_configured`
  (production cannot silently treat a managed dependency as optional) live in
  `config/servana.php`. Probe bodies expose only safe names+statuses — no DSN,
  host, bucket, credential, SQL or exception detail.
- **Bounded timeouts.** `probe_timeout` (HTTP), Redis connection `timeout`, S3
  `http.connect_timeout`, and PG `PGCONNECT_TIMEOUT`. The prod **nginx**
  healthcheck now uses `/health/deep` (traffic eligibility); the app container
  keeps `php -v` liveness.

#### Test isolation & CI
- **Per-run + per-process namespace.** `tests/bootstrap.php` assigns a unique
  Redis + cache prefix (`servana_test_{runId}_{token}_`) from the CI run id and
  parallel-test token. Cache/session/queue already use array/sync (in-memory, per
  process — no shared store, **no FLUSHDB**); identical logical keys are isolated
  across namespaces. Proven by `RedisPrefixIsolation`/`Cache`/`RateLimit`/
  `ParallelTestIsolation` tests; three consecutive parallel backend runs are stable.

#### Runtime parity
- **PHP 8.3 / Node 20 / Composer 2** pinned across the app image, SPA/nginx build
  image, dev tooling, CI and machine-readable metadata (`package.json` `engines`
  + new `.nvmrc`). `RuntimeParityTest` fails on drift. Docker remains the
  canonical local runtime.

#### ADR-009
- Brand contrast decision recorded with **measured** ratios: dark Brand Deep on
  the Savannah-Orange CTA (≈ 4.92:1, AA) — white-on-orange (≈ 2.80:1) fails AA.
  `BrandContrastTokenTest` guards the committed tokens.

#### Tests & docs
- New: `tests/Feature/Api/{LivenessProbe,ReadinessProbe,ReadinessDependencyFailure,
  ProductionReadinessConfiguration}Test`, `tests/Feature/Security/HealthResponseRedactionTest`,
  `tests/Feature/Infrastructure/{RedisPrefixIsolation,CacheIsolation,RateLimitIsolation,
  ParallelTestIsolation,RuntimeParity}Test`, `tests/Unit/BrandContrastTokenTest`.
  Removed `tests/Feature/Api/DeepHealthTest` (migrated). ADR-009 + draft
  pre-feature completion report.
- **R6 corrected:** REM-SESS-001 = `verified_complete` (PR #18 merged, `57ae8db`,
  CI all SUCCESS, governance exception). REM-DOC-001 corrected to
  `verified_complete` (PR #12, `c58b64a`).

#### Known deferrals
- Full OpenAPI/route contract → Phase 10; file/media → Phase 10F; release-wide
  responsive/dark/a11y redesign + axe sweep → Phase 23; deployment/backups/
  alerting → Phase 25; Horizon/queue observability → Phase 21N/25. REM-OPS-001 =
  `verified_complete` (PR #19 merged, CI green).

### Phase R6 — Session & authorization revocation (`phase-r6-session-authorization-revocation`)

Closes REM-SESS-001 (`verified_complete`) — merged as PR #18 (squash
`57ae8db`) — completes credential revocation and per-request authorization
freshness so a suspension, deactivation or authority change takes effect no later
than the next authenticated request, with no stale session, token, membership,
role, branch or permission (Plan §79 R6; §9, §17–§19, §24; Correction 7). Built
on merged R5 `main` (`66aaead`, PR #17). CI Backend/Frontend/Docker/Security all
SUCCESS; solo-maintainer governance exception (reviewDecision intentionally
blank — not independent approval).

#### Added
- **Central revocation service** `app/Domain/Auth/Services/AccessRevocationService.php`
  — one idempotent, transactional domain service with `revokeForUser` /
  `revokeForMembership` / `revokeForMerchant`. Each revokes all database
  sessions, all Sanctum personal-access tokens (defence in depth — no token
  issuance surface exists; proven), all unconsumed Magic Links, and all
  applicable pending invitations. Returns a secret-free `RevocationSummary`
  (counts only) — `app/Domain/Auth/Support/RevocationSummary.php`.
- **Per-request active-principal gate** `app/Http/Middleware/EnsureActivePrincipal.php`
  — pinned after authentication and BEFORE `EnsurePrivilegedMfa` /
  `ResolveTenantContext` (bootstrap priority + route groups). A
  suspended/deactivated user (merchant or platform) is rejected 401 and its
  session torn down, even if a session survived revocation.
- **Tests** `tests/Feature/Auth/{SessionRevocation,AuthorizationFreshness,
  MagicLinkRevocation,SanctumTokenRevocation,InvitationRevocation,
  PermissionFreshness,BranchAssignmentFreshness,RevocationMiddlewareOrder}Test`,
  `tests/Feature/Security/MidSessionSuspensionTest` (real DB-backed session via
  the Magic Link login flow), `tests/Feature/Isolation/RevocationIsolationPostureTest`.

#### Changed
- **`StaffLifecycleService`** suspend/deactivate now delegate revocation to
  `AccessRevocationService` (adds Sanctum-token revocation) and attach the
  secret-free revocation counts to the existing membership lifecycle audit event
  (no new event, no duplication).
- **Logout** (`MagicLinkController`) invalidates the signed-in identity's
  unconsumed Magic Links; **Magic Link request** (`RequestMagicLink`) invalidates
  any previous unconsumed link so only the latest link is ever usable.
- **SPA** `apiClient` gains a loop-safe central 401 handler (registered in
  `main.ts`) that clears auth state and returns to login on a mid-session
  revocation — UX only; the backend remains the security boundary.

#### Security / freshness
- Membership, role, branch ids and the permission set are re-resolved from the
  database on every authenticated request (TenantContextResolver +
  PermissionResolver issue fresh queries; TenantContext caches per-request only).
  There is no cross-request authorization cache to invalidate; a second request
  re-queries authoritative state. Redis/cache/rate-limit prefix isolation is R7.
- The 404 cross-tenant / 403 same-tenant cross-branch contract is unchanged.
- No session id, token hash, Magic-Link value or invitation token is logged or
  audited; only aggregate counts. `audit:verify-chain` passes.

#### Known deferrals
- Redis/cache/rate-limit prefix isolation, liveness/readiness split, environment
  parity, ADR-009 → R7. Full route contract/OpenAPI → Phase 10. Future-domain
  revocation hooks → each owning feature phase. Release-wide browser/security
  hardening → Phase 23. REM-SESS-001 = `verified_complete` (PR #18 merged, CI
  green).

### Phase R5 — Tenant & branch schema hardening (`phase-r5-tenant-branch-schema-hardening`)

Closes REM-TEN-001 (`verified_complete`) — merged as PR #17 (squash
`66aaead`) — makes tenant/branch ownership structurally complete so every
existing branch-owned record is protected by `merchant_id` + `branch_id`, a
database consistency constraint, global scopes, scoped binding, and automated
coverage (Plan §2.1, §13.1, §79 R5; ADR-002). Built on merged R4 `main`
(`1288f48`, PR #16). CI Backend/Frontend/Security passed; the initial CI/Docker
run failed on an external Buildx/Docker Hub timeout and a rerun passed with no
product-code or Dockerfile change; solo-maintainer governance exception recorded
(`docs/governance/solo-maintainer-review-exception-pr-17.md`, reviewDecision
intentionally blank).

#### Added
- **Central registry** `app/Domain/Tenancy/TenantOwnership.php` — classifies every
  existing table (branch_owned / tenant_owned / exempt-with-reason); the single
  source of truth for the coverage tests.
- **Migrations (forward-only, expand→backfill→constrain):**
  `2026_06_23_000001` adds `UNIQUE (id, merchant_id)` to `merchant_branches`,
  `staff_profiles`, `merchant_users`; `…000002` adds `merchant_id` to the five
  branch-owned tables (`branch_user_assignments`, `branch_operating_hours`,
  `branch_calendar_exceptions`, `branch_day_records`, `branch_cash_ups`);
  `…000003` adds `merchant_id` to `staff_history` and
  `merchant_user_permission_overrides`. Each: backfill from the parent
  (parameterized, fail-safe), NOT NULL, index, `merchant_id → merchants` FK
  (RESTRICT), and a **composite consistency FK** `(fk, merchant_id) → parent(id,
  merchant_id)`.
- **Data dictionary** `docs/architecture/data-dictionary/branches-and-staff.md`
  (authored before the migrations, Plan §13.2).
- **ADR-002** (`docs/architecture/adr/0002-tenancy-enforcement-model.md`).
- **Tests** `tests/Feature/Tenancy/{TenantColumnCoverage,TenantBackfillMigration,
  TenantBranchConsistencyConstraint,ModelTenancyTraitCoverage}Test` +
  `tests/Feature/Isolation/{CrossTenantBranchOwnedModel,RouteBindingTenantSafety}Test`.

#### Changed
- **Models** gain `BelongsToMerchant` (+ `BelongsToBranch` on the four branch
  models): `BranchOperatingHour`, `BranchCalendarException`, `BranchDayRecord`,
  `BranchCashUp`, `BranchUserAssignment` (BelongsToMerchant only — BranchScope
  would be circular for the branch-assignment authority), `StaffHistory`,
  `MerchantUserPermissionOverride`. Factories derive `merchant_id` from the branch.
- **Creation sites** set `merchant_id` from the branch/parent:
  `AcceptStaffInvitation` (no tenant context — explicit), `StaffLifecycleService`,
  `OpenBranchDay`, `CloseBranchDay`, `BranchOperatingHoursController`,
  `PermissionOverrideService`.

#### Security
- Every branch-owned record now carries `merchant_id` + `branch_id` with a DB
  composite FK that **rejects** a merchant/branch mismatch (proven by inserting
  via `DB::table`, bypassing the model). Route-bound owned models resolve only
  within merchant scope (foreign ULID → 404 + `unauthorized_access` audit;
  same-tenant out-of-branch → 403 `no_branch_scope`, unchanged).
  `idempotency_keys` and `audit_logs` remain cross-cutting (nullable merchant by
  design).

#### Tests / proof
- `pint` clean, `stan` L8 0 errors, `composer validate` valid, backend **370
  passed / 4 skipped** (serial + parallel), vitest **77**, e2e **30** (after one
  documented flake + one webServer-timeout rerun), `composer audit` / `npm audit`
  / `gitleaks` clean, both Docker images build. Proof: `docs/proof/phase-r5.md`.

#### Known deferrals
- Session/token/link/invitation/cache revocation + per-request membership/role
  freshness → R6; readiness/parity → R7; migration manifest + full route contract
  → Phase 10; future tenant/branch tables → each owning feature phase; invoice/
  payment/queue/personnel isolation rows → Phases 16–19. REM-TEN-001 =
  `verified_complete` (PR #17 merged).

### Phase R4 — Idempotency & replay protection (`phase-r4-idempotency-replay-protection`)
*(Merged via PR #16, commit `1288f48`; CI Backend/Frontend/Security/Docker passed;
REM-IDEMP-001 `verified_complete` under a documented solo-maintainer governance
exception — `reviewDecision` blank, not an independent approval.)*

Closes REM-IDEMP-001 — the corrected `idempotency_keys` store + a
reusable middleware so duplicate, concurrent and recoverable-retry requests
produce exactly one durable effect, and a completed request replays its stored
(encrypted, sanitised) response (Plan §13.5, §24.4, §79 R4; ADR-003). Built on
merged R3 `main` (`c0402b2`, PR #15).

#### Added
- **Schema (forward-only, §13.5 corrected):** migration
  `2026_06_22_000003_create_idempotency_keys_table` —
  `UNIQUE(idempotency_scope, key_hash)`, indexes `(state, lock_expires_at)` +
  `(expires_at)`, `state` CHECK; `key_hash` = SHA-256(raw key); encrypted
  `response_body_encrypted`; FKs actor `SET NULL`, merchant/branch `RESTRICT`.
  Data-dictionary entry authored first (Plan §13.2).
- **Domain** `app/Domain/Idempotency/*`: `IdempotencyKey` model + `IdempotencyState`
  enum; `IdempotencyStore` (claim/complete/fail; `INSERT ON CONFLICT DO NOTHING`
  first-claim + `SELECT … FOR UPDATE` resolution); `CanonicalRequestHasher`;
  `IdempotencyScopeResolver`; `ReplayResponseSanitizer`; `ClaimResult`/`ClaimOutcome`;
  `ProviderReplayGuard` + `ProviderClaimResult`; idempotency exceptions.
- **Middleware** `EnsureIdempotentRequest` (§24.4 algorithm; key 16–255;
  replay / 409 conflict / 409 in-progress+Retry-After / reclaim; stable-4xx replay;
  redacted server failure) pinned last in `bootstrap/app.php` priority.
- **Classification seam** `RouteClass` + `RouteClassification` (`route_class`
  default) — extendable by Phase 10 without replacement.
- **Command** `idempotency:prune` (bounded; never deletes an active lock) scheduled
  daily; config in `config/servana.php` + `.env.example`.
- **ADR-003** (`docs/architecture/adr/0003-idempotency-and-replay-protection.md`).
- **Tests** `tests/Feature/Idempotency/*` (9 suites) +
  `tests/Feature/Security/FinancialRouteIdempotencyCoverageTest` (41 tests); a
  `testing`-only route harness (no production financial route ships).

#### Changed
- `bootstrap/app.php` — `EnsureIdempotentRequest` added to middleware priority
  (after authorization, immediately before the controller; §9.4 step 16).
- `routes/api.php` — `testing/idempotency/*` harness (financial-classified) +
  `RouteClassification` import. `routes/console.php` — prune schedule.

#### Security
- Only `SHA-256(raw key)` is stored; the response body is encrypted at rest; only a
  `content-type` header allowlist is persisted/replayed (never cookies, auth,
  XSRF, session, CSP, signed-URL, server, or debug headers); server failures store
  only a redacted code. PostgreSQL (unique constraint + row locks) is the
  concurrency boundary, not process memory.

#### Tests / proof
- `pint` clean, `stan` L8 0 errors, `composer validate` valid, backend **351
  passed / 4 skipped** (serial + parallel), vitest **77**, e2e **30** (after one
  documented `auth-magic-link` flake rerun), `composer audit` / `npm audit` /
  `gitleaks` clean, both Docker images build. Proof: `docs/proof/phase-r4.md`.

#### Known deferrals
- Full route-classification/OpenAPI contract → Phase 10; real invoice/payment/
  refund attachment → Phases 17–18; M-Pesa callback/inbox/receipt dedupe → Phase
  20D; billing/payout/compensation → owning Phase 20 subphases; tenant schema → R5;
  session revocation → R6; readiness → R7. REM-IDEMP-001 = `verified_complete`
  (PR #16, `1288f48`).

### Phase R3 — Privileged MFA & step-up (`phase-r3-privileged-mfa-step-up`)
*(Merged via PR #15, commit `c0402b2`; CI Backend/Frontend/Security/Docker passed;
REM-MFA-001 `verified_complete` under a documented solo-maintainer governance
exception — `reviewDecision` blank, not an independent approval.)*

Closes REM-MFA-001 — real privileged TOTP MFA, one-time recovery
codes, mandatory enforcement for Super Administrator / Merchant Administrator /
Finance, and a reusable fresh step-up control (Plan §17, §18, §79 R3). Built on
merged R2 `main` (`1df759e`, PR #14).

#### Added
- **Schema (forward-only):** `mfa_credentials` (encrypted TOTP secret;
  `UNIQUE(user_id,type)`; type `CHECK('totp')`; `last_used_timestep` replay
  guard; `user_id` FK RESTRICT) and `mfa_recovery_codes` (SHA-256 `code_hash`;
  single-use `used_at`; `UNIQUE(code_hash)`; index `(user_id, used_at)`).
  Migrations `2026_06_22_000001/000002`. Data-dictionary entry authored first
  (`docs/architecture/data-dictionary/core-identity-and-tenancy.md`, Plan §13.2).
- **TOTP** via `pragmarx/google2fa` v8.0.3 (RFC 6238, constant-time). Domain
  services `app/Domain/Auth/Mfa/*`: `TotpProvider`, `RecoveryCodeManager`,
  `MfaRequirementResolver`, `MfaSession`, `MfaManager`, `MfaStatus`,
  `MfaAuditLogger`, `StepUpAction`.
- **Models/factories** `MfaCredential`, `MfaRecoveryCode` (secret/hash hidden).
- **Middleware** `EnsurePrivilegedMfa` (mandatory-role gate, pinned after auth
  and before tenant context) and `RequireFreshMfa` (reusable step-up).
- **API** `GET /api/v1/auth/mfa` + `POST /auth/mfa/{enroll,confirm,challenge,
  recovery-challenge,recovery-codes}`; `MfaCodeRequest`; `mfa-confirm` /
  `mfa-challenge` rate limiters; MFA exceptions rendering the §11.5 envelope
  (`mfa_enrollment_required`, `mfa_challenge_required`, `mfa_invalid_code`,
  `step_up_required`, `mfa_invalid_state`).
- **8 MFA `AuditEvent` cases** (enrollment started/confirmed, challenge
  succeeded/failed, recovery code used, recovery codes regenerated, step-up
  succeeded/denied).
- **Frontend** `MfaSetup.vue`, `MfaChallenge.vue`, `authStore` MFA state +
  actions, a UX-only router guard, and the `mfa` block in the bootstrap payload.
- **Tests** 8 backend suites (`tests/Feature/Auth/Mfa*`, `tests/Feature/Security/
  MfaSecretRedactionTest`), authStore vitest cases, and `tests/e2e/mfa.spec.ts`.
  A test-only step-up harness exercises every `StepUpAction` (no fake business
  routes ship).

#### Changed
- `MfaController` — real flow replaces the `mfa_not_enabled` placeholder.
- `bootstrap/app.php` — `EnsurePrivilegedMfa` pinned between auth and
  `ResolveTenantContext` (Plan §9.4 step 2).
- `routes/api.php` — `auth/mfa` group, `EnsurePrivilegedMfa` on the authenticated
  group, and a `testing`-only step-up/privileged-probe harness.
- `AuthenticatedUserResource` — safe `mfa` state block.
- `AppServiceProvider` — MFA rate limiters. `config/servana.php` — `mfa` config
  (issuer, totp window, recovery-code count, step-up window).

#### Security
- TOTP secret encrypted at rest (`encrypted` cast); recovery codes stored only as
  SHA-256 hashes and shown once. Replay blocked via `last_used_timestep`. The MFA
  assertion lives only in the server session (`mfa_verified_at`), regenerated on
  challenge and cleared on logout — the Magic Link is never the MFA assertion. No
  secret / code / session id is logged or written to the audit trail;
  `audit:verify-chain` passes after MFA events.

#### Tests / proof
- `pint` clean, `stan` L8 0 errors, `composer validate` valid, backend **311
  passed / 4 skipped**, `audit:verify-chain` exit 0, vitest **77 passed**,
  `npm run build`, e2e **30 passed**, `composer audit` / `npm audit` / `gitleaks`
  clean, both Docker images build (dev + prod). Proof: `docs/proof/phase-r3.md`.

#### Known deferrals
- Step-up attachment to real business routes → owning phases (billing 20A; refund
  finalization & period reopen 18B; payout approval/mark-paid 20H; M-Pesa
  reconciliation 20D; backdated compensation 20F/20G). WebAuthn/passkeys &
  SMS/email OTP → later security enhancement. Administrator MFA reset/recovery →
  future account-recovery phase. Complete per-request revocation → R6.
  REM-MFA-001 = `verified_complete` (PR #15, `c0402b2`).

### Phase R2 — Core audit completeness (`phase-r2-core-audit-completeness`)
*(Merged via PR #14, commit `1df759e`; CI Backend/Frontend/Security/Docker passed;
REM-AUD-001 `verified_complete` under a documented solo-maintainer governance
exception — `reviewDecision` blank, not an independent approval.)*

Closes (locally) REM-AUD-001 — completes CORE audit-event coverage, hash-chain
verification, and secure masked audit reads for the already-implemented domains
(Plan §70, §79 R2; ADR-008). Financial/billing/M-Pesa/compensation/SMS/file/
export coverage and the flagged-event workflow remain Phase 19.

#### Added
- **Canonical typed event catalogue** `app/Domain/Audit/Enums/AuditEvent.php` —
  one snake_case action per transition with central `severity()`; existing Phase
  8/9 strings preserved. `AuditRecorder::record()` now takes an `AuditEvent`.
- **`AuditChainHasher`** — single canonical hash shared by recorder + verifier.
- **`AuditValueMasker`** — recursive server-side masking (email/phone/reference/
  token/secret/restricted) for `context`/`actor_label`.
- **`AuthAuditLogger`** — writes auth events to `audit_logs` (masked email, null
  actor; no token/session stored).
- **`audit:verify-chain`** Artisan command — verifies per-merchant + platform
  chains; detects altered/forged/reordered rows; no mutation; exit non-zero on
  failure; `--merchant`/`--platform` filters.
- **Masked read API** — `GET /api/v1/audit-logs(+/{auditLog})` (merchant;
  `audit.view_full`) and `GET /api/v1/platform/audit-logs(+/{auditLog})`
  (platform; `platform.audit.view`); paginated, allowlisted filters/sort;
  `AuditLogResource` (ULIDs only, masked). No write/delete routes.
- **`AuditLogPolicy`** — read-only; merchant + branch scope; platform separation;
  foreign-tenant 404. Registered in `AppServiceProvider`.
- **ADR-008** (`docs/architecture/adr/0008-audit-immutability-and-chain.md`).
- Migration `2026_06_21_000001_add_branch_id_to_audit_logs` (forward-only expand;
  nullable FK, indexed; part of the hash).
- 7 Audit feature tests (`tests/Feature/Audit/*`, 30 tests).

#### Changed
- `DatabaseAuditRecorder` — per-merchant + platform chains, advisory-lock
  serialization, `branch_id`, shared hasher.
- Core coverage wired into actions/services (auth, invitations, membership/staff
  lifecycle, branch lifecycle + day, branch assignment, permission overrides,
  unauthorized access) — in-transaction, with old/new values where sensitive.
- `AuditLog` model gains `branch_id` + `branch()` relation.
- Existing tests updated for the new recorder API and the audit-to-DB move (not
  weakened): `Auth/AuditReadOnlyTest`, `Security/MagicLinkTokenSecurityTest`.

#### Removed
- `AuthEventLogger` and `AuthEvent` (replaced by `AuditRecorder`/`AuditEvent`;
  no parallel audit system).

#### Security
- No raw Magic Link token, session id, full email (where masked), or request
  body is stored. `audit_logs` remains append-only (DB trigger; re-asserted).
  Chains are tamper-evident and independently verifiable per tenant.

#### Tests / proof
- `pint` (271), `stan` L8 (192, 0), backend **268 passed / 4 skipped**
  (serial + parallel), `audit:verify-chain` exit 0, vitest 72, build, `composer
  audit`/`npm audit`/`gitleaks` clean, both Docker images build. e2e: known
  `auth-magic-link` flake (26/1 then 27/0). Proof: `docs/proof/phase-r2.md`.

#### Known deferrals
- Full audit coverage + flagged-event workflow + exceptional unmasking → Phase 19;
  chain-failure alerting/scheduling → Phase 25; audit dashboard → Phase 11/19;
  audit export/signed delivery → Phase 19/23. REM-AUD-001 = `verified_complete`
  (PR #14, `1df759e`).

### Phase R1 — Dependency & runtime security (`phase-r1-dependency-runtime-security`)
*(Merged via PR #13, commit `8fe575f`; CI Backend/Frontend/Security/Docker passed;
REM-DEP-001 `verified_complete` under a documented solo-maintainer governance
exception — `reviewDecision` blank, not an independent approval.)*

Closes REM-DEP-001 — formalizes and re-verifies the Laravel 12 upgrade
delivered by PR #11 (`cbcf50c`). **No application/source/`composer.*`/Docker/CI
code changed in R1**; it adds the missing governance/evidence and re-runs the
gates.

#### Added
- `docs/architecture/adr/0001-framework-upgrade.md` (**ADR-001**): Laravel 12.60+
  on PHP 8.3 canonical (Docker), advisory-removal + dependency rationale,
  runtime parity, schema compatibility, rollout, rollback limitation,
  forward-repair, consequences. (Laravel 12 is **not** LTS.)
- `docs/operations/laravel-12-upgrade.md`: before/after versions, PR #11 + merge
  commit, packages changed, the `LogUnauthorizedAttempt` compat change, security
  tests, rebuild procedure incl. the **servana-vendor named-volume** warning,
  DB/cache results, deploy sequence, rollback, residual risks.
- `docs/proof/phase-r1.md`: full R1 verification evidence.

#### Verified (no code changes)
- Laravel **12.62.0** (≥12.60); PHP **8.3.31** across app/worker/scheduler
  (same image), CI, and prod compose; composer platform 8.3.31.
- `composer validate --strict` valid; `composer audit --locked` **0 advisories,
  0 suppressions**; guzzle 7.12.1 + psr7 2.12.1 retained.
- Security regressions: `EmailHeaderInjectionTest` (4) — embedded CR/LF rejected;
  `SignedUrlIntegrityTest` (4) — valid accepted, query-tamper/path-confusion/
  expiry rejected.
- DB/cache: clean disposable PG16 `migrate:fresh --seed` (26 + PermissionSeeder);
  Redis ping/round-trip; `cache:clear`; worker/scheduler boot on 8.3 image. No
  schema change from the upgrade.
- Full gates green: pint (254), Larastan L8 (0), backend **238 passed / 4
  skipped** (serial + parallel), vitest **72**, build, lint/typecheck (0),
  `npm audit` 0, gitleaks clean, both Docker images build.

#### Known deferrals
- e2e: first run 26/1 (intermittent `auth-magic-link` flake), reruns 27/0 —
  recorded, not erased; stabilization → Phase 23.
- REM-DEP-001 = `local_complete`; `verified_complete` only after PR merge, green
  CI, and the Plan-required **second reviewer**. Readiness/env-parity (R7), audit
  (R2), MFA (R3), idempotency (R4), tenant-schema (R5), revocation (R6) remain.

### Phase V — As-built verification (`phase-v-as-built-verification`)
*(Merged via PR #12, commit `c58b64a`; CI Backend/Frontend/Security/Docker passed.)*

#### Added
- Verification evidence (clean-environment, container-derived):
  `docs/verification/evidence/{versions.txt,migrations.txt,schema.sql,routes.json,test-results.md,security-results.md}`.
- `docs/verification/as-built-discrepancies.md` — evidence-based status for every
  Plan §4 claim (confirmed/partially_confirmed/contradicted/not_verifiable).
- `docs/remediation/register.yaml` — seeded all Plan §5.3 items + Phase V
  discoveries (REM-DOC-001) with evidence-based statuses and gating categories.
- `docs/traceability/servana-requirements.csv` — Plan §85 traceability matrix
  (foundation rows for implemented domains).
- `docs/proof/phase-v.md`.

#### Verified (no code changes)
- Runtime/deps from lock files **and running containers**: Laravel **12.62.0**,
  PHP **8.3.31**, Sanctum 4.3.2, PostgreSQL 16.14, Redis 7.4.9, Meilisearch
  1.10.3; PHP 8.3 pinned across Dockerfile/CI/composer; `composer audit` clean
  (advisory ignore removed).
- Clean `migrate:fresh` (26 migrations) on a disposable DB; audit_logs
  immutability trigger runtime-proven (UPDATE/DELETE blocked); 18 CHECK / 40 FK /
  34 UNIQUE / 0 exclusion constraints.
- Forbidden routes proven absent (Super-Admin merchant creation; personnel
  contact export). Full suite re-run: backend **238 passed / 4 skipped**,
  frontend **72**, e2e **27** (axe AA); Pint/Larastan/validate/audit; `npm audit`
  0; gitleaks clean; both Docker images build.

#### Findings / deferrals
- C0 pre-feature items open: REM-DEP-001 (**partial** — L12 upgrade landed via PR
  #11 but ADR-001/proof/notes missing, R1 still required), REM-AUD-001,
  REM-MFA-001, REM-IDEMP-001 (no idempotency_keys table), REM-TEN-001 (branch
  tables lack `merchant_id`), REM-SESS-001. C1: REM-OPS-001, REM-DOC-001 (closed).
- The pre-feature remediation gate (§5.4) is **not** closed; no Section 80
  feature phase may begin.

#### Documentation
- Plan §4 refreshed with §4.1 verified outcomes; `CLAUDE.md` stack (Laravel
  11→12.62) and roadmap (§27 → §§79–80) references corrected; `PROGRESS.md`
  regenerated onto the v3 roadmap (Phase 9 → merged #9; #11 L12 upgrade; #10 v3
  docs); this changelog.

### Phase 9 — Tenant-scoped data access hardening (`phase-9-tenant-scoped-data-access-hardening`)

#### Added
- Tenancy traits + global scopes (Plan §8.2): `BelongsToMerchant` (MerchantScope +
  `merchant_id` auto-fill on create, throwing `MissingTenantContext` when unscoped),
  `BelongsToBranch` (BranchScope; merchant-wide roles restricted to own-merchant
  branches via subquery). `withoutTenancy()` is the only sanctioned escape.
- Scoped route-model binding: `resolveRouteBinding()` resolves within merchant scope
  → foreign-tenant ULID 404s (no existence leak) and writes an `unauthorized_access`
  audit row. `bootstrap/app.php` pins `ResolveTenantContext` before
  `SubstituteBindings`; `ResolveTenantContext::terminate()` resets context per request.
- `LogUnauthorizedAttempt` (UnauthorizedAccessRecorder): unscoped existence check →
  high-severity `unauthorized_access` audit (actor, merchant, model, attempted ULID,
  route, correlation id); never leaks the foreign row or request body. `EnsureBranchScope`
  audits its foreign-branch 404 path too.
- `TenantAwareJob` + `MissingTenantContext` (Plan §8.3): captures merchant/branch ids,
  rehydrates + re-validates context in `handle()`, fails safely when absent or the
  merchant is not active. `TenantContext::bindForJob()`.
- PHPStan tenancy rules activated: `NoWithoutTenancyOutsidePlatformRule`,
  `NoRawSqlConcatRule` (no-ops since Phase 1). Plus `TenancyStaticAnalysisTest` source
  scan (escape hatches outside Tenancy/Platform; `::find()` in controllers; raw-SQL concat).
- Tests: `TenantAwareJobTest`, `Isolation/{RouteBinding,CrossTenantAccess,
  CrossBranchAccess,UnauthorizedAccessAudit,PermissionDeniedStillWorks,
  FutureResourceIsolation}Test`, `Security/{SuspendedMerchant,TenancyStaticAnalysis}Test`.
  Future-resource §8.4 rows (invoices/payments/exports/personnel) are permanent skipped
  tests naming Phases 16–19.

#### Changed
- Tenant-owned models (`MerchantProfile`, `MerchantUser`, `MerchantStatusHistory`,
  `MerchantBranch`, `StaffInvitation`, `StaffProfile`) and branch-owned models
  (`BranchOperatingHour`, `BranchCalendarException`, `BranchDayRecord`, `BranchCashUp`)
  now apply the tenancy traits. Excluded (documented in proof): `Merchant`,
  `BranchUserAssignment`, `StaffHistory`, `MagicLoginToken`, permission registry models,
  `MerchantUserPermissionOverride`, `AuditLog`, `User`.

### Phase 8 — Roles & permissions (`phase-8-roles-permissions`)

#### Added
- Permission schema (Plan §10.3): `permissions`, `roles`,
  `role_permission_assignments`, `merchant_user_permission_overrides`, and the
  real append-only, hash-chained `audit_logs` (DB trigger blocks UPDATE/DELETE).
  Forward-only; `merchant_users` untouched (role still lives there).
- `PermissionRegistry` — the canonical §10.3 matrix (54 keys × 8 roles);
  `PermissionSeeder` materialises it (82 default grants); `PermissionResolver`
  resolves role defaults ± per-user overrides (deny beats grant; suspended/
  deactivated → no permissions; read-only `audit` can never gain a mutating key).
- `EnsurePermission` middleware (missing key → 403 `permission_denied`) on the
  mutating Branch routes; policies for Merchant/MerchantBranch/MerchantUser/
  StaffInvitation/StaffProfile/BranchOperatingHour/BranchDayRecord (Plan §10.4).
- Audit foundation (Plan §22.2): `AuditRecorder` contract + `DatabaseAuditRecorder`
  (hash-chained). Permission override created/updated/revoked → high severity;
  denied self-escalation + denied audit/write attempts → warning.
- Per-membership overrides API (admin/HR, audited, anti-self-escalation):
  `POST`/`DELETE /api/v1/staff/{staff}/permissions`. HR permission preview:
  `GET /api/v1/hr/permission-preview` and `GET /api/v1/staff/{staff}/permissions`.
- `/api/v1/me` now returns the resolved `permissions[]` (request-cached in
  `TenantContext`).
- SPA: real `permissionStore` (sourced from `/me`), `useCan` composable,
  `PermissionGate` component, HR `PermissionPreview` page; the branch "Add branch"
  action is gated on `branches.create`.
- Tests: 8 backend Auth suites (PermissionMatrix [zero mismatches], Authority
  Boundaries, HrSelfEscalation, AuditReadOnly, PermissionOverrideAudit,
  PermissionMiddleware, PermissionPreview, MePermissionsBootstrap); Vitest +1
  (gate visibility). Matrix proof: `docs/proof/phase8-matrix.txt`.

#### Changed
- Branch/Staff controllers: coarse inline `assert*` role checks replaced by
  `EnsurePermission` (branch create/archive → `branches.create`; profile/hours →
  `branch.profile.manage`; day → `day.open_close`) and policies (staff lifecycle
  + invitations). Branch profile/hours/day editing is now a Branch Manager
  capability (matrix §10.3), not Merchant Admin — affected branch tests updated.

### Phase 7 — Branches, memberships, invitations (`phase-7-branches-memberships-invitations`)

#### Added
- Branch lifecycle (Scope §3.3): admin-only branch CRUD, weekly operating hours,
  day open/close records, and `BranchClosureGuard` enforcing the §3.3 blockers
  (unclosed day + cash-up discrepancy now; queue/session/invoice/payment/receipt/
  appointment as explicit named stubs for Phases 16–18) + `BranchDebtGate` stub.
- Branch scope (Plan §8.2): `branch_user_assignments` (partial-unique active per
  member+branch), `EnsureBranchScope` middleware (foreign ULID → 404, missing
  assignment → 403 `no_branch_scope`), `/api/v1/me` `branch_ids`.
- Staff invitations (Scope §3.4): `staff_invitations` (SHA-256-hashed 72h token),
  create/resend/revoke + atomic public accept (`AcceptStaffInvitation` →
  user + active membership + `staff_profiles` + active branch assignment +
  append-only `staff_history`). `StaffInvitationNotification` (raw token only in
  the emailed link). Duplicate-pending invite blocked; duplicate active staff
  phone/email blocked (partial unique index).
- `StaffLifecycleService`: transactional activate/suspend/deactivate/assignBranch/
  revoke; suspend/deactivate revoke DB sessions + unused Magic Links + pending
  invites; sole-active-admin orphan guard; branch-assignment-required-to-activate.
- Schema: `staff_profiles`, `staff_history`, `branch_operating_hours`,
  `branch_calendar_exceptions`, `branch_day_records`, `branch_cash_ups` (seam);
  `merchant_branches` expanded forward-only. Enum-backed statuses + DB CHECKs.
- HTTP: branch + staff-invitation + staff controllers, requests, resources;
  routes under EnsureMerchantActive (+ EnsureBranchScope on `{branch}` routes);
  public `POST /staff-invitations/accept` (`invitation-accept` limiter).
- SPA: branch list/create/detail/operating-hours, staff list (status badges)/
  invitations/public accept/profile; `branchStore` + `staffStore`; routes wired.
- Tests: 51 backend (branches/hr/isolation/security/auth check-6), Vitest +20
  (branch/staff stores + pages), Playwright +7 (`branches-staff-invitations`).

#### Changed
- `LoginEligibilityService` check 6 now enforced — a branch-scoped role requires
  an active branch assignment to receive a Magic Link (admin/platform exempt).
- `TenantContext` carries branch scope and `reset()`s on each resolution so a
  reused (scoped) instance never leaks a stale merchant.
- `MagicLinkTokenService::invalidateUnconsumedForEmail()` added for lifecycle
  revocation.

#### Fixed
- DB-default `status` columns are now mirrored via model `$attributes` so a
  freshly created branch/invitation has a status in memory before refresh.

### Phase 6 — Account & tenant model (`phase-6-account-tenant-model`)

#### Added
- Merchant Administrator self-registration (Scope §3.1/§3.2): public
  `POST /api/v1/merchant-registration/self-register` (`registration` limiter,
  uniform 202 — no enumeration) → `RegisterMerchant` action creates user +
  merchant (`pending_setup`) + shell profile + `merchant_admin`/`active`
  membership + status-history row in one transaction, then emails the owner a
  Magic Link. No Super Admin approval, no KYC — neither route nor UI exists.
- First-time setup (Scope §3.2 steps 1–7): `GET`/`POST`
  `/api/v1/merchant-registration/first-time-setup` (gated by
  `EnsureFirstTimeSetupAccess`: pending_setup + merchant_admin). Transactional
  `CompleteFirstTimeSetup` action — tier, profile, ≥1 branch, initial
  Branch+HR invited memberships (auto-selected to the single branch), welcome
  emails, then flips merchant → `active` + records status history.
- Tenant context (Plan §8.1): `TenantContext` (request-scoped),
  `TenantContextResolver`, `ResolveTenantContext` middleware, `EnsureMerchantActive`
  + `EnsureFirstTimeSetupAccess` gates, `TenantAccessException` (envelope codes
  `no_tenant_context` / `merchant_suspended` / `pending_setup_only` /
  `setup_already_completed`). Merchant dashboard shell `GET /api/v1/merchant/dashboard`.
- Schema (forward-only): `merchants`, `merchant_profiles`, `merchant_users`,
  `merchant_status_histories`, minimal `merchant_branches` (Phase 6 seam — full
  branch lifecycle is Phase 7); `is_platform_staff` added to `users`. Enum-backed
  statuses + DB CHECK constraints (MerchantStatus, MerchantUserStatus,
  MerchantUserRole, ServiceFeeTier, BranchStatus).
- `/api/v1/me` bootstrap now returns `{ user, merchant, membership, memberships,
  permissions, setup }` from the resolved tenant context (Plan §6.2).
- SPA: `RegisterMerchant.vue`, 4-step `FirstTimeSetup.vue` wizard, merchant
  `Dashboard.vue` shell; `onboardingStore`; `authStore`/`merchantStore` rewired
  to the new bootstrap; global `router.beforeEach` awaits session bootstrap
  before guards; `requiresPendingSetup` guard + pending→wizard routing.
- `StaffWelcomeNotification` (safe, tokenless welcome for invited Branch/HR;
  full invitation-accept flow is the Phase 7 seam).
- Tests: 40 backend onboarding/tenancy/security tests; Vitest +13 (RegisterMerchant,
  FirstTimeSetup, merchantStore, authStore tenant bootstrap); Playwright +4
  (`merchant-onboarding` incl. axe on registration + wizard).

#### Changed
- `LoginEligibilityService`: Scope §2.3 checks 2 & 4 now enforced
  (`User::hasTenantAccess` — active membership or platform staff);
  `AUTH_ENFORCE_TENANCY_ELIGIBILITY` now defaults `true`. Check 6 (branch
  assignment) stays deferred to Phase 7 (always passes for now).
- `AuthenticatedUserResource` reshaped to the tenant-aware bootstrap; Magic Link
  verify populates tenant context before responding.

### Phase 5 — Authentication (Magic Link + sessions) (`phase-5-authentication`)

#### Added
- Magic Link authentication (Plan §9.1): `POST /api/v1/auth/magic-link` (uniform
  202, no enumeration), `POST /api/v1/auth/magic-link/verify` (atomic single-use
  consume → Sanctum session login with session-id regeneration; uniform 422
  `invalid_or_expired_token`), `POST /api/v1/auth/logout` (204), `GET /api/v1/me`.
- `Domain/Auth/*`: `MagicLoginToken` model; `MagicLinkTokenService` (64 random
  bytes → base64url, SHA-256 at rest, 15-min expiry, atomic conditional-UPDATE
  single-use); `LoginEligibilityService` (seven-check contract, Scope §2.3);
  `RequestMagicLink`/`ConsumeMagicLink` actions; `MagicLoginLinkNotification`
  (branded, `mail` queue); `AuthEventLogger` (interim redacted audit until Phase 8);
  `InvalidMagicLinkException`; `AuthEvent` enum; `EligibilityResult` VO.
- `magic_login_tokens` table; auth-owned expand of `users` (`ulid`, `status`,
  `last_login_at`; `password` made nullable — Plan A3 "no password column").
- Laravel Sanctum `^4.3` installed; SPA stateful mode (`statefulApi()` + `sanctum`
  guard); `EnforceIdleTimeout` middleware (60-min sliding, Plan §9.2).
- SPA auth pages `Login.vue`/`CheckEmail.vue`/`Verify.vue` (replace Phase 4 stubs);
  `authStore` real `bootstrap/requestMagicLink/verifyMagicLink/logout`; bootstrap
  on app mount; typed `apiError` on `AxiosError`.
- `config/servana.php` (frontend URL, idle timeout, tenancy-eligibility flag);
  `sanctum` guard in `config/auth.php`.
- Tests: 8 backend auth/security suites (28 tests), Vitest authStore/Login/Verify
  (11 tests), Playwright `auth-magic-link` (5 tests incl. axe on all auth pages).

#### Fixed
- Test environment now overrides docker-injected `$_SERVER` vars via
  `tests/bootstrap.php` (previously the suite ran on the shared redis cache,
  causing rate-limiter bleed and non-deterministic sessions).
- Dev `worker` now consumes the `mail` queue (`queue:work --queue=mail,default`)
  so Magic Link emails are actually delivered.

#### Security
- Raw tokens never stored (only SHA-256), never logged (masked-email audit only),
  never returned in API responses. Single-use, 15-min expiry, uniform 202/422 to
  prevent account enumeration; named Magic Link rate limiters → structured 429.

#### Deferred
- Eligibility checks 2/4 (membership/role) → Phase 6; check 6 (branch) → Phase 7
  (seam methods + `AUTH_ENFORCE_TENANCY_ELIGIBILITY` flag in place). Instant
  suspension revocation of sessions → Phase 7. Real MFA/TOTP → later phase
  (safe `MfaController` placeholder only). No Phase 6 tenant schema created.

---

### Phase 4 — Frontend foundation (`phase-4-frontend-foundation`)

#### Added
- 8 role-based layout shells: `AuthLayout`, `PlatformAdminLayout`, `MerchantLayout`,
  `BranchLayout`, `FrontOfficeLayout`, `PersonnelLayout`, `FinanceLayout`, `AuditLayout`.
  Each includes skip link, accessible landmarks (`header`, `nav`, `main`), `.dark`-compatible
  tokens (Plan §6.1, §15.9).
- Router foundation: `router/index.ts` integrating 9 route modules, `router/guards.ts`
  with UX-only stubs for `requiresAuth`, `requiresRole`, `requiresPermission`,
  `requiresActiveMerchant` (Plan §6.2).
- 6 typed Pinia stores: `authStore`, `merchantStore`, `branchStore`, `permissionStore`,
  `themeStore` (persists to `localStorage`), `notificationStore`.
- `services/apiClient.ts`: single axios instance (`baseURL=/api/v1`, `withCredentials`,
  CSRF priming helper), response interceptor mapping Phase 3 error envelope to typed
  `ApiError { code, message, fields, meta }` (Plan §6.3, §11.5).
- `composables/useForm<T>`: typed values, dirty, touched, errors, `submitting`,
  `reset()`, `setFieldError()`, `mergeServerErrors(ApiError)`, `handleSubmit()` with
  duplicate-submit prevention (Plan §16).
- Types: `types/api.ts` (`ApiError`, `Paginated<T>`), `types/models.ts`, `types/enums.ts`.
- Utils: `utils/money.ts` (minor-unit formatting, Africa/Nairobi), `utils/dates.ts`.
- 9 core UI components under `components/ui/` (all light+dark, all states, axe-verified):
  `SvButton` (4 variants, loading, disabled, 44px touch, `text-brand-deep` on orange for
  WCAG AA 4.78:1), `SvInput`, `SvSelect`, `SvTextarea` (labels, `aria-invalid`,
  `aria-describedby`, `aria-required`), `SvCard`, `SvModal` (focus trap, Esc, `aria-modal`),
  `SvToast` (`role="status"`, 5s auto-dismiss, pause on hover), `SvStateBoundary`
  (loading/empty/error/success), `SvEmptyState`.
- `pages/dev/DesignSystemDemo.vue` routed at `/dev/design-system` — renders all Phase 4
  components in both themes with all required states.
- `playwright.config.ts`; Playwright smoke suite (11 tests) covering 3 breakpoints,
  no horizontal scroll, component rendering, theme toggle, modal keyboard, axe WCAG AA scan.
- Vitest tests for `apiClient` error mapping (10), `useForm` (8), `SvStateBoundary` (8).
- `npm` packages added: `axios`, `@playwright/test`, `@axe-core/playwright`.

#### Fixed
- Primary button and CTA button contrast: `text-white` on `#f97316` (2.8:1) replaced with
  `text-brand-deep` (`#4A2208`) on `#f97316` (4.78:1) to meet WCAG AA (Plan §15.3).
- Loading skeleton: added `role="status"` to permit `aria-label` on the loading div
  (axe `aria-prohibited-attr` violation resolved).

---

### Phase 3 — Laravel backend foundation (`phase-3-laravel-backend-foundation`)

#### Added
- Domain-oriented skeleton: 20 `app/Domain/*` folders (Plan §5.1).
- `app/Support/Money.php` — immutable integer-minor-unit money value object with
  currency-checked arithmetic, comparisons and integer-only formatting;
  `CurrencyMismatchException`.
- Enums: `Currency` (KES + USD forward-compat), `Severity`, `ErrorCode`.
- Structured API error envelope (Plan §11.5) via `app/Exceptions/ApiErrorRenderer`
  wired in `bootstrap/app.php`; 5xx responses carry a generic message + correlation
  id only.
- `CorrelationIdMiddleware` (+ `App\Support\CorrelationId`) — safe/length-bounded
  inbound `X-Correlation-ID` or generated ULID, echoed on the response and 5xx meta.
- Structured logging: `Support\Redaction\Redactor` and Monolog
  `RedactionProcessor` / `CorrelationIdProcessor` / `StructuredLogTap`, tapped onto
  the `single` and `stderr` channels (JSON + redaction + correlation id).
- Seven named rate limiters (Plan §9.3) registered in `AppServiceProvider`.
- `HealthController` with `/health` (liveness) and `/health/deep` (readiness:
  db/redis/cache required, meilisearch/s3 optional).
- `sentry/sentry-laravel ^4.10` wired (`Integration::handles`); env placeholders only.
- `routes/api.php` registering the `/api/v1` group (no business routes yet).
- Tests: `Unit/MoneyTest`, `Feature/Api/{ErrorEnvelope,CorrelationId,DeepHealth}Test`,
  `Feature/Security/LogRedactionTest`, `Feature/RateLimitersRegisteredTest`,
  `Feature/SentryConfigTest`.

#### Changed
- `bootstrap/app.php` — api routing + `apiPrefix=api/v1`, correlation middleware,
  `/health` + `/health/deep`, exception→envelope renderer, Sentry integration.
- `config/logging.php` (structured tap), `config/services.php` (meilisearch host),
  `app/Providers/AppServiceProvider.php`, `.env.example` (Sentry PII flag).
- `composer.json`/lock — added `sentry/sentry-laravel`.

#### Notes
- Framework tables (sessions/cache/jobs/job_batches/failed_jobs) already exist in
  the default migrations — confirmed, no new migration added.

### Phase 2 — Docker & environment setup (`phase-2-docker-environment`)

#### Added
- `docker/php.Dockerfile` — PHP-FPM 8.3 (alpine), extensions `pdo_pgsql`,
  `redis`, `intl`, `gd`, `bcmath`, `pcntl`, `zip`, `opcache`; Composer;
  non-root `servana` user; `dev` and `prod` build stages (Plan §26.1).
- `docker/nginx.Dockerfile` — non-root (nginx-unprivileged) edge image with a
  Node 20 SPA-build stage; `docker/nginx/default.conf`.
- `docker/php/php.ini`, `docker/php/opcache.ini`, `docker/php/entrypoint.sh`.
- `docker-compose.yml` — dev stack: app, nginx, postgres:16, redis:7,
  meilisearch, minio (+ bucket init), mailpit, clamav (opt-in `clamav`
  profile), worker + scheduler placeholders, spa-builder (`tools` profile);
  healthchecks on app/nginx/postgres/redis/meilisearch/minio/clamav.
- `docker-compose.prod.yml` — app/nginx/worker/scheduler against managed
  PG/Redis/S3 (Phase 25 completes deployment).
- `.dockerignore`.
- CI `docker` job building the app + nginx images (no push/deploy).
- `docs/proof/phase-2.md`.

#### Changed
- `.env.example` — full documented variable set with Docker service hostnames
  (postgres/redis/mailpit/minio/meilisearch); placeholders only.
- `Makefile` — real targets: `env up down restart logs ps shell composer npm
  fresh test lint stan build clamav-up`, run against the containers.
- `composer.json` — added `brianium/paratest` so `php artisan test --parallel`
  works; `pint` script made a passthrough (so `composer pint -- --test`).
- `.github/workflows/ci.yml` — `pcntl` extension, parallel tests, Pint check via
  `-- --test`.

#### Notes
- Horizon (Phase 21), ClamAV upload scanning (Phase 23), `/health/deep`
  (Phase 3), opcache preload + prod deploy (Phase 24/25) intentionally deferred
  — see `docs/PROGRESS.md`.

### Phase 1 — Project initialization (`phase-1-initialization`)

#### Added
- Laravel 11.54 application skeleton (PHP `^8.3`), domain-oriented per Plan §5.1
  (folders mature in later phases).
- Standalone Vue 3 + TypeScript + Vite 5 SPA under `resources/spa` (Pinia,
  Vue Router, self-hosted Inter/Manrope fonts), building to `public/spa`.
- Tailwind CSS configured with brand design tokens (Plan §12.1) and the exact
  responsive breakpoints `md: 768px`, `lg: 1025px` (Plan §13); dark-mode class
  strategy with pre-paint flash-prevention script (Plan §14).
- Quality tooling: Pest, Larastan level 8 with custom-rule placeholders
  (`NoWithoutTenancyOutsidePlatformRule`, `NoRawSqlConcatRule`), Pint, ESLint
  flat config, vue-tsc.
- Secret scanning: `.gitleaks.toml` + `.githooks/pre-commit` (activate with
  `git config core.hooksPath .githooks`).
- `.github/workflows/ci.yml`: PR-stage pipeline (Pint → Larastan → ESLint →
  vue-tsc → Pest on PostgreSQL 16 + Redis 7 → Vitest → build → dependency
  audits → gitleaks) per Plan §26.2.
- `GET /health` liveness endpoint and `tests/Feature/SmokeTest`.
- `.env.example` (Phase 1 minimal), `.editorconfig`, `pint.json`,
  `phpstan.neon`, `tsconfig.json`, `eslint.config.js`, `vite.config.ts`,
  `tailwind.config.ts`, `postcss.config.js`.
- Docs: `docs/PROGRESS.md`, `docs/proof/phase-1.md`, this changelog.

#### Changed
- `composer.json` rebranded to `citrus-labs/servana`; `audit.ignore` entry for
  CVE-2026-48019 (no Laravel 11 fix; documented, mitigated).
- `.gitignore` extended to ignore the `public/spa` build output.
- README "Local Development Setup" / "Common Commands" / "Repository Structure"
  updated to match the real scaffold.

#### Security
- Confirmed `.env` never entered git history; gitleaks gate clean on staged
  content. Pre-existing malformed local `.env` preserved as
  `.env.local-notes.bak` (gitignored).
