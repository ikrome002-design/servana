# Phase 20A — Plan Catalogue, Prices, Entitlements, Billing Settings — Proof

> Lifecycle: **in_progress** (branch `phase-20a-billing-catalogue-settings`, based on
> `origin/main` = `85bd3e570db1436586d3d1ead17ab6b1701538d5`). One reviewed PR at the
> end; not marked `verified_complete` until that PR merges with green CI. Proof is
> appended per increment. Controlling sources: Plan §13.9, §13.10, §20, §47, §49,
> §50, §80 (Phase 20A); ADR-011 (price sole source), ADR-005 (round-half-up),
> ADR-012 (no direct provider integration).

## Gate A — v4 adoption PR (verified merged before Phase 20A)

- **PR #34** — "docs: update Servana software development plan" — **MERGED**.
- Head → base: `docs/update-servana-development-plan` → `main`.
- Merge commit: `85bd3e570db1436586d3d1ead17ab6b1701538d5`; `git merge-base
  --is-ancestor 85bd3e5… origin/main` → exit 0 (contained).
- CI: five required checks all `COMPLETED/SUCCESS` — Backend (Pint, Larastan,
  Pest), Frontend (ESLint, vue-tsc, Vitest, build), Docker (build images),
  Security (gitleaks), E2E (Playwright).
- `reviewDecision`: blank (`""`) — recorded truthfully as the **documented
  solo-maintainer governance exception, NOT independent reviewer approval**.
- Phase 19 merge `7ef259e28f51fc9bba24a16ef3945ff61ddef4ce` contained in
  `origin/main` (exit 0). `git fsck --full` clean (dangling objects only).

## Docker/PG16 blocker — recorded and CLOSED

- **Initial failure:** Docker Desktop's Linux engine returned HTTP 500
  (`dockerDesktopLinuxEngine` API) and PG16 verification could not run; Increments
  2–7 were blocked at the Increment-1 checkpoint.
- **Root cause:** the `docker-desktop` WSL distribution was running, but the Docker
  Linux engine was not serving its API.
- **Correct recovery (product owner):** created an external checkpoint backup
  (`…/Backups/Servana/phase20a-increment1-20260710-113152`), shut down Docker
  Desktop and WSL, restarted Docker Desktop, waited for `docker info`, started the
  Compose stack, and verified Laravel/PostgreSQL connectivity. No `HEAD`/branch
  change, no commit, no discard.
- **Resolution proof (re-verified on resume):** Docker engine healthy; 11 Compose
  services up; `servana-app-1` healthy; `servana-postgres-1` healthy on
  `postgres:16-alpine`; Laravel **12.62.0** executable; `php artisan migrate:status`
  succeeds; branch `phase-20a-billing-catalogue-settings` @ `85bd3e5` unchanged; all
  11 Increment-1 paths preserved; `git diff --check` passes.
- **Increment 1 re-verification:** `php -l` clean on both enums; `BillingMode` = 3
  canonical values, `BillingInterval` = 5 canonical values (loaded via autoloader);
  `composer pint -- --test` PASS (956 files); `vue-tsc --noEmit` clean;
  `screenInventory.spec.ts` 8/8 pass (inventory.json valid, JSON/YAML agree,
  `platform-registration-monitoring` + `plan-management` = Phase 20B,
  `platform-billing-settings` remains the genuine Phase-20A screen); no Phase-20B
  runtime added.
- **Remaining risk:** none from the Docker incident; normal Phase 20A implementation
  risks remain. The external backup is retained for integrity only and is **not** a
  source of working files.

## Branch

- `phase-20a-billing-catalogue-settings` created from `origin/main` `85bd3e5`
  (`git merge-base origin/main HEAD == origin/main`). Never worked on `main`.

## Source-of-truth reconciliation (recorded)

Two `docs/frontend/screens/inventory.json` entries were tagged **Phase 20A** but
the Plan and the permission matrix place them in **Phase 20B**; retagged to 20B to
make all three artifacts consistent with the Plan (higher authority):

1. **`platform-registration-monitoring`** — Plan §47 (Phase 20A scope) does not
   include registration monitoring or merchant governance; its perms
   `platform.registration_monitor.view` + `platform.merchant.{view,suspend,
   reactivate,deactivate}` are `owning_phase: Phase 20B`; merchant-lifecycle
   governance is Plan §21/§48 (20B). → retagged 20A → 20B.
2. **`plan-management`** — "Plan selection and scheduled change" depends on
   `merchant_subscriptions` + `scheduled_plan_changes` (Phase 20B, excluded from
   20A by assignment §7); perm `merchant.subscription.plan_change` is
   `owning_phase: Phase 20B`. → retagged 20A → 20B.

