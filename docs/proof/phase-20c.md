# Phase 20C — Promotions and Free-Period Offers — Proof

> Lifecycle: **local_complete pending PR CI/review/merge** (branch `phase-20c-promotions-free-periods`,
> based on `origin/main` = `3dd528a2779a44d13b9fe105ac9ee49e688e84c6` = the Phase 20B PR #36 squash
> merge). All six increments are complete and every local gate is green (see Increment 6); the phase is
> **not** `verified_complete` until a reviewed PR merges with green CI and truthful governance evidence
> — no PR is opened by this work. Proof appended per increment. Controlling sources: Plan §53
> (promotions/free-period offers), §§13.9, 22, 47, 48, 49, 65, 67, 80 (Phase 20C); ADR-005
> (round-half-up), ADR-011 (price sole source). Exclusions per §7 assignment (Wallet 20D-W, %-fee ledger
> 20E, compensation 20F–20H).

## Phase 20B merge reconciliation (recorded)

- **PR #36** "Phase 20B: Implement subscription lifecycle and invoices" — **MERGED** into `main`.
  Implementation commit `6790081bace7efb2a659ec8254e6eda53d3d5935`; governance/final PR head
  `4a998dc6e4c0f8259c8d6c179c076f8b8496aec9`; squash merge `3dd528a2779a44d13b9fe105ac9ee49e688e84c6`
  (= `origin/main`); merged **2026-07-12T06:57:28Z**.