`inventory.yaml` mirrored to keep the `screenInventory.spec.ts` snapshot green. The
**only** Phase-20A-owned screen is therefore `platform-billing-settings`. No
permission-matrix authority was changed (it already said 20B).

## Increment 1 — Specification-first gate (COMPLETE — docs; guards pending Docker)

- **Data dictionary** — `docs/architecture/data-dictionary/billing-and-wallet.md`
  expanded with full column-level entries for the five Phase-20A tables
  (`platform_billing_settings`, `subscription_plans`, `subscription_plan_prices`,
  `plan_entitlements`, `preferred_personnel_fee_rules`): columns, types, PostgreSQL
  CHECKs, FKs + ON DELETE RESTRICT, effective-range EXCLUDE constraints, indexes,
  ULID behaviour, immutability, locking/concurrency, backfill rules, audit events,
  retention, factories, positive/negative tests. No duplicate dictionary created.
- **State machines** — created:
  - `docs/architecture/state-machines/preferred-personnel-fee-rule.md` (draft/
    scheduled/active/superseded/expired/cancelled; supersede-not-edit; overlap
    EXCLUDE; round-half-up resolution).
  - `docs/architecture/state-machines/plan-price.md` (effective-dated future/
    current/historical; create/schedule/cancel-future; no destructive edit; sole
    source; overlap EXCLUDE).
  - `docs/architecture/state-machines/platform-billing-settings.md` (append-only
    effective-dated version series; documented settings keys only).
  - `docs/architecture/state-machines/subscription-plan.md` (active → retired;
    retirement preserves history; no price column).
- **Canonical enums** — `app/Domain/Billing/Enums/BillingMode.php` (3 canonical
  modes; `fixed_amount` default) and `app/Domain/Billing/Enums/BillingInterval.php`
  (5 canonical intervals). Both expose `values()` for the DB CHECK + parity guards.
- **Legacy preferred-fee cutover policy (product-owner-fixed):** backfill one
  `fixed_amount`/`service`/`active` rule per service with a non-null
  `services.preferred_personnel_fee_minor`; `fixed_amount_minor` == legacy value
  exactly; `calculation_basis = service_item_net_amount`; currency per the
  authoritative service/merchant currency rule; `effective_from` = the **immutable
  literal `DATE '2026-07-10'`** (product-owner decision — never `now()`/`today()`/
  `CURRENT_DATE`/deployment-time input, so every environment is identical), **not
  retroactive**; legacy column retained read-only (not dropped this deploy);
  application writes to the legacy column prohibited; contract/removal owner to be
  recorded; finalized invoices never recalculated. A test asserts every backfilled
  row uses exactly `2026-07-10`.
- **Migration manifest** — entries are added in Increment 2 together with the
  on-disk migration files (the `MigrationManifestTest` guard requires the files to
  exist; manifest entries reference `billing-and-wallet.md`, which exists).

### Increment 1 verification status
Doc-consuming guards (`MigrationManifestTest`, `screenInventory.spec.ts`,
`BillingEnumParityTest`) run in Increment 2+ once the Docker/PG16 stack and the npm
toolchain are exercised. Enums are pure PHP (Pint/Larastan-checked with the rest of
Increment 2).

## Exclusions honoured (owner phases)
merchant_subscriptions/scheduled_plan_changes/subscription_invoices/billing-status
projection/trial-grace-suspension machine → 20B; promotions/free-periods → 20C;
Wallet/provider/STK/PayBill/webhooks/reconciliation → 20D-W (after Gate W);
percentage fee ledger/adjustments/disputes → 20E; compensation → 20F; commission/
salary ledgers → 20G; payouts → 20H; R&E runtime → 21R. No direct Safaricom/Daraja/
provider integration. `mpesa_offline` preserved as merchant-client payment
terminology.

## Increment 2 — Schema, canonical enums, parity (COMPLETE, green on PG16)

- **Five forward-only migrations** (no collision; after `2026_07_04_000003`):
  `2026_07_10_000001_create_platform_billing_settings_table` …
  `_000005_create_preferred_personnel_fee_rules_table`. All applied on
  `postgres:16-alpine` via `php artisan migrate` (1s/100ms/210ms/55ms/188ms DONE).
- **Schema verified in PG** (`pg_constraint`): 44 constraints across the five tables
  incl. **both `EXCLUDE USING gist`** overlap guards (contype `x`) on
  `subscription_plan_prices_no_overlap` and `preferred_personnel_fee_rules_no_overlap`;
  every documented CHECK present; **no `merchant_id`/`branch_id`** on any of the five
  (information_schema returned 0 rows).
- **Platform ownership** — all five registered in `App\Domain\Tenancy\TenantOwnership::EXEMPT`
  (the canonical platform-owned mechanism, alongside `permissions`/`roles`), each with a
  rationale; `TenantColumnCoverageTest` classifies them (no undocumented table) and
  `ModelTenancyTraitCoverageTest` stays green (no platform model added to the tenant/branch
  MODELS map).
- **Manifest** — five entries in `docs/architecture/migrations/manifest.yaml`
  (`domain: Billing`, `owner_phase: 20A`, `data_dictionary → billing-and-wallet.md`,
  `depends_on` valid); `MigrationManifestTest` + `FileMigrationManifestTest` green.
- **Enum parity** — `BillingMode`(3) and `BillingInterval`(5) backed enums;
  `tests/Feature/Billing/BillingEnumParityTest.php` proves **PHP enum ↔ PostgreSQL CHECK**
  by parsing `pg_get_constraintdef` for `platform_billing_settings_billing_mode_check` and
  `subscription_plan_prices_billing_interval_check` (later increments extend parity to API/
  OpenAPI/TS/screens).
- **Schema invariants** — `tests/Feature/Billing/Phase20ASchemaTest.php`: platform ownership
  (no merchant/branch/soft-delete), canonical-mode/interval acceptance + rejection, uppercase
  currency, non-negative money, one-version-per-effective-instant, ADR-011 no-price-on-plan,
  duplicate-key/status rejection, five intervals accepted, **overlap rejected + adjacent
  allowed** (both EXCLUDE tables), `effective_to > effective_from`, **FK RESTRICT** on plan
  delete, ULID uniqueness, entitlement `unique(plan,key)` + non-negative limit, preferred-fee
  **value-shape** (fixed⊕percentage), **scope↔service_id**, bp≤10000, empty-`change_reason`
  rejection, superseded-overlaps-active allowed, per-service isolation.
- **Gates:** targeted suite **52 passed / 341 assertions** (BillingEnumParity, Phase20ASchema,
  MigrationManifest, FileMigrationManifest, TenantColumnCoverage, ModelTenancyTraitCoverage) on
  PG16; **Pint PASS (963 files)**; **Larastan L8 "No errors" (714 files)**;
  **`migrate:fresh --seed` proven** on a disposable `servana_p20a_check` DB (all migrations +
  PermissionSeeder) then dropped — dev data untouched (RefreshDatabase runs transactionally).
- **Deferred to Increment 3:** the legacy expand-and-contract migration
  `2026_07_10_000006` (backfill + `DATE '2026-07-10'` cutover) with the rule-backed resolver
  swap and future-finalization resolver — coupled to the domain resolver, not the schema.
  `preferred_personnel_fee_rules.created_by` was made **nullable** (NULL = system/migration
  backfill) to support it.

## Increment 3 — Models, resolvers, legacy expand-and-contract (COMPLETE, green on PG16)

- **Models + factories** (all platform-owned, no tenant traits): `SubscriptionPlan`,
  `SubscriptionPlanPrice`, `PlanEntitlement`, `PlatformBillingSettings`,
  `PreferredPersonnelFeeRule` (+ matching factories). ULID route keys; enum casts.
- **Enums:** `SubscriptionPlanStatus`, `PreferredPersonnelFeeRuleStatus` (with
  `allowedTransitions`), `PreferredFeeCalculationType`/`…Basis`/`…Scope`.
- **`JsonObject` cast** (`app/Support/Casts/JsonObject.php`) — guarantees `metadata`/`settings`
  serialize to a JSON **object** (empty → `{}`), matching the `jsonb_typeof = 'object'` CHECK.
  (Root-caused from a real failure: PHP `[]` serialized to `[]` and violated the CHECK.)
- **Legacy expand-and-contract migration** `2026_07_10_000006` — one `fixed_amount`/`service`/
  `active` rule per service with a non-null `services.preferred_personnel_fee_minor`;
  `fixed_amount_minor` == legacy value **exactly**; `currency` = service currency;
  `calculation_basis = service_item_net_amount`; **`effective_from = DATE '2026-07-10'`**
  (immutable product-owner cutover; never `now()`/`today()`/`CURRENT_DATE`); `created_by` NULL
  (system); idempotent; legacy column retained read-only, **not dropped**.