- CI: initial run `29183137798` (head `6790081…`) SUCCESS + final run `29183286205` (head `4a998dc…`)
  SUCCESS — five required jobs (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS.
- `reviewDecision`: **blank** — documented PR-specific solo-maintainer governance exception, **not**
  independent reviewer approval. Local + remote `phase-20b-subscription-lifecycle-invoices` branches
  deleted after merge.
- Reconciled `docs/PROGRESS.md`, `docs/CHANGELOG.md`, `docs/proof/phase-20b.md`,
  `docs/traceability/servana-requirements.csv` (SRV-SUBSCRIPTION-001 + SRV-PLATFORM-GOVERNANCE-001),
  and `docs/remediation/register.yaml` (REM-PERM-001 status comment) from
  `local_complete → verified_complete`. All Phase 20B implementation/test/defect detail retained;
  history not rewritten as though independent review occurred.

## Baseline verification (independent, at start)

Branch `phase-20c-promotions-free-periods`; created off HEAD == origin/main == merge-base ==
`3dd528a2779a44d13b9fe105ac9ee49e688e84c6`; working tree clean at creation; `git fsck --full` clean;
old local + remote `phase-20b*` branches absent. Docker Engine healthy (Desktop 29.6.1); Compose
services up (`app`/`postgres`/`redis`/`nginx`/`meilisearch`/`minio`/`mailpit` healthy). PHP 8.3 /
Laravel 12.62.0 / PostgreSQL 16 confirmed at Increment 2 fresh-build.

## Specification gates (resolved before migration)

| Gate | Decision | Basis |
|---|---|---|
| **C1** effective-date + target ULID | Use `effective_from`/`effective_to` (date); **no** `starts_at`. Both target tables carry immutable unique `ulid` (tie-break). Global candidates have no target row; global ties → parent `effective_from` then parent `ulid`. | Repository billing convention (`subscription_plan_prices`) + Plan §53 wording. |
| **C2** normalized targets | Scopes `all_new_merchants`/`selected_merchants`/`selected_plans`/`billing_mode`; types `merchant`/`plan`/`billing_mode`; exactly-one field set matching type; `all_new_merchants` has zero targets; duplicate parent/target forbidden; no JSON. | Plan §53. |
| **C3** eligibility instant | Free-period: Merchant-Admin creation anchor (Gate B1) + setup plan/effective billing mode. Promotion: invoice issuance business date + invoice merchant/plan/billing mode. Issued invoices never re-resolved. | Plan §48/§49/§53. |
| **C4** snapshot persistence | Forward-only expand columns on 20B tables; applied days stay in `trial_days_snapshot`, applied discount stays in `discount_minor`; no JSON blob; no backfill. | Plan §53 + existing 20B schema. |
| **C5** oversized fixed discount | **Cap at subtotal** (product-owner decision 2026-07-12): `applied_discount_minor = min(configured_fixed_minor, subtotal_minor)`; `total_minor = subtotal_minor − applied_discount_minor` (≥ 0, may be 0); no credit/carry-forward/refund/residual; snapshot **both** configured (`promotion_value_snapshot`) and applied (`discount_minor`); currency matched before calc; configured promotion never mutated; percentage uses bps + ADR-005 round-half-up. | Product-owner decision; existing `subscription_invoices` CHECKs are the DB backstop. |
| **C6** approval | Reuse Phase 20A platform-config approval pattern — Super-Admin, MFA, fresh step-up, high-severity audit; no separate maker/checker. Drafts + targets editable only while `draft`; approval records `approved_by`/`approved_at`; approved terms/targets immutable (supersede via new record); pause/resume = availability only; cancel only from `draft`/`scheduled`. | Plan §53 + Phase 20A pattern. |

### C5 — full product-owner decision text (2026-07-12)

> Cap at subtotal. `applied_discount_minor = min(configured_fixed_discount_minor,
> invoice_subtotal_minor)`; `invoice_total_minor = invoice_subtotal_minor − applied_discount_minor`.
> The promotion still resolves and applies; total may become KES 0.00 but never negative; no merchant
> credit, carry-forward, refund entitlement or residual promotional value is created; snapshot both the
> configured fixed amount and the applied amount; the configured promotion is preserved unchanged
> (capping is invoice-specific); currency must match before calculation; percentage promotions use
> basis points with ADR-005 round-half-up; calculation is server-side, integer minor units only, inside
> the atomic `IssueSubscriptionInvoice` transaction; existing issued invoices are never recalculated.

Required C5 invariant: `0 <= applied_discount_minor <= invoice_subtotal_minor` and `invoice_total_minor
= invoice_subtotal_minor − applied_discount_minor`. C5 does **not** authorize credit balances,
carry-forward, negative invoices, refunds, Wallet activity, payment processing, %-fee ledger entries,
or retroactive recalculation.

## Increment 1 — reconciliation + spec gates + data dictionary + state machines + traceability (COMPLETE)

- Phase 20B reconciled to `verified_complete` across PROGRESS/CHANGELOG/proof-20b/traceability/register
  (evidence above).
- Gates C1–C6 resolved and recorded (C5 via the narrow cap-versus-reject question; owner chose cap).
- Data dictionary `docs/architecture/data-dictionary/billing-and-wallet.md` extended with the Phase 20C
  section: `promotional_discounts`, `promotional_discount_targets`, `free_period_offers`,
  `free_period_offer_targets`, the two forward-only snapshot expands, the target-resolution algorithm,
  ownership/retention/scheduler.
- State machines `docs/architecture/state-machines/promotional-discount.md` +
  `free-period-offer.md` authored (promotion has `draft → active`; free-period does not).
- Traceability skeleton rows `SRV-PROMOTION-001` + `SRV-FREE-PERIOD-001` added (status `in_progress`).
- No migration or runtime code written yet (spec-first gate).

## Increment 2 — migrations + enums + models/factories + schema/parity tests + PG16 fresh build (COMPLETE + green)

- **Migrations (6, forward-only):** `2026_07_12_000001_create_promotional_discounts_table` …
  `_000004_create_free_period_offer_targets_table` (creates) + `_000005_add_promotion_snapshot_to_
  subscription_invoices` + `_000006_add_free_period_snapshot_to_merchant_subscriptions` (expands; shipped
  20B migrations untouched). All named CHECK constraints, three partial unique indexes per target table,
  resolution indexes, immutable target ULIDs. `btree_gist` not needed (no daterange EXCLUDE — offers may
  overlap by design; resolution disambiguates).
- **Enums (5):** `PromotionalDiscountType`, `PromotionTargetScope`, `PromotionTargetType`,
  `PromotionStatus` (has `draft→active`), `FreePeriodOfferStatus` (no `draft→active`). Each with
  `values()`; the two status enums add `allowedTransitions()`/`isTerminal()`/`isResolvable()`/
  `requiresApproval()`.
- **Models (4) + factories (4):** platform-scoped (no `BelongsToMerchant`); ULID route key; enum/date
  casts; targets `$timestamps=false` (append-only `created_at`). Factories cover per-status/per-type/
  per-scope + `forMerchant/forPlan/forBillingMode`; approved statuses auto-set `approved_by/approved_at`.
- **Registration:** `TenantOwnership::EXEMPT` += 4 tables (documented reasons); `manifest.yaml` += 6
  entries with depends_on + data_dictionary refs.
- **Tests:** `Phase20CSchemaTest` (29) + `Phase20CEnumParityTest` (11) = **40 pass / 75 assertions** on
  PG16. Coverage guards green: `TenantColumnCoverageTest`/`ModelTenancyTraitCoverageTest`/
  `MigrationManifestTest` (15 pass / 285 assertions).
- **Gates:** Pint clean (250 files); Larastan L8 no errors (billing domain + factories); disposable
  `servana_p20c_check` `migrate:fresh --seed` (all 6 new migrations DONE + PermissionSeeder DONE) green
  on PG16, then dropped; dev DB untouched.
- **C1 note (recorded):** target `billing_mode` uses the real canonical `BillingMode` values
  (`fixed_amount`, `percentage_on_merchant_client_invoice`,
  `fixed_amount_plus_percentage_on_merchant_client_invoice`) — an earlier data-dictionary draft used
  placeholder names; corrected before migration.

## Increment 3 — state machines + lifecycle scheduler + resolvers + calculator + unit/concurrency tests (COMPLETE + green)

- **State machines:** `PromotionalDiscountStateMachine` + `FreePeriodOfferStateMachine` (reuse
  `BillingStateException` → 422 `invalid_state_transition`). Promotion allows `draft→active`;
  free-period does not.
- **Resolvers:** `ResolvePromotionalDiscount` + `ResolveFreePeriodOffer` — precedence
  merchant>plan>billing_mode>global via per-class target-join queries; ties by latest `effective_from`
  then ascending target `ulid` (global by parent `ulid`); only `active` + in-window (half-open
  `effective_from <= date < effective_to`); returns `?Model` (null = explicit none); never stacks.
- **Calculator:** `CalculatePromotionalDiscount` — percentage bps + ADR-005 round-half-up
  (`intdiv(basis*bps+5000,10000)`), fixed capped at subtotal (Gate C5 `min(configured,subtotal)`),
  currency-matched (`PromotionCurrencyMismatchException` → fail-closed, no silent fallback); invariant
  `0 <= applied <= subtotal`.
- **Actions (12):** Create/UpdateDraft/Approve/Pause/Resume/Cancel for promotion + free-period. Drafts
  editable; targets replaced only while draft; approval records `approved_by`/`approved_at` (promotion
  current-window→active, future→scheduled; free-period→scheduled always); approved terms immutable
  (`activeTermsImmutable`); resume rejected past window (`windowEnded`); cancel only from draft/scheduled;
  every state change row-locked + mandatory sanitized reason + typed high-severity audit.
- **Scheduler:** `ProcessPromotionLifecycle` (`billing:process-promotion-lifecycle`, registered daily/
  Nairobi/withoutOverlapping/onOneServer in `routes/console.php`) — activates due scheduled + expires due
  active for both aggregates; row-locked bounded per-item txns; idempotent (re-selection by status);
  one audit per real transition; per-item redacted failure signal.
- **Audit:** 16 typed events added to `AuditEvent` (`promotion.*` ×8 + `free_period_offer.*` ×8), all
  High severity, General read-domain. `AuditSeverityCoverageTest` green.
- **Tests (86 phase20c pass / 469 assertions):** `Phase20CStateMachineTest` (7; full valid + every
  invalid pair), `CalculatePromotionalDiscountTest` (9; bps/round-half-up/cap/currency/sweep),
  `ResolvePromotionalDiscountTest` (12; precedence/tie-break/exclusions/isolation/stability),
  `ResolveFreePeriodOfferTest` (6; precedence/modes/anchor-date), `Phase20CLifecycleTest` (13; actions +
  immutability + scheduler activation/expiry idempotency) + Increment 2 schema/parity (40). Larastan L8
  clean; Pint clean.
- **Concurrency note:** exactly-once activation/expiry proven by the idempotent double-run
  (re-selection + `lockForUpdate`); a multi-connection race test is deferred to the Increment 4/gate
  concurrency battery.

## Increment 4 — subscription/invoice integration + permissions/API/audit + OpenAPI/TS (in progress → green)

- **Integration:** `CreateTrialSubscription` extended with `ResolveFreePeriodOffer` (resolve at the
  founding-admin anchor date; snapshot `free_period_offer_id`/`free_period_resolved_at` + days; else
  platform default). `IssueSubscriptionInvoice` extended with `ResolvePromotionalDiscount` +
  `CalculatePromotionalDiscount` (snapshot `promotional_discount_id`/`promotion_type`/
  `promotion_value_snapshot`/`promotion_currency`/`promotion_resolved_at`; set `discount_minor`/
  `total_minor`/`balance_minor`; fail-closed non-fixed mode preserved; atomic). Snapshot columns added
  to the `SubscriptionInvoice` immutable-after-issue set. **Phase 20B regression: billing group 362
  pass** (integration transparent when no offer/promo).
- **Snapshot tests:** `PromotionInvoiceSnapshotTest` (6; zero-discount default, percentage snapshot,
  C5 capped fixed, issued-invoice immutability after promo edit/cancel, idempotent re-issue, no
  platform-fee ledger) + `FreePeriodSubscriptionSnapshotTest` (6; default fallback, offer snapshot,
  anchor-not-setup, existing-trial immutability, idempotent replay, no retroactive apply) = 12 pass.
- **Permissions:** activated `platform.promotion.manage` + `platform.free_period_offer.manage`
  (`PermissionRegistry` def + super_admin grant; matrix YAML planned→active with derived `audit_event`;
  regenerated `permissions.ts`). Four-way parity green; planned 68→66. `tests/Feature/Auth`
  permissions group **92 pass** (incl. plan-metadata parity, TS parity, matrix seeding).
- **API:** 2 policies (`PromotionalDiscountPolicy`/`FreePeriodOfferPolicy`, registered), shared
  `ChangeReasonRequest` + `Store/Update{Promotional Discount,FreePeriodOffer}Request` (+
  `ValidatesOfferTargets` trait), 2 masked ULID-only Resources, 2 thin controllers, **16 platform
  routes** (8+8: index/store/show/update/approve/pause/resume/cancel) under `ResolvePlatformContext` +
  `EnsurePrivilegedMfa` + `RequireFreshMfa:billing_configuration` + `platform_mutation` classification;
  `EnsureIdempotentRequest` on create. Request bodies reject authoritative status/approver (ignored).
- **Audit:** 12 mutating routes mapped in `AuditMutationCoverage` (approve→approved+activated for
  promotion; free-period approve→approved only). `RouteSecurityContractTest` + `AuditMutationCoverageTest`
  green.
- **Contracts:** OpenAPI regenerated (**219 ops / 183 paths**); `api.ts` regenerated;
  `api:contract:check` OK.
- **API tests:** `Phase20CPlatformApiTest` (12; super-admin list, non-platform 403, create+ULID-only+
  audit, merchant-target ULID, percentage>100% 422, global-with-targets 422, approve→active+reason,
  reason-required 422, stale step-up 403, active-cancel 422 invalid_state_transition, free-period
  create→approve-to-scheduled, days-bound 422).

## Increment 5 — platform frontend + merchant read-only snapshots + navigation/inventory + Vitest (COMPLETE + green)

- **Platform screen:** `pages/platform/Promotions.vue` — consolidated Super-Administrator surface with
  accessible tabs (Promotional discounts / Free-period offers), each gated by its resolved manage
  permission (UX-only; API authoritative). List + draft create form (percentage↔fixed dynamic value
  label, KES minor-unit-safe, scope select + target ULID/billing-mode builder, effective window),
  lifecycle action buttons (approve/pause/resume/cancel by status) → reason modal (mandatory reason,
  MFA/step-up + invalid-transition error surfacing), loading/empty/error states, 44px targets.
- **Stores:** `promotionStore.ts` + `freePeriodOfferStore.ts` (generated-type-backed; list/filter,
  fetch, create, updateDraft PATCH, transition with reason).
- **Router/nav/inventory:** route `platform.promotions` (PlatformAdminLayout); `roleNavigation.ts` +
  `role-navigation.yaml` promotions item planned→**live** with route; `inventory.json` +
  `inventory.yaml` `platform-promotions` planned→**implemented** + route + §27.1 spec
  (`platform/platform-promotions.md`, regenerated via `scripts/generate-screen-specs.mjs`).
- **Merchant read-only snapshots:** `SubscriptionInvoiceResource` exposes `promotion_applied` +
  snapshotted type/value/currency (alongside subtotal/discount/total); `MerchantSubscriptionResource`
  exposes `trial_days_snapshot` + `free_period_offer_applied`. No merchant promotion-management control;
  no internal/offer/promotion id leaked. OpenAPI + api.ts regenerated (219 ops), contract:check OK.
- **Tests:** `promotionStore.spec` (5) + `freePeriodOfferStore.spec` (4) + `Promotions.spec` (4;
  both-sections, single-section, no-access, form-reveal). vue-tsc clean; ESLint 0 errors; full Vitest
  suite **317 pass** (+9); nav/inventory parity specs green.

## Increment 6 — Playwright E2E + full local gate battery (COMPLETE + green)

- **Playwright `tests/e2e/phase-20c.spec.ts` — 18/18 pass:** super-admin renders both sections; creates
  a percentage promotion; creates a fixed-amount promotion (currency field); target inputs appear by
  scope (merchant/plan ULIDs, billing-mode select); creates a free-period offer; approval opens a reason
  modal + requires a reason; scheduled/active/paused promotions render with status; **no Wallet/STK/
  PayBill/record-payment control** anywhere; a merchant user sees no management controls (UX gate; API
  authoritative); no page-level horizontal overflow at **360/768/1280**; usable at **200% zoom**;
  keyboard operation + focus restoration after the reason modal (Escape closes); **axe serious/critical
  = 0** in **light and dark**. (One initial failure — the role-boundary test wrongly expected a
  client-side redirect; corrected to assert the accurate "No access" UX gate, since platform routes have
  no staff-only client guard and the server enforces the boundary.)
- **Backend:** serial **1458 pass / 7 skip / 0 fail** (8098 assertions) + parallel **1458 / 7 / 0** on
  PG16.
- **Static/contract:** Pint **PASS (1189 files)**; Larastan L8 **No errors**; `composer validate
  --strict` valid; OpenAPI **219 ops / 183 paths**, **byte-identical across two runs**; `api.ts` +
  `api:contract:check` OK; `servana:permission-types --check` clean; four-way permission parity green.
- **Frontend:** vue-tsc clean; ESLint **0 errors**; **Vitest 317 pass**; production SPA build ✓.
- **Security/deps:** `composer audit` no advisories; `npm audit` 2 moderate (below the high gate;
  pre-existing dev-dependency chain); **gitleaks no leaks**.
- **Docker:** php `dev` + php `prod` + nginx `prod` images build (multi-stage; nginx stage runs the SPA
  build).

## Bug Fix Protocol log

**DEF-20C-001 — Playwright role-boundary test assumed a client-side redirect that does not exist.**
- Observed problem: `phase-20c.spec.ts` "a merchant user cannot reach the platform promotions route"
  failed — after `goto('/platform/promotions')` the URL stayed at `/platform/promotions`.
- Evidence: initial Playwright run 17 passed / 1 failed (`expect(page).not.toHaveURL(/\/platform\/promotions$/)`).
- Affected files: `tests/e2e/phase-20c.spec.ts` (test only).
- Root cause: the platform route group guards only with `requiresAuth` (any authenticated user);
  there is no staff-only *client-side* guard. The real boundary is server-side (the platform API denies
  a non-platform user — proven by `Phase20CPlatformApiTest` "denies a non-platform user") plus the UX
  gate in `Promotions.vue` (`can()` → tabs empty → "No access"). The test asserted behaviour the app
  intentionally does not implement.
- Why this is the root cause: inspecting `router/routes/platform.ts` shows `beforeEnter: [requiresAuth]`
  only; `Promotions.vue` renders "No access" when neither manage permission is present.
- Correct fix: assert the accurate UX gate — zero tabs, "No access" visible, no "New promotion" control.
- Files changed: `tests/e2e/phase-20c.spec.ts`.
- Tests added/updated: the role-boundary test rewritten.
- Test command: `npx playwright test tests/e2e/phase-20c.spec.ts`.
- Test result: 18/18 pass.
- Proof of resolution: full spec green; server-side denial independently proven by the Feature suite.
- Remaining risk: none — the security boundary is server-enforced, not client-enforced.

_Other in-development corrections were caught pre-commit by Larastan/tests, not shipped defects:
enum-typed property assignment in the four target-building actions (assign string → `Enum::from()`),
resolver `find()` union return (→ `whereKey()->first()`), and `list<int>` scheduler helper annotations —
all fixed in Increment 3 before any test run; two `final` request base classes made extendable and three
Pint style fixes in Increment 4._

## Scope boundaries (Phase 20C)

Owns: promotion + free-period configuration/targets/state-machines/approval/resolution/snapshot,
platform-only management API + UI, merchant read-only applied-snapshot presentation, audit,
permissions, tests, docs. Does **not** own: Wallet sync/payments (20D-W), %-fee ledger (20E),
compensation (20F–20H), R&E (21R), notifications (21N), SMS (21S), search (22), hardening (23),
performance (24), deployment (25). No Wallet/provider/payment runtime, no %-fee ledger, no
merchant-create/first-admin/impersonation, no JSON target storage, no destructive edits to issued
invoices or existing trials.

## Solo-Maintainer Review Exception - PR #37

- PR: #37
- verified implementation head: 782c97313ea988d2263e35d44c325d2c7ccb25ec
- initial successful CI run: 29191160816
- CI/Backend: passed
- CI/Frontend: passed
- CI/Docker: passed
- CI/Security: passed
- CI/E2E - Playwright: passed
- GitHub reviewDecision: intentionally blank
- governance record: docs/governance/solo-maintainer-review-exception-pr-37.md

This exception applies only to Phase 20C and is not independent reviewer
approval.

Phase 20D-W and all later Wallet, payment, platform-fee ledger, compensation,
payout and integration domains remain deferred to their documented owning
phases.