- **Resolvers:** `ResolveEffectivePreferredPersonnelFee` (Billing query — service-scope preferred
  over platform_default, effective-date window, **round-half-up** ADR-005 via
  `intdiv(basis*bp + 5000, 10000)`); `RuleBasedPreferredPersonnelFeeResolver` (Invoicing —
  session honoured-gating unchanged, delegates to the query) **rebound in `AppServiceProvider`**
  replacing `LegacyPreferredPersonnelFeeResolver`; `PreferredPersonnelFeeResolution` gained
  `ruleFixed`/`rulePercentage`/`ruleNone` sources. `ResolveEffectivePlanPrice` (current/historical
  price). Entitlement substrate: `PlanContextResolver` interface + `UnboundPlanContextResolver`
  default (returns null — merchant→plan binding is Phase 20B; **no subscription fabricated**),
  `ResolvePlanEntitlement` + `EntitlementDecision`.
- **Seam-closure proof (§10, §13.10, §17.5):** `PreferredFeeRuleFinalizationTest` proves a
  finalized invoice snapshot is **never recalculated** when a rule later changes, and a **new**
  finalization resolves the **new** effective rule; the legacy column is no longer the
  finalization source. The Phase-17 `FinalizeInvoiceTest` "preferred fee snapshot" case was
  updated (not weakened) to assert the rule-based source — a **Plan-mandated behavior change**,
  not a regression.
- **Manifest:** `2026_07_10_000006` added (`change_type: data`, deps on `…000005` + services).
- **Gates:** targeted **billing 59** + **invoicing+billing 146** green; **full parallel suite
  1129 passed / 7 skipped / 0 failed (6016 assertions)** — no regression from the cross-cutting
  resolver swap (baseline 1062 → 1129); **Pint clean (993)**; **Larastan L8 "No errors" (739)**.

## Increment 4 (part A) — audit-event catalogue + state machines (COMPLETE, green)

- **`AuditEvent` — 13 Phase-20A cases added** (`platform_settings.updated`,
  `platform_billing.settings_updated`, `subscription_plan.created/metadata_updated/retired`,
  `subscription_plan_price.created/scheduled/cancelled`, `plan_entitlement.updated`,
  `preferred_personnel_fee_rule.created/approved/superseded/cancelled`) with `severity()` mappings
  (Notice: plan create/metadata, entitlement update, fee-rule create; High: settings/billing
  updates, plan retire, all price events, fee-rule approve/supersede/cancel) and `domain()` =
  `General` (platform governance; no billing audit-read surface in 20A — platform rows carry null
  branch_id and are excluded from branch reads). `AuditSeverityCoverage`/`AuditEventCoverage`/
  `AuditMutationCoverage` green (no route yet ⇒ no coverage entry required).
- **State machines:** `BillingStateException` (renders `invalid_state_transition` 422 + a typed
  `activeTermsImmutable()`), `SubscriptionPlanStateMachine` (active→retired), and
  `PreferredPersonnelFeeRuleStateMachine` (draft→scheduled/active/cancelled; scheduled→active/
  cancelled; active→superseded/expired; terminals). **`BillingStateMachineTest` — 36 tests** (every
  valid + invalid pair, terminal/reserves-range checks, envelope render). Pint 997, Larastan clean.

## Increment 4 (part A+) — actions + policies (COMPLETE, green)

- **12 named actions** in `app/Domain/Billing/Actions/` (each opens a DB transaction with row/
  advisory lock and emits its typed `AuditEvent`): `CreateSubscriptionPlan`,
  `UpdateSubscriptionPlanMetadata`, `RetireSubscriptionPlan` (state machine),
  `CreatePlanPrice` (**absorbs SchedulePlanPrice** — one POST route; a future `effective_from`
  emits `subscription_plan_price.scheduled`, else `.created`; overlap `23P01` → 409),
  `CancelFuturePlanPrice` (future-only; effective/historical → 422), `UpdatePlanEntitlements`
  (upsert + prune; no merchant data), `UpdatePlatformBillingSettings` + `UpdatePlatformSettings`
  (append new effective version of `platform_billing_settings`; billing-config vs general-settings
  split over one versioned table), `CreatePreferredPersonnelFeeRule` (draft),
  `ApprovePreferredPersonnelFeeRule` (draft/scheduled→active|scheduled; advisory lock; overlap→409),
  `SupersedePreferredPersonnelFeeRule` (active→superseded + new version; active terms immutable),
  `CancelPreferredPersonnelFeeRule` (draft/scheduled→cancelled).
  **Design note:** `SchedulePlanPrice` is not a separate class — the schema has no price status
  column, so "schedule" is the future-dated path of `CreatePlanPrice` (distinct audit event); one
  POST route covers both (documented, not a forced 1:1).
- **Exceptions:** `BillingStateException` (422 `invalid_state_transition`), `BillingOverlapException`
  (409 `plan_price_overlap` / `preferred_personnel_fee_rule_overlap`).
- **6 policies** (`app/Policies/`, registered in `AppServiceProvider::POLICIES` where model-backed):
  `PlatformBillingSettingsPolicy`, `PlatformSettingsPolicy`, `SubscriptionPlanPolicy`,
  `SubscriptionPlanPricePolicy`, `PlanEntitlementPolicy`, `PreferredPersonnelFeeRulePolicy`
  (with `viewBranchRule` for the Branch Manager read). Defence-in-depth alongside `EnsurePermission`.
- **Gates:** Pint clean (1016), Larastan L8 "No errors", targeted 89 tests green (app boots with the
  new policies/events; existing permission schema + legacy-reconciliation guards still pass — 17
  legacy count unchanged, no permission touched yet). `AuditSeverityCoverage`/`AuditEventCoverage`/
  `AuditMutationCoverage` green.

## Increment 4 (part B) — atomic flip (COMPLETE, green)

- **Routes wired:** 21 platform routes (new `prefix('platform')` group OUTSIDE the merchant
  tenant-context group, middleware `[auth:sanctum, EnforceIdleTimeout, EnsureActivePrincipal,
  throttle:api, EnsurePrivilegedMfa, ResolvePlatformContext]`) + 1 branch read
  (`GET branch/preferred-personnel-fee-rule` inside the merchant/`EnsureBranchScope` group).
  `ResolvePlatformContext` added to the middleware priority list in `bootstrap/app.php`. Each
  mutation carries `EnsurePermission:<key>` + `RouteClass::PlatformMutation` + (sensitive)
  `RequireFreshMfa:billing_configuration`; reads carry `EnsurePermission` only; idempotency on the
  effective-dated version/price/fee creates. 4 bodiless transitions added to `VALIDATION_EXEMPT`.
- **RouteSecurityContract green** — `platform_mutation` required/forbidden middleware satisfied
  (ResolvePlatformContext is NOT the forbidden ResolveTenantContext); no merchant context on
  platform routes.
- **AuditMutationCoverage** — 12 mutating platform routes mapped to their emitted events; guard green.
- **StepUpAction::BillingConfiguration** moved OUT of `businessActions()` (now a live platform
  config step-up, like `InvoiceVoid`); `owningPhase` → `Phase 20A (implemented)`.
- **PermissionRegistry:** +9 canonical keys (8 `platform.*` → super_admin default grants;
  `preferred_personnel_fee.view_branch_rule` mutating=false → branch_manager default grant); −3 legacy
  keys (`platform.settings.manage`, `platform.billing.configure`, `platform.fee_rules.manage` — no
  runtime consumers) removed from `PERMISSIONS` + grants.
- **permission-matrix.yaml:** 9 keys `planned→active` (`owning_phase`/`canonical_successor` null;
  `audit_event` route-derived & sorted); 3 legacy rows deleted. Fixtures updated
  (`PermissionMatrixTest` expected grants; `PermissionLegacyKeyReconciliationTest` **17→14**;
  `PermissionPlannedKeyIsolationTest` **86→77**).
- **Active-key arithmetic (verified):** 87 → **93** (`+9 −3`); legacy-active 17 → 14; planned 86 → 77.
- **Regenerated:** `permissions.ts` (93 keys), `openapi.json` (157 paths / 188 ops), `api.ts` —
  `servana:permission-types --check` up-to-date, `api:contract:check` OK.
- **Increment-4 tests:** `Phase20APlatformApiTest` (13 tests) — platform context (super_admin
  resolves, merchant denied), plan create/retire + audit, price create/overlap-409/cancel-future/
  cancel-current-422, fee-rule draft→approve→overlap-409, value-shape 422, **step-up denial vs
  fresh-allow**, billing-settings version + audit, **branch read masked (no status/approval/id) +
  service-override precedence + BM-cannot-mutate**.
- **Gates:** full **Auth suite 192 pass** (four-way parity, reconciliation 14, planned 77, MFA/
  step-up coverage, `audit_event` derivation); **RouteSecurityContract / AuditMutationCoverage /
  AuditSeverityCoverage / OpenApi** green; **billing+phase20a groups 96 pass**; **Pint clean**,
  **Larastan L8 "No errors"**.

### Increment 4 (part B) — original plan (delivered above)

This part is **atomic**: the four-way permission-parity guards + `audit_event` derivation require
keys, routes, and audit emissions to land together. **The entire runtime layer is DONE (part A+):**
12 actions, 6 policies, **8 Form Requests** (`app/Http/Requests/Billing/`), **6 masked Resources**
(`PlatformBillingSettingsResource`, `SubscriptionPlanResource`, `SubscriptionPlanPriceResource`,
`PlanEntitlementResource`, `PreferredPersonnelFeeRuleResource` admin, `PreferredPersonnelFeeBranchRuleResource`
masked branch — no bigint id/approval internals/drafts), **7 controllers** (6 platform +
`Branch/PreferredPersonnelFeeRuleReadController`), `PlatformServiceLocator`
(`App\Domain\Platform\Services\` — the tenancy-rule-exempt place for cross-tenant service ULID↔id
lookup), and **`ResolvePlatformContext`** middleware. Larastan clean; RouteSecurityContract +
AuditMutationCoverage + permission guards green with the unwired layer.

**Key routing decision (recorded):** `RouteClass::PlatformMutation` **forbids** `ResolveTenantContext`
+ `EnsureMerchantActive` (Plan §24.1) but `EnsurePermission` needs resolved permissions. So platform
billing routes use a NEW group (OUTSIDE the merchant tenant-context group) with **`ResolvePlatformContext`**
(populates platform-staff grants only, never a merchant) — which passes the forbidden-middleware
check. The branch read is separate (tenant-scoped, `EnsureBranchScope`).

Remaining steps, in order:

1. ~~13 actions~~ / ~~policies~~ / ~~Form Requests~~ / ~~Resources~~ / ~~controllers~~ **DONE.**
3. **Thin controllers + routes** (`/api/v1/platform/...` + `GET /api/v1/branch/preferred-personnel-fee-rule`)
   with `EnsurePrivilegedMfa` (all platform), `EnsurePermission:<key>`, and `RequireFreshMfa:<StepUpAction>`
   on the SU-Y mutations (settings.update, billing_settings.update, plan.manage, plan_price.manage,
   preferred_personnel_fee.manage). Branch read: NO platform MFA/step-up. Bounded pagination +
   allowlisted filters/sorts + ULID binding. Add a `StepUpAction` case per sensitive mutation.
4. **`AuditMutationCoverage::AUDITED`** entries for every new mutating route → its emitted event(s),
   so the `audit_event` matrix field derives correctly:
   `platform.plan.manage` ⇒ `subscription_plan.created; subscription_plan.metadata_updated;
   subscription_plan.retired; plan_entitlement.updated`; `platform.plan_price.manage` ⇒ the 3 price
   events; `platform.preferred_personnel_fee.manage` ⇒ the 4 fee-rule events;
   `platform.settings.update` ⇒ `platform_settings.updated`; `platform.billing_settings.update` ⇒
   `platform_billing.settings_updated`; view keys ⇒ `none`.
5. **`PermissionRegistry`**: add the 9 canonical keys to `PERMISSIONS` (`platform.*` category;
   `preferred_personnel_fee.view_branch_rule` in a `catalogue`/`billing` read category, **mutating=false**);
   add the 8 platform keys to super_admin `DEFAULT_GRANTS`; add
   `preferred_personnel_fee.view_branch_rule` to **branch_manager** `DEFAULT_GRANTS`. **Remove** the
   3 legacy keys (`platform.settings.manage`, `platform.billing.configure`, `platform.fee_rules.manage`)
   from `PERMISSIONS` + super_admin grants (no route/policy consumers — verified).
6. **`permission-matrix.yaml`**: flip the 9 keys `planned→active` **and set `owning_phase: null`,
   `canonical_successor: null`, `audit_event:` = the route-derived value** (reads `none`); **delete**
   the 3 legacy rows entirely (Phase-19 `audit.view_full` precedent — the loader has only
   `active`/`planned`, no `retired` status). Update
   `PermissionLegacyKeyReconciliationTest` count `toHaveCount(17)` → **`toHaveCount(14)`**.
   Active-key count 88 → **94** across all four projections.
7. **Regenerate** (never hand-edit): `composer api:openapi` → `npm run api:types` →
   `php artisan servana:permission-types` → `npm run api:contract:check` +
   `servana:permission-types --check`.
8. **Tests:** `Phase20APermissionActivation`/`LegacyPermissionReconciliation`/`PermissionRoleBoundary`/
   `Mfa`/`StepUp`; `PlatformSettings`/`PlatformBillingSettings`/`SubscriptionPlan`/
   `SubscriptionPlanPrice`/`PlanEntitlement`/`PreferredPersonnelFeeRule` API; `PreferredPersonnelFeeBranchRead`;
   `Phase20AAuditEvent`/`AuditRedaction`; plus the existing `PermissionMatrix*`, `AuditMutationCoverage`,
   `AuditSeverityCoverage`, `RouteSecurityContract`, `OpenApi` guards must stay green.

**Branch-rule permission correction (recorded):** `preferred_personnel_fee.view_branch_rule` =
branch scope, **branch_manager** default, read-only, **no MFA, no step-up**, info severity — NOT a
super-admin platform key; Branch Manager gets no mutation authority (rule management stays
Super-Admin under `platform.preferred_personnel_fee.manage`).

## Increment 5 — Phase 20A frontend (COMPLETE, green)

Single genuine platform screen delivered per the canonical inventory: `platform-billing-settings`
→ route `platform.billing-settings` at `/platform/billing-settings` (nested under the existing
`/platform` `PlatformAdminLayout`; `requiresAuth`; `meta.roleIdentity: super_administrator`).
No Phase 20B surface (`plan-management`, `platform-registration-monitoring`) was activated.

- **Page + components:** `resources/spa/src/pages/platform/BillingSettings.vue` (one coherent
  surface with an accessible `role="tablist"`; arrow/Home/End roving `tabindex`; tabs the user
  cannot view are **absent**, not disabled) hosting six section components in
  `pages/platform/billing/`: `GeneralSettingsSection`, `BillingSettingsSection`,
  `SubscriptionPlansSection`, `PlanPricesSection`, `PlanEntitlementsSection`,
  `PreferredFeeRulesSection`.
- **Stores (5, generated-type-backed):** `platformBillingSettingsStore` (settings + billing
  settings share `PlatformBillingSettingsResource`), `subscriptionPlanStore` (plans +
  entitlements), `planPriceStore`, `preferredPersonnelFeeStore`, `branchPreferredFeeStore`.
  All use `components['schemas'][…]` generated types, preserve ULIDs, send `Idempotency-Key`
  on effective-dated creates (settings/billing-settings update, price create, fee-rule
  create/supersede), surface structured `err.apiError`, and persist no secrets/raw settings/
  internal IDs.
- **Canonical enums in the UI:** three billing modes and five billing intervals mirrored from
  `BillingMode`/`BillingInterval` in store constants (`BILLING_MODES`, `BILLING_INTERVALS`) —
  no second vocabulary.
- **MFA/step-up UX:** reads never force step-up; mutations attempt the call and, on the server's
  step-up/MFA rejection, surface the canonical "a fresh step-up is required" guidance. The
  mandatory-MFA challenge redirects reads away from the route (proven in E2E).
- **Money:** major-unit inputs converted to integer minor units via `Math.round(x * 100)` before
  submission; amounts displayed from minor units. No float authority in the browser.
- **Read-only/immutability UX:** current/historical prices show "Read-only" (only future prices
  cancellable); active fee terms show "Active terms are read-only" and offer **Supersede** (never
  in-place edit); terminal fee states expose no controls.
- **Branch Manager read-only integration decision (recorded):** no dedicated inventory entry
  exists and §5.9 forbids a new top-level screen, so the branch-scoped **effective** preferred fee
  is surfaced read-only via `pages/branch/BranchPreferredFeeCard.vue` inside the existing
  `branch.services` (Service catalogue), gated by `preferred_personnel_fee.view_branch_rule`,
  fed by `GET /branch/preferred-personnel-fee-rule`. No mutation controls, no draft/scheduled
  data, no platform nav, no MFA/step-up UX.
- **Navigation reconciliation (`roleNavigation.ts`; YAML auto-generated):** `platform.billing-settings`,
  `platform.plans`, `platform.preferred-personnel-fee`, `platform.settings` → `live` pointing at the
  one consolidated route (no dead links, no duplicate top-level screens). `platform.merchants` +
  `platform.registration-monitoring` phase corrected `20A→20B` (kept `planned`).
- **Inventory + spec:** inventory entry flipped to `implemented` with route + spec; full §27.1 spec
  hand-authored at `docs/frontend/screens/platform/platform-billing-settings.md`. Generated
  `inventory.yaml` + `role-navigation.yaml` snapshots regenerated.

**Contract-truth fix (recorded):** the OpenAPI generator (Scramble) did not propagate the
null-safe `?->` operator, so `SubscriptionPlanPriceResource.effective_to`,
`PreferredPersonnelFeeRuleResource.effective_to`/`approved_at`, and the branch resource's
`effective_to` were typed non-nullable although the runtime returns `null` for open-ended rows.
Fixed by making nullability explicit (`=== null ? null : …`) in the three Resources; regenerated
`openapi.json` + `api.ts` — those fields are now `["string","null"]`. Behaviour unchanged.

**Increment-5 tests (Vitest, all green):** 5 store specs + `BillingSettings.spec.ts` +
`PreferredFeeRulesSection.spec.ts`. Full Vitest **279 passed** (67 files, +31). `npm run typecheck`
clean; `npm run lint` **0 errors** (new files warning-free); `npm run build` OK;
`api:contract:check` OK — 157 paths, 188 operations.

## Increment 6 — Playwright, responsive, dark, a11y (COMPLETE, green)

`tests/e2e/phase-20a-billing.spec.ts` — **17 tests, all passing** (`--workers=1`):

- **Nav/access:** Super Admin sees the header "Billing settings" link + 6 tabs; a merchant
  identity on the platform route sees no tabs and the "do not have access" note; a mandatory-MFA
  challenge redirects away from the route; only viewable tabs render (billing-only grant → 1 tab).
- **Settings:** three canonical billing modes; read-only viewer has no save control; a 403 on
  update surfaces the fresh-step-up guidance in an `alert`.
- **Plans/prices:** plan metadata form has no price/amount input; retire offered for active;
  five canonical intervals; current price read-only; overlapping price → explicit conflict message.
- **Entitlements:** enable/disable/limit editing; no merchant-subscription binding on the surface.
- **Preferred fee:** active rule read-only + supersede; fixed⇔percentage mutually exclusive;
  service scope requires a service.
- **Branch Manager:** effective fee shown read-only (KES 50.00) with zero buttons in the card;
  absent when `view_branch_rule` is not held.
- **A11y/responsive/keyboard:** axe serious/critical = 0 in light **and** dark; no horizontal
  overflow at 360/768/1280; `tablist` arrow-key navigable.

Two initial failures were root-caused and fixed (not by weakening assertions): (1) strict-mode —
the landing page also renders a "Billing settings" card link, so the nav assertion was scoped to
`header-primary-nav`; (2) a broad `**/plans**` route stub hung the `/entitlements` request — the
plans-LIST endpoint is now matched by a precise regex; a `branches.max` text match was made
`exact`. Full suite `npm run e2e` **269 passed** (+17, no regressions from the nav change).

## Increment 7 — Full quality gates (COMPLETE, green)

- **Contracts:** `composer api:openapi` (188 production routes) → `npm run api:types` →
  `servana:permission-types` (up to date) → `api:contract:check` OK (157 paths / 188 operations) →
  `servana:permission-types --check` up to date.
- **Backend:** `composer validate --strict` valid; `pint --test` PASS (1040 files);
  `composer stan` (Larastan L8) **No errors** (784 files); `php artisan test` **1164 passed,
  7 skipped, 0 failed** (6309 assertions); `php artisan test --parallel` **1164 passed, 7 skipped**.
- **Frontend:** lint 0 errors; typecheck clean; Vitest 279 passed; build OK; e2e 269 passed.
- **Security:** `composer audit --locked` no advisories; `npm audit --audit-level=high` — **2
  moderate, 0 high/critical** (pre-existing dev dep `@redocly/openapi-core`; below the high gate);
  `gitleaks detect --no-git --redact` no leaks.
- **Docker (sequential):** `docker/php.Dockerfile --target dev` built; `docker/nginx.Dockerfile
  --target prod` built.

**Permission counts (unchanged from Increment 4, re-verified green):** 94 active-status /
77 planned in the matrix; runtime split 93 canonical-active / 14 legacy-active / 77 planned
asserted by `PermissionLegacyKeyReconciliationTest` + `PermissionPlannedKeyIsolationTest`. Nine
Phase-20A canonical keys active; three legacy keys retired. YAML/PHP/DB/TS parity green.

**Lifecycle:** Phase 20A = **local_complete pending PR CI/review/merge**. Not `verified_complete`.

**Residual risks:** (1) four platform nav labels route to the one consolidated screen (tabs), not
distinct deep-links — acceptable per §5.2 (no tab-param in the nav registry); (2) service-scoped
fee-rule creation takes a service ULID as free text (no platform service-picker endpoint in 20A) —
validated server-side (`exists:services,ulid`); (3) the OpenAPI generator still cannot infer
nullability through `?->` — future nullable resource fields must use the explicit-ternary pattern.

## Solo-Maintainer Review Exception - PR #35

- PR: #35
- verified implementation head: a31cd000f84a0a19f1d8b526a4fdf5d01aefc090
- initial successful CI run: 29145005108
- CI/Backend: passed
- CI/Frontend: passed
- CI/Docker: passed
- CI/Security: passed
- CI/E2E - Playwright: passed
- GitHub reviewDecision: intentionally blank
- governance record: docs/governance/solo-maintainer-review-exception-pr-35.md

This exception applies only to Phase 20A and is not independent reviewer
approval. Phase 20B and all later billing, Wallet, compensation and payout
domains remain deferred to their owning phases.
