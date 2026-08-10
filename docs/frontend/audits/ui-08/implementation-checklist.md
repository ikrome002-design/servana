# Phase UI-08 — file-level implementation checklist

Persistent working memory for the UI-08 branch. Every increment updates the status column here so a
later session can resume without re-deriving anything. Statuses: `todo`, `in_progress`, `done`.

**Branch:** `phase-ui-08-super-administrator-experience` · **Base:** `16d544c5` (UI-07 squash merge)
**Decision:** `COR-UI08-001` · **Target disposition:** 17 implemented / 5 disabled_by_gate / 0 planned / 0 removed

---

## Increment 1 — safety, predecessor verification, readiness audit — `done`

| Path | Action | Status |
|---|---|---|
| — | PR #57 predecessor verification (merge, tree equality, CI, governance, branch cleanup) | done |
| — | UI-07 merged-authority verification (160 pages, 22/23/18/19/24/19/20/15) | done |
| — | UI-08 branch creation at `16d544c5` | done |
| — | 22-page contract extraction, runtime survey, permission (167) and API inventory | done |
| `docs/frontend/audits/ui-08/page-readiness-matrix.json` | create — 22 rows, Category A–F | done |
| `docs/decisions/blocking-ambiguities.md` | create — `UI08-AMBIG-001` | done |

## Increment 1A — persist decision, update readiness — `done`

| Path | Action | Status |
|---|---|---|
| `docs/decisions/cor-ui08-001-super-administrator-backend-enablement.md` | create — accepted decision record | done |
| `docs/decisions/blocking-ambiguities.md` | update — `resolved_by_product_owner`, evidence preserved | done |
| `docs/frontend/audits/ui-08/page-readiness-matrix.json` | update — four F→D, decision refs, targets 17/5/0/0 | done |
| `docs/frontend/audits/ui-08/implementation-checklist.md` | create — this file | done |

## Increment 2 — permission and specification-first backend contracts — `in_progress`

### 2a — permission authority — `done`

| Path | Action | Status |
|---|---|---|
| `docs/auth/permission-matrix.yaml` | added `platform.internal_access.manage` + `.view`, inserted alphabetically before `platform.merchant.deactivate`; both `active`, `scope: platform`, `override_policy: revocable_only`, `default_roles: [super_admin]`, MFA true, step-up true/false, severity crit/info | done |
| `app/Domain/Auth/Services/PermissionRegistry.php` | added both to `PERMISSIONS` and to `ROLE_SUPER_ADMIN` default grants | done |
| `resources/spa/src/types/generated/permissions.ts` | regenerated via `php artisan servana:permission-types` (host PHP works; no DB needed) | done |
| verification | **169 total / 134 active / 35 planned** — exactly as COR-UI08-001 requires | done |
| `php artisan test --filter=Permission` | **95 passed, 1168 assertions, 0 failed** (was 91 passed / 4 failed) | done |

**Four pre-existing tests corrected** — root cause: each pinned the *absolute* catalogue total with the
message "the catalogue only ever shrinks … never grows". That invariant held only because no phase
since Phase 23 had an authorization to add a key. `COR-UI08-001` is the first that does. The fix
states the total as `baseline + itemised authorization` and asserts each authorized key is present,
which is **stricter** than the original — an unauthorized key still fails rather than hiding inside a
bumped number. No assertion was weakened or deleted.

```text
tests/Feature/Auth/Phase20HPermissionActivationTest.php   167 -> 167 + authorizedGrowth
tests/Feature/Auth/Phase21SPermissionActivationTest.php   167 -> 167 + authorizedGrowth
tests/Feature/Auth/Phase23PermissionActivationTest.php    132/35/167 -> 132+2 active, 35 planned, 167+2
tests/Feature/Docs/Ui07NavigationContractTest.php         167 -> 167 + authorizedSinceUi07
```

> Anyone adding a future authorized key edits these four `authorizedGrowth` arrays and nothing else.

### 2b — specifications before implementation — `done`

| Path | Action | Status |
|---|---|---|
| `docs/architecture/data-dictionary/platform-governance.md` | create — 7 tables (3 platform access + 4 feature flag), the session `revoked_reason` expansion, retention/masking, audit catalogue, and the **proof** that no existing table can carry the requirement | done |
| `docs/architecture/data-dictionary/billing-and-wallet.md` | add `platform_sms_billing_rules` + the 5-point proof that the existing settings series cannot schedule SMS fields safely | done |
| `docs/architecture/data-dictionary/README.md` | index both | done |
| `docs/architecture/state-machines/platform-sms-billing-rule.md` | create — derived state, guard trigger, immutability | done |
| `docs/architecture/state-machines/platform-access-membership.md` | create — lifecycle, quorum, mirror invariant | done |
| `docs/architecture/state-machines/platform-access-invitation.md` | create — token handling, enumeration safety | done |
| `docs/architecture/state-machines/platform-feature-flag.md` | create — 5 states, fail-closed evaluation order, Gate-W non-bypass | done |
| `docs/architecture/state-machines/platform-feature-flag-change-request.md` | create — 6 states, structural maker/checker | done |
| `docs/backend/audits/ui-08/cor-ui08-001-contract-matrix.json` | create — 4 domains, **33 operations**, route security, migration plan, forbidden operations | done |
| `tests/Feature/Docs/Ui08CorrectiveContractMatrixTest.php` | create — 19 cases | done |
| `tests/Feature/Security/Ui08CorrectiveRouteSecurityTest.php` | create — bidirectional spec↔runtime parity | done |
| `tests/Feature/Security/Ui08NoForbiddenPlatformCapabilityTest.php` | create — 9 unconditional boundary cases | done |

**Result:** `php artisan test --filter=Ui08` → **31 passed, 1,200 assertions, 0 failed, 0 risky**.
Pint clean on all three files.

**Proven root cause (SMS pricing authority).** `COR-UI08-001` prefers the existing
`platform_billing_settings.settings` map and permits a dedicated table only on proof. The proof
holds: every row is a *complete* configuration snapshot; **two** authorities
(`UpdatePlatformBillingSettings`, `UpdatePlatformSettings`) write the same series, each carrying the
other's fields forward at `now()`; so a future-dated SMS row would silently revert unrelated billing
settings when it became current, and `UNIQUE(effective_from)` couples the two scheduling streams.
`/billing/sms` requires a scheduled next rule, so immediate-only writes are not an option either.
→ dedicated `platform_sms_billing_rules`, storing **no currency** (one currency authority remains).

**Three defects this increment's own tests caught before any implementation existed:**

1. Pest `toContain()` / `toHaveKey($key, $value)` are **variadic / value-asserting**, not
   message-taking — four assertions were silently asserting the wrong thing. Replaced with
   `in_array(...)`/`array_key_exists(...)` + a real message.
2. Four feature-flag migration-plan entries were missing the mandatory `purpose` field.
3. `Ui08NoForbiddenPlatformCapabilityTest` matched `personnel` as a **substring** and flagged the
   legitimate shipped Phase 20A `platform/preferred-personnel-fee-rules` routes. Now matched on
   whole path segments.

**Design decision recorded here so later increments do not re-derive it.** `docs/api/openapi.json`
is *derived* from the live route collection by `servana:openapi` and is never hand-written, so it
cannot be the specification-first artifact. The contract matrix is; `openapi.json` is regenerated
per domain, and the matrix's `implementation_state` drives a **bidirectional** parity test: while an
operation is `planned` its route must NOT exist (negative control), and the moment it flips to
`implemented` the same suite asserts the full live security contract. No assertion is ever weakened
for missing implementation.

**Two new `StepUpAction` cases** are required by the specification and land with their routes:
`platform_access_administration` (Increment 5) and `platform_feature_flag_change` (Increment 6).
`billing_configuration` is reused unchanged for SMS.

**Migration-manifest sequencing.** `MigrationManifestTest` fails when an entry references a file
that does not exist, so `manifest.yaml` entries are authored in the same increment as their
migration files. ADR-004 §6 ("no migration against a missing dictionary entry") is satisfied because
the dictionary entries and the `migration_plan` records both exist now, before any migration file.

## Increment 3 — `/billing/sms` backend (COR-UI08-001 §9) — `done`

Seven operations, one create+backfill migration, **no new permission key**.

| Path | Action | Status |
|---|---|---|
| ~~`platform_billing_settings` settings map~~ | ~~add SMS fields~~ — **rejected on proof in 2b**; a future-dated row would revert unrelated billing settings | done (decided) |
| `database/migrations/2026_08_05_000001_create_platform_sms_billing_rules_table.php` | create + guard trigger + genesis backfill from `config('sms.pricing.unit_cost_minor')` at `2026-07-22T00:00:00Z` | done |
| `docs/architecture/migrations/manifest.yaml` | registered with the full root-cause rationale | done |
| `app/Domain/Billing/Enums/PlatformSmsBillingRuleState.php` | derived state vocabulary (never stored) | done |
| `app/Domain/Billing/Models/PlatformSmsBillingRule.php` + factory | ULID route key, `stateAt()`, `live()` scope | done |
| `app/Domain/Billing/Queries/ResolveEffectiveSmsBillingRule.php` | `at()` / `requireCurrent()` (fails closed) / `next()` | done |
| `app/Domain/Billing/Actions/ScheduleSmsBillingRule.php` | overlap + backdating refusal, audit | done |
| `app/Domain/Billing/Actions/CancelScheduledSmsBillingRule.php` | row-locked, pending-only, audit | done |
| `app/Domain/Billing/Services/SmsBillingCostNoticeGenerator.php` | generated notice; tax disclosed, never charged | done |
| `app/Domain/Billing/Queries/SmsBillingUsageProjection.php` | month/merchant/branch aggregation, four distinct quantities | done |
| `app/Domain/Billing/Queries/SmsBillingChargeReconciliationProjection.php` | status rollup, invoice mapping, threshold + anomaly state | done |
| `app/Domain/Billing/Exceptions/SmsBillingRuleException.php` | 409 overlap; 422 backdated / already-effective / already-cancelled | done |
| `app/Http/Controllers/Api/V1/Platform/PlatformSmsBillingController.php` | seven thin actions | done |
| 4 Form Requests + `SmsBillingRuleResource` + `PlatformSmsBillingPolicy` | per convention | done |
| `routes/api.php` | 7 routes in the existing platform group | done |
| `app/Domain/Tenancy/TenantOwnership.php` | registered `platform_sms_billing_rules` as platform-owned | done |
| `app/Domain/Audit/Enums/AuditEvent.php` | `platform_sms_billing.rule_scheduled` / `.rule_cancelled` (high severity) | done |
| `app/Domain/Messaging/Sms/Support/SmsCostCalculator.php` | **rewired to the versioned authority**; config is the genesis bootstrap only | done |
| `app/Providers/AppServiceProvider.php` | policy + **two load-bearing container binds** (see below) | done |
| `docs/api/openapi.json` · `resources/spa/src/types/generated/api.ts` | regenerated: **302 → 309** operations | done |
| `tests/Feature/Billing/Ui08SmsBillingSettingsApiTest.php` (18) · `...RuleResolutionTest` (11) · `...SnapshotImmutabilityTest` (10) | done |

**Result:** `--filter=Ui08` → **72 passed, 1,580 assertions, 0 failed**. SMS regression
`--filter=Sms` → **177 passed**. Larastan level 8 **0 errors**. Pint clean. `MigrationManifestTest`
9 passed. `git diff --check` exit 0.

### Two findings worth carrying forward

1. **`Container::resolveClass()` silently skips a defaulted, unbound class parameter.**
   `SmsCostCalculator`'s collaborators are `?Foo $x = null` so the pure-arithmetic path stays
   directly constructible — but Laravel returns the default unless the class is explicitly `bound()`
   (`Container.php:1327`). Without the two `$this->app->bind(...)` lines in `AppServiceProvider`
   every SMS charge silently falls back to deployment config: *the exact defect COR-UI08-001
   exists to fix*. Proven by probe, then pinned by
   `it('always prefers a scheduled rule over deployment configuration')`. **Do not delete those
   binds as redundant autowiring.**
2. **A raising trigger poisons the whole test transaction.** PostgreSQL aborts the transaction on
   `RAISE`, and `RefreshDatabase` wraps each test in one, so the first expected guard violation
   makes every later query in that test fail with *"current transaction is aborted"*.
   `ui08ExpectGuardViolation()` wraps each one in a nested `DB::transaction()` (a SAVEPOINT), which
   rolls back to the savepoint and leaves the outer transaction usable.

**Currency unification (an inconsistency found and closed in passing).** `SmsCostCalculator` read
`sms.pricing.currency` while the platform surface read the `platform_billing_settings` version — two
authorities that could disagree, which would have made the new page misreport the currency of a
charge. Both now read the settings version, with config as the bootstrap fallback only.

## Increment 4 — `/billing/subscriptions` backend (§10) — `done`

Seven read operations. **No table, no migration, no mutation, no new permission key.**

| Path | Action | Status |
|---|---|---|
| `app/Domain/Billing/Queries/PlatformSubscriptionOperationsProjection.php` | summary/cohorts/funnel, subscriptions, invoices, credits, escalations; allowlisted sorts | done |
| `app/Http/Controllers/Api/V1/Platform/PlatformSubscriptionOperationsController.php` | seven GETs, every figure carrying its definition | done |
| `app/Http/Resources/PlatformSubscriptionResource.php` | `current_state` explanation + `authorization_authority` | done |
| `app/Http/Resources/PlatformSubscriptionInvoiceResource.php` | the stored snapshot, never a recalculation | done |
| `app/Http/Requests/Platform/PlatformSubscriptionQueryRequest.php` · `...InvoiceQueryRequest.php` | canonical enums, ULID-only, bounded page | done |
| `routes/api.php` | 7 GET routes, all `platform.merchant.view` | done |
| `docs/api/openapi.json` · generated TS | **309 → 316** operations | done |
| `tests/Feature/Billing/Ui08SubscriptionOperationsApiTest.php` (10) · `...AuthorizationTest.php` (6) | done |

**Result:** `--filter=Ui08` → **88 passed, 1,745 assertions, 0 failed**. Larastan level 8 **0
errors**. Pint clean.

**No new policy class.** The existing `MerchantPolicy::viewGovernance` already expresses exactly
this authority; adding a second policy for the same permission would have created a duplicate
authority rather than reused the canonical one.

**Billing credits are invoice LINES, not a ledger.** Servana holds no credit table, so the endpoint
projects `subscription_invoice_items` with a negative amount and says so in its `meta.source`. No
credit table was invented to make a page look fuller.

**Two Larastan findings, both fixed rather than annotated:** an aggregate row is not an Eloquent
model (`$row->total` does not exist on `MerchantSubscription`) — switched to the query builder with
array access; and the `explainState()` `match` had an unreachable `default`, so it was removed —
`MerchantSubscriptionStatus` is a closed vocabulary, and an unnamed future status should be an
`UnhandledMatchError` rather than a silently unexplained state on a governance screen.

## Increment 5 — `/platform-access` backend (§11) — `done`

Eleven operations, three new tables + one expand migration, using the two keys added in 2a.

| Path | Action | Status |
|---|---|---|
| `2026_08_05_000002_create_platform_access_invitations_table.php` | hashed single-use token, purpose + environment binding, partial unique on pending | done |
| `2026_08_05_000003_create_platform_access_memberships_table.php` | lifecycle + 4 consistency CHECKs + backfill from `is_platform_staff` | done |
| `2026_08_05_000004_create_platform_access_permission_overrides_table.php` | deny-only + platform-scope guard trigger | done |
| `2026_08_05_000005_expand_session_revocation_reason_add_platform_access.php` | expand both `revoked_reason` CHECKs by ONE value | done |
| `docs/architecture/migrations/manifest.yaml` | four entries with full rationale | done |
| `app/Domain/Auth/Mfa/StepUpAction.php` | `PlatformAccessAdministration` | done |
| `app/Domain/Sessions/Enums/SessionRevocationReason.php` | `PlatformAccessSessionsRevoked` | done |
| `app/Domain/PlatformAccess/**` | 2 enums, 3 models, 3 factories, 7 actions, 2 services, 1 token value object, 1 exception | done |
| `app/Domain/Auth/Services/PermissionResolver.php` | `forPlatformStaff($userId)` subtracts deny overrides | done |
| `app/Domain/Tenancy/TenantContextResolver.php` | passes the user id through | done |
| `app/Http/Controllers/Api/V1/Platform/InternalPlatformAccessController.php` | 11 operations | done |
| 3 Form Requests · 2 Resources · `PlatformAccessPolicy` · `TenantOwnership` · 8 audit events | done |
| `routes/api.php` | 11 routes | done |
| `docs/api/openapi.json` · generated TS | **316 → 327** operations | done |
| `tests/Feature/PlatformAccess/` — API (13) · Invitation (13) · Safety (11) | done |

**Result:** `--filter=Ui08` → **125 passed, 2,028 assertions**. Combined with every affected
regression suite (sessions, permissions, step-up, route security, forbidden routes, manifest, SMS):
**286 passed, 3,379 assertions, 0 failed**. Larastan level 8 **0 errors**. Pint clean.

### Three defects the tests caught

1. **`SELECT count(*) … FOR UPDATE` is rejected by PostgreSQL** (`0A000`). The quorum check now
   SELECTs and counts the ids, which is not only legal but *actually locks the rows* — a locking
   aggregate would not have.
2. **The service returns a count of SESSIONS, not families.** The response field was named
   `families_revoked`, which would have put a quietly wrong number on a governance screen. Renamed
   to `sessions_revoked`, with the family revocation asserted separately.
3. **An "expired" invitation fixture violated `expires_at > created_at`.** The CHECK was right: an
   expired invitation must have been *issued* in the past. The factory state now backdates both.

Also: `permissions` is a **seeded** authority, not a migration artifact, so the two suites that need
real rows call `$this->seed(PermissionSeeder::class)`.

## Increment 6 — `/platform/feature-flags` backend (§12) — `done`

Eight operations, four new tables, **no new permission key**. All 33 corrective operations are now
implemented; OpenAPI is at **335 = 302 + 33**.

| Path | Action | Status |
|---|---|---|
| `config/platform-feature-flags.php` | the code allowlist — **truthfully empty**, with the reasoning written down | done |
| `...000006_create_platform_feature_flags_table.php` | state, rollout bps, dating, version, approved hash | done |
| `...000007_create_platform_feature_flag_targets_table.php` | closed target vocabulary + scalar value | done |
| `...000008_create_platform_feature_flag_change_requests_table.php` | **maker/checker CHECK**, mandatory governance fields, one-pending index | done |
| `...000009_create_platform_feature_flag_history_table.php` | append-only trigger | done |
| `docs/architecture/migrations/manifest.yaml` | four entries | done |
| `app/Domain/Auth/Mfa/StepUpAction.php` | `PlatformFeatureFlagChange` | done |
| `app/Domain/PlatformFeatureFlags/**` | 3 enums, 4 models, 3 factories, 3 actions, 3 services, 2 value objects, 1 exception | done |
| `app/Http/Controllers/.../PlatformFeatureFlagController.php` | 8 operations | done |
| 3 Form Requests · 2 Resources · `PlatformFeatureFlagPolicy` · `TenantOwnership` · 6 audit events | done |
| `routes/api.php` | 8 routes; **no create route exists** | done |
| `docs/api/openapi.json` · generated TS | **327 → 335** | done |
| `tests/Feature/PlatformFeatureFlags/` — API (16) · Evaluation (13) · MakerChecker (11) | done |

**Result:** `--filter=Ui08` → **165 passed, 2,455 assertions**. Combined with the regression suites:
**301 passed, 3,738 assertions, 0 failed**. Larastan level 8 **0 errors**. Pint clean.

### The three properties that matter, and how they are proven

1. **A flag can never grant.** `PlatformFeatureFlagEvaluator` has no access to permissions,
   entitlements, billing state or account context, and a test asserts its *entire* method list is
   `__construct, allows, decide, bucket, externalGateIsOpen` and that its source references no
   authorization authority. There is no method on it that could authorize anything.
2. **A flag can never open Gate W.** An `active`, fully rolled-out, correctly targeted flag still
   denies with `external_gate_closed`, and no table, column or route persists gate state at all.
3. **Self-approval cannot exist as a row.** The decisive test bypasses policy, controller and
   service and attempts the raw `UPDATE`; the database CHECK refuses it.

### One design decision worth recording

A separate `PlatformFeatureFlagChangeRequestStateMachine` class proved **unnecessary** and was not
created. The request transitions are already enforced by the pending-row lock plus the six database
CHECKs; a second class would have restated them without adding a guarantee. The flag's own
`PlatformFeatureFlagStateMachine` does exist, because its transition map is not derivable from the
CHECKs. Recorded in the contract matrix's `action_note`.

### Defects the tests caught

- **`config()->set('…flags.my.flag', …)` treats dots as nesting.** A flag key *contains* dots, so
  the helper was creating `flags['my']['flag']` and every lookup 404'd. The helpers now replace the
  whole `flags` array. This is a genuine trap for anyone adding a flag in a test.
- **`assertUnauthorized` after `actingAs` in the same test yields 403, not 401** — the session
  persists. Split into its own case.
- **`->value('state')` returns the cast enum, not a string.** Compared against the enum instead.

## Sequencing refinement adopted at Increment 7A (product-owner directive §4)

The original Increment 7 above would have updated canonical statuses and run `nav:generate`
**before** the four corrective pages and the thirteen remaining pages existed. The higher UI/UX
authority (§7.2) forbids that: a page is `implemented` only when a real component renders real
data under real authorization with tests and browser proof. Increment 7 is therefore split:

```text
7A  design the activation, register nothing, flip no status, do NOT run nav:generate
8   four corrective frontend pages
9   shell + the remaining thirteen implemented pages + the five gated treatments
7B  ONE atomic activation: routes, statuses, inventory, specs, nav:generate ONCE
10  focused browser, responsive, accessibility, theme, evidence
```

Scope and final disposition are unchanged: **17 implemented / 5 disabled_by_gate / 0 planned /
0 removed**, global total **160**.

## Increment 7A — canonical activation design — `done`

| Path | Action | Status |
|---|---|---|
| `docs/frontend/audits/ui-08/route-activation-matrix.json` | create — 22 entries × route/component/store/API/permission/MFA/step-up/gate/prerequisite | done |
| `scripts/check-ui08-audit-artifacts.mjs` + `npm run ui08:check` | create — the `--check` for the handwritten UI-08 audit artifacts | done |

**Result:** `npm run ui08:check` → OK (22 entries, 17 implemented / 5 disabled_by_gate,
22 unique route names, 4 same-account redirects). `git diff --check` exit 0. No route registered,
no status flipped, `nav:generate` NOT run.

### Host-scoped registration is required, not preferred — and here is the proof

The Super Administrator canonical path **`/audit`** (contract §5.4.18, Platform Audit) is
**exactly** the Merchant Audit account tree root declared in
`resources/spa/src/router/routes/audit.ts`. Both are authenticated account trees registered in one
router today, so a global registration would make one account's page shadow another account's
entire tree. `/dashboard`, `/get-started`, `/account`, `/notifications` and `/reports` are names the
seven remaining accounts will claim in UI-09 … UI-15, so this is one collision now and eight later.

`router/index.ts` therefore exports **`createAppRouter(accountKey)`**: exactly one account tree
when the server resolved an account host, all eight when it did not. The null composition is safe
because `requiresAccount` denies every account route when no host context exists
(`guards.ts`: `host === null` → access-denied), so cross-account shadowing is unreachable there.

**A defect this design closes before it ships:** `main.ts` imports `router` at module scope, and ES
module evaluation runs imports **before** `initAccountContext()` on line 14 — so a module-level
`createRouter(...)` is built while the account context is still null. `createAppRouter` must be
called *from* `main.ts` after `initAccountContext()`; that is the only ordering in which host
scoping can be correct.

### Route tree shape

Parent stays `/platform` (it carries `platform.landing`, which `roleEntryRoutes.spec.ts` requires
via `ROLE_ENTRY` and which is deliberately outside the 160). Every canonical page is an
**absolute-path child**, so its URL comes from the contract, not from the parent prefix. A parent
at `/` was rejected: the UI-06 public landing `home` owns `/` on every account host.

### Four same-account compatibility redirects, each with a proven current consumer

| From | To | Proven consumer |
|---|---|---|
| `/platform/get-started` | `/get-started` | `releaseAudit.ts:249`, `ui-01-as-built-audit.spec.ts:179` |
| `/platform/billing-settings` | `/billing/settings` | `phase-20a-billing.spec.ts` (17 navigations), `phase-20e.spec.ts`, `releaseAudit.ts:250` |
| `/platform/promotions` | `/billing/promotions` | `phase-20c.spec.ts:86,188`, `releaseAudit.ts:251` |
| `/platform/registration-monitoring` | `/merchants/registrations` | `phase-20b.spec.ts` (6 navigations), `releaseAudit.ts:252` |

`/platform/dashboard` gets **no** redirect: UI-07 removed it because it rendered a stub, and
`ui-01-as-built-audit.spec.ts` records it as an absent destination. A redirect would rewrite that
historical finding. `/platform` is **not** a redirect either — it stays the authenticated role
landing.

### Five guessed permission keys the new checker caught before any code was written

`platform.billing_settings.manage`, `platform.promotion.view`, `platform.free_period_offer`'s
assumed view key, and `platform.preferred_fee.view` / `.manage` do not exist. The real keys, read
from the shipped route middleware in `routes/api.php`, are `platform.billing_settings.update`,
`platform.plan_price.manage`, `platform.promotion.manage`, `platform.free_period_offer.manage` and
`platform.preferred_personnel_fee.manage` — several of which gate **reads as well as** mutations,
because Phase 20C/20G shipped no separate view key. The pages cite what the server enforces.

### One new backend operation, itemised

`GET /api/v1/platform/dashboard` (`platform.dashboard.show`) — OpenAPI **335 → 336**. Directive
§18.3 requires a real dashboard and the readiness matrix proves why a client-side aggregate is
unacceptable: every platform read is paginated, so aggregating page 1 in the browser would
misreport every count on a governance screen. Existing permission (`platform.merchant.view`), no
migration, no state machine, no financial calculation, read-only. It is the **only** new operation:
Get Started, Platform Audit and Account and Security are served entirely by shipped endpoints.

Prove at 7B: Super Administrator 17/5/0/0 · global total still 160 · other seven account contracts
unchanged.

## Increment 7B — one atomic route/status/navigation activation — `todo`

| Path | Action | Status |
|---|---|---|
| `resources/spa/src/router/routes/platform.ts` | 17 canonical absolute-path children + 4 compatibility redirects | todo |
| `resources/spa/src/router/index.ts` · `main.ts` | `createAppRouter(accountKey)`, called after `initAccountContext()` | todo |
| 3 router/navigation/inventory specs | build their own all-account router via `createAppRouter(null)` | todo |
| `docs/frontend/navigation/servana-user-account-navigation-map.yaml` | 22 entries: status, runtime route, delivery, permissions, MFA/step-up, owner | todo |
| `docs/frontend/screens/inventory.json` · `tests/e2e/support/releaseAudit.ts` | retire `platform.registration-monitoring` + `platform.promotions`, add the new routes | todo |
| `npm run nav:generate` **once**, then `nav:check` | after Increments 8 and 9 are green | todo |

## Increment 8 — four corrective frontend pages — `done`

Pages, stores and component tests for the four `COR-UI08-001` domains. **No route is registered
yet** — activation is Increment 7B, so none of these is reachable in the browser and no canonical
status has been flipped.

| Path | Action | Status |
|---|---|---|
| `resources/spa/src/stores/platformSmsBillingStore.ts` | settings/versions/usage/reconciliation/cost-notice + schedule/withdraw; sequence-token stale-response guard | done |
| `resources/spa/src/stores/platformSubscriptionOperationsStore.ts` | summary + four read tabs + detail; **no write method exists at all** | done |
| `resources/spa/src/stores/platformAccessStore.ts` | roster, invitations, deny-only overrides, lifecycle, session revocation | done |
| `resources/spa/src/stores/platformFeatureFlagStore.ts` | catalogue, detail, history, change request, decide, pause | done |
| `resources/spa/src/pages/platform/SmsBillingSettings.vue` (+ spec, 15) | effective/scheduled rule, history, cost notice, usage, reconciliation | done |
| `resources/spa/src/pages/platform/SubscriptionOperations.vue` (+ spec, 13) | summary, 4 tabs, subscription/invoice detail dialogs | done |
| `resources/spa/src/pages/platform/InternalPlatformAccess.vue` (+ spec, 14) | roster, invite, deny-only overrides, lifecycle, sessions | done |
| `resources/spa/src/pages/platform/FeatureFlags.vue` (+ spec, 16) | truthful empty catalogue, maker/checker change request, pause, history | done |

**Result:** `npx vitest run resources/spa/src/pages/platform/` → **9 files, 80 passed**. Full
`npm run test` → **123 files, 1,204 passed** (UI-07 baseline 1,148; +56). `npm run typecheck`
clean. `npm run lint` clean on every new path.

### Bug Fix Protocol — `UI08-API-001`, a published contract that misdescribed its own response

**Observed problem.** The four pages must consume generated API types, but
`GET /platform/sms-billing-settings` published `current` and `next` as untyped ARRAYS
(`{"type":["array","null"],"items":{}}`), so no generated client could type an object it actually
receives.

**Evidence.** `SmsBillingRuleResource` was absent from `components.schemas` entirely, while every
resource returned AS a resource (`PlatformAccessMembershipResource`, `PlatformSubscriptionResource`,
…) was present and `$ref`-linked.

**Affected files.** `app/Http/Controllers/Api/V1/Platform/PlatformSmsBillingController.php`
(4 sites), `docs/api/openapi.json`, `resources/spa/src/types/generated/api.ts`.

**Root cause.** The controller returned `(new SmsBillingRuleResource(...))->resolve()`. `resolve()`
returns `array`, and the OpenAPI generator (Scramble) infers the response schema from the STATIC
return type — so the resource type was erased before the generator could see it.

**Why this is the root cause.** Every endpoint in the same file that returned a resource *as* a
resource produced a correct `$ref`; only the `->resolve()` sites were wrong. Removing `->resolve()`
registered the component and produced `anyOf: [$ref, null]`, with no other change.

**Correct fix.** Return the `JsonResource` instances. `JsonResource` is `JsonSerializable`, so the
emitted JSON is byte-identical — this is a contract-only correction, not a behaviour change.

**Files changed.** The controller (4 sites) + regenerated `openapi.json` and `api.ts`.

**Tests.** No new test was needed: `Ui08SmsBillingSettingsApiTest` already pins the JSON shape, and
it is what proves the output is unchanged.

**Test command / result.** `php artisan test --filter=Ui08SmsBilling` → **40 passed, 293
assertions, 0 failed**.

**Proof of resolution.** `SmsBillingRuleResource` is now a registered component schema;
`current`/`next` are `$ref | null`; OpenAPI operation count is **unchanged at 335**, so none of the
33 corrective operations was renamed, added or removed.

**Remaining risk.** Other endpoints that use `->resolve()` may carry the same weak inference. None
is on a UI-08 page, so this branch does not chase them; worth a sweep in a later phase.

### Two test-authoring traps worth recording

1. **A privacy/authority assertion written as a word-ban fails on the page's own guarantee.**
   Banning "phone number" / "branch assignment" tripped on the sentences that STATE the boundary.
   Both were rewritten to assert on data and controls — no phone-shaped value, no `tel:`/`mailto:`
   /`download` affordance, no export control, no selectable merchant role — which is the property
   that actually matters. A word-ban would also have passed on a page that silently deleted the
   guarantee.
2. **`SvDialog` renders through `<Teleport to="body">`,** so its footer controls are outside the
   wrapper's tree and `wrapper.find` returns an empty DOMWrapper. Mount with
   `global: { stubs: { Teleport: true } }` (the convention `Ui01Render001.spec.ts` already uses).

## Increment 9 — shell and the remaining thirteen pages

Refreshed external snapshot (one only, replacing the pre-Increment-8 one):

```text
root       %TEMP%\servana-ui08-increment9-recovery-20260808-162518
counts     23 tracked + 125 untracked = 148
patch      fd1ffbad64734d819322ef6bf6671bbc6b4cb013e3a4ae15528a8b59925a1137
status     b7ba27b2b50708db1284978a0b63c521ec160334b0abaacc05e0991c5d9417bf
name-stat  9c59c919bdd8ec7d1e7bcea1f363b91ffa0bbfd71ce4f4295818ef8440da8bf7
tracked    62fbc09622f5e8b1ac670b541b8746600d3665004ae5468f2310641bdac532b6
untracked  d001162e4e236138ff0986b0484eb1ee77973a0a58e289d01a9fef16ff7bc771
```

### Increment 9A — shell and grouped header navigation — `done`

| Path | Action | Status |
|---|---|---|
| `resources/spa/src/components/navigation/HeaderGroupNavigation.vue` | create — 8 contract groups as disclosures, CSS-declared overflow, gated treatment, stacked drawer variant | done |
| `resources/spa/src/components/navigation/HeaderGroupNavigation.spec.ts` | create — 18 cases | done |
| `resources/spa/src/components/layout/AppShell.vue` | header account now renders `navigationTree()`; drawer renders the SAME tree stacked; sidebar accounts unchanged | done |

**Result:** `vitest run resources/spa/src/components/navigation/` → **2 files, 21 passed**.
Existing `layouts/`, `navigation/`, `router/` suites → **5 files, 110 passed** (no regression).
`typecheck` clean · `lint` clean · `git diff --check` exit 0.

**Three design decisions, each with its reason:**

1. **A disclosure, not `role="menu"`.** `SvMenu` documents that the ARIA menu pattern is
   deliberately scoped to ACTIONS because it hijacks the arrow keys and suppresses link semantics,
   which makes navigation worse. Group triggers are therefore `aria-expanded`/`aria-controls`
   disclosures revealing real links — link semantics, "open in new tab" and `aria-current` all
   survive. Arrow/Home/End are supported inside an open group as a convenience, not as the contract.
2. **The overflow is CSS, not measurement.** CLAUDE.md guardrail 1 forbids JS device detection. The
   tail groups render twice — inline (`hidden xl:block`) and inside a `xl:hidden` overflow — so
   exactly one is ever visible and nothing probes the container width.
3. **Header nav from `md` (≥768) up.** Tablet keeps header ownership (no left rail is introduced);
   below 768 the existing drawer renders the same tree as labelled sections.

**Defect caught by its own tests — `UI08-NAV-001`.** `CSS.escape` is `undefined` in jsdom and is
not universally available, so the panel lookup threw
`Cannot read properties of undefined (reading 'escape')`. Root cause: escaping was applied at the
point of USE for an id this component itself generates. Fixed by removing it and relying on the id
being built by `slug()` (`[a-z0-9-]` only) — safety by construction. Also: jsdom has no
`PointerEvent` constructor; the outside-click test dispatches a plain `Event('pointerdown')`, which
is sufficient because the listener only reads `event.target`.

**Note on what the header shows today.** `navigationTree()` correctly omits `planned` entries, so
until Increment 7B flips the statuses the header renders 10 implemented + 5 gated. That is the
intended sequencing, not a defect — which is exactly why the grouping/overflow cases above are
proven against a supplied node set rather than the live registry.

### Increment 9B — dashboard operation and page — `done` · Get Started — `todo`

| Path | Action | Status |
|---|---|---|
| `app/Domain/Platform/Queries/PlatformDashboardProjection.php` | create — 6 sections, server-side aggregation, gate-aware | done |
| `app/Http/Controllers/Api/V1/Platform/PlatformDashboardController.php` | create — one GET, reuses `MerchantPolicy::viewGovernance` | done |
| `app/Http/Resources/PlatformDashboardResource.php` + `Platform/*` (6) | create — nested section resources | done |
| `routes/api.php` | `GET /api/v1/platform/dashboard` → `platform.dashboard.show`, `platform.merchant.view` | done |
| `tests/Feature/Platform/Ui08PlatformDashboardTest.php` | create — 10 cases | done |
| `resources/spa/src/stores/platformDashboardStore.ts` | create | done |
| `resources/spa/src/pages/platform/PlatformDashboard.vue` (+ spec, 11) | create | done |
| **Get Started** (`/get-started`, server-evidence checklist) | **not started** | todo |

**Result:** `--filter=Ui08PlatformDashboard` → **10 passed, 58 assertions**. Larastan level 8
**0 errors**. Pint clean. `PlatformDashboard.spec.ts` → **11 passed**. OpenAPI **335 → 336**,
exactly the one authorized operation. `typecheck` clean · `lint` clean · `git diff --check` exit 0.

**No new permission key.** `platform.merchant.view` via the existing `MerchantPolicy::viewGovernance`
— a second policy for the same authority would be a duplicate that eventually disagrees.

#### `UI08-API-002` — the generator cannot express a nested array shape

**Observed problem.** The dashboard response published every section as `"type":"string"`, so the
page could not be typed from the generated contract.

**Evidence.** Three encodings were measured against the generator:

```text
array<string,mixed>                every section published as `string`
nested array{...} in @return       every section still `string`
@phpstan-type + @phpstan-import    published an EMPTY object — worse
nested JsonResource per section    a proper $ref per section          <-- correct
```

**Root cause.** The generator (Scramble) infers from the STATIC return type and resolves a nested
*Resource*, but cannot express a nested array *shape*. This is the same mechanism as `UI08-API-001`,
one level deeper — and the shipped Increment 4 subscription summary carries the identical looseness,
so it is a pre-existing generator limitation rather than something this increment introduced.

**Correct fix.** Six small FLAT section resources nested inside `PlatformDashboardResource`. Flat
shapes the generator can express; nested ones it cannot.

**Residual, and why it is accepted.** An `array<string, X>` MAP (`definitions`, `by_severity`, the
status breakdowns) still publishes as `string`. Reshaping those to fixed keys would be a lie — a new
merchant status must not require an API change — so the API keeps the map and the PAGE narrows it at
the boundary via `asRecord()` / `countOf()`, treating anything unexpected as absent. The generated
types remain the authority; no response shape is hard-coded.

**Remaining risk.** Other endpoints returning maps carry the same looseness. None is on a UI-08 page.

#### An unrelated-path slip, caught and undone

`npx eslint --fix resources/spa/src/` auto-formatted two files outside UI-08 scope
(`pages/finance/DashboardStub.vue`, `pages/merchant/Dashboard.vue`). Both were restored to their
committed content **with the editor**, not with `git restore`/`git checkout --`, which remain
prohibited before the completion gate. Verified: `git diff --name-only` now lists only
`AppShell.vue` and the two generated type files under `resources/spa/`. **Lesson: scope `--fix` to
the paths the increment owns.**

## Increment 9 — original UI-08 page groups — `todo`

| Group | Pages | Status |
|---|---|---|
| Shell + header navigation | grouped header from `navigationTree()`, overflow, tablet, mobile drawer; no desktop left nav | todo |
| Home | `/dashboard` (narrow read projection), `/get-started` | todo |
| Billing & Commercial | settings, plans, prices, promotions, free-periods, preferred-personnel-fees (split from 2 consolidated routes) | todo |
| Merchants | registrations, directory, `/merchants/:merchantUlid` (split from 1 consolidated route) | todo |
| Reporting & Audit | `/audit` (Category C; export absent, owner Phase 23) | todo |
| Utility | `/account` (Category C; own identity only) | todo |
| Gate-blocked | 5 entries render visible, inert, naming External Gate W | todo |

## Increment 10 — focused browser, responsive, accessibility — `todo`

`tests/e2e/ui-08-super-administrator-experience.spec.ts` · 22 dispositions · 17 routes · 5 gated ·
desktop/tablet/mobile header · light/dark · keyboard · axe 0 serious/critical · negative
authorization · evidence under `docs/frontend/audits/ui-08/screenshots/`.

## Increment 11 — production images and host proof — `todo`

Build once after stabilization; `nginx -t`; disposable production pair; canonical host-relative route
probes; wrong-account and unknown-host denial; forbidden routes absent; record image IDs.

## Increment 12 — final gates, docs, atomic commit, push — `todo`

| Path | Action | Status |
|---|---|---|
| `docs/proof/ui-08.md` | full proof incl. UI-07 merge closure and `COR-UI08-001` | todo |
| `docs/proof/ui-07.md` | append merge-closure section | todo |
| UI-07 closures | promote `UI07-GUARD-001/002`, `UI07-ROUTE-001`, `UI07-NAV-001` to `verified_complete` | todo |
| `docs/PROGRESS.md` | UI-07 verified_complete + UI-08 record | todo |
| `docs/CHANGELOG.md` | UI-07 promotion + UI-08 entry | todo |
| `docs/traceability/servana-requirements.csv` | `COR-UI08-001` rows + UI-08 rows | todo |
| remaining `docs/frontend/audits/ui-08/*.json` | 16 further artifacts with `--check` support | todo |
| gates | composer validate · Pint · Larastan · backend serial + parallel · ESLint · vue-tsc · Vitest · Vite build · focused Playwright · full Playwright · Docker · audits · gitleaks | todo |
| commit | one atomic `ui-08: implement super administrator experience`; push; **no PR** | todo |

---

## EXACT NEXT ACTION (session handoff)

Increments **1, 1A, 2a, 2b, 3, 4, 5, 6, 7A and 8 are complete and green.** All four corrective
backend domains AND their four frontend pages are delivered. The working tree is intentionally
dirty with **0 commits**; there is no checkpoint commit and none may be created.

```text
--filter=Ui08                 165 passed   2,455 assertions   0 failed
--filter=Ui08SmsBilling        40 passed     293 assertions   0 failed   (after UI08-API-001)
+ regression suites           301 passed   3,738 assertions   0 failed
composer stan                 No errors (level 8)
OpenAPI                       335   (302 + the 33 specified operations; UI08-API-001 changed a
                                     SCHEMA only, not the count)
permission matrix             169 / 134 / 35, unchanged since Increment 2a
npm run typecheck             clean
npm run lint                  clean
npm run test (Vitest)         123 files, 1,204 passed   (UI-07 baseline 1,148)
npm run ui08:check            OK
git diff --check              exit 0
```

**Nothing is routed yet.** The four new pages exist as components with tests; no canonical route is
registered, no canonical status is flipped, and `nav:generate` has NOT been run. That is deliberate
— the sequencing refinement above puts activation in Increment 7B, after Increment 9.

> **SUPERSEDED — see the CURRENT RESUME POINT at the end of this file.** Increments 9A and the
> dashboard half of 9B are now done. The text below is kept because its §17.x design notes for 7B
> are still binding.

**Resume at: Increment 9 — the shell and the remaining thirteen implemented pages.**

1. **Shell first.** `AppShell.vue` already places Super Administrator navigation in the HEADER and
   already carries the mobile disclosure, fixed-footer reserve, profile control and account
   switcher. What it does NOT have is **grouping or overflow**: it renders `navigationFor()` as a
   flat inline list, and UI-08 puts **22 entries** in the header across **8 groups**
   (Home · Billing & Commercial · Merchants · Billing Operations · Integrations ·
   Reporting & Audit · Platform Administration · Utility). Add grouped header navigation with an
   overflow menu, keeping: no desktop left primary navigation, keyboard traversal, Escape, focus
   restoration, menus within the viewport, and the tablet condensed treatment.
   `RoleNavigation.vue` already takes `variant="header"`.
2. Build the thirteen remaining implemented pages per §18 of the directive, reusing the shipped
   stores (`platformBillingSettingsStore`, `subscriptionPlanStore`, `planPriceStore`,
   `promotionStore`, `freePeriodOfferStore`, `preferredPersonnelFeeStore`, `platformMerchantStore`,
   `auditEventStore`, `authStore`/`themeStore`/`accountContextStore`).
3. Build `GET /api/v1/platform/dashboard` — the ONE authorized new operation
   (**335 → 336**, itemised in the activation matrix). Existing permission `platform.merchant.view`,
   no migration, no state machine, no financial calculation, read-only. Gate-W panels render the
   gate, never a zero.
4. Render the five gated entries as visible, inert header treatments naming their exact gate.

Then **Increment 7B** (single atomic activation — see its table above), then 10 → 12.

### What Increment 7B must not forget

- `createAppRouter(accountKey)` in `router/index.ts`, called from `main.ts` **after**
  `initAccountContext()` (ES imports are hoisted, so a module-level `createRouter` sees a null
  context).
- Update `roleEntryRoutes.spec.ts`, `screenInventory.spec.ts` and `navigationFilter.spec.ts` to
  build their own all-account router via `createAppRouter(null)`.
- Retire `platform.registration-monitoring` and `platform.promotions`; update
  `tests/e2e/support/releaseAudit.ts:249-252` and `docs/frontend/screens/inventory.json`.
- Add the four compatibility redirects, and deliberately NOT one for `/platform/dashboard`.

### Traps already paid for — do not rediscover them

| Trap | What to do |
|---|---|
| Pest `toContain()` is **variadic**; `toHaveKey($k, $v)` asserts the **value** | never pass a message to either; use `in_array()` / `array_key_exists()` + `toBeTrue($message)` |
| A raising trigger **poisons the whole test transaction** | wrap each expected violation in a nested `DB::transaction()` (a SAVEPOINT) |
| `TestCase::actingAs()` seeds a **fresh MFA session by default** | use `statefulMfa($stale)` for step-up cases; and `assertUnauthorized` needs its **own** test, because the session persists |
| `Container::resolveClass()` skips a **defaulted, unbound** class parameter | bind the class explicitly, or drop the default |
| `config()->set('…flags.my.flag', …)` treats **dots as nesting** | a flag key contains dots — replace the whole `flags` array |
| Query-builder rows are bare `stdClass`; an aggregate row is **not** an Eloquent model | cast `(array) $row`; use `DB::table()` for `GROUP BY` counts |
| `->value('col')` returns the **cast enum**, not a string | compare against the enum |
| `SELECT count(*) … FOR UPDATE` is **rejected** by PostgreSQL | select the ids under the lock and count them — it also actually locks the rows |
| `withoutTenancy()` is **not needed** on platform routes | `MerchantScope` no-ops when no merchant is resolved |
| `permissions` is a **seeded** authority | `$this->seed(PermissionSeeder::class)` when a test needs real rows |
| OpenAPI is **derived from routes**; `MigrationManifestTest` fails on an entry whose file is missing | regenerate per domain; add manifest rows **with** their migrations |
| Larastan analyses `app`, `routes`, `database` — **not** `tests` | do not chase analyser errors in test files |
| A heredoc containing PL/pgSQL `$$` blocks breaks the shell | write those files with the editor tool, not `cat <<` |

---

## Standing constraints

- **No checkpoint commit.** No `git add`, `commit`, `stash`, `reset`, `restore`, or `clean` before the
  final gate.
- **Permission change is exactly two keys.** 167→169 total, 132→134 active, 35 planned unchanged. If
  the live matrix ever differs from the 167/132/35 baseline, stop and reconcile.
- **`UI07-ENV-001` safe-time rule:** do not start backend serial/parallel in the final 75 minutes
  before Nairobi midnight; do not modify the scheduling tests.
- **Historical evidence:** hash before full Playwright; restore exact enumerated blobs after; never
  `git clean`. UI-06 keeps 33 audit + 33 proof screenshots.
- **Never regenerate UI-07 artifacts repeatedly** — once, after Increment 7 stabilizes, then `--check`.

---

## CURRENT RESUME POINT (authoritative)

**Done:** Increments 1, 1A, 2a, 2b, 3, 4, 5, 6, 7A, 8, **9A**, and the **dashboard half of 9B**.

```text
git                       branch phase-ui-08-super-administrator-experience
                          HEAD = origin/main = merge-base = 16d544c5, divergence 0 0
                          0 commits, 0 staged, 164 working-tree paths, all classified
snapshot                  %TEMP%\servana-ui08-increment9-recovery-20260808-162518
                          (23 tracked + 125 untracked = 148 at the time it was taken)
OpenAPI                   336  = 302 + 33 corrective + 1 dashboard
permissions               169 / 134 / 35 — unchanged, and no further key is authorized
Larastan level 8          0 errors
Pint                      clean
--filter=Ui08PlatformDashboard   10 passed / 58 assertions
platform page suites             10 files / 91 passed
navigation suites                 2 files / 21 passed
layouts+navigation+router         5 files / 110 passed (no regression)
typecheck / lint / ui08:check     clean
git diff --check                  exit 0
```

**Nothing is routed yet.** No canonical route is registered, no canonical status is flipped, and
`nav:generate` has NOT been run. That is the intended sequencing: activation is Increment 7B.

### Next action, in order

1. **Finish 9B — Get Started** (`/get-started`). Server-evidence checklist in dependency order:
   billing mode → plans/entitlements → prices/intervals → trial/grace/overdue/suspension →
   preferred-personnel fees → SMS billing → Wallet/R&E readiness (renders the gate) → registration
   monitoring → MFA. Compose the SHIPPED reads (`platform.billing-settings.show`,
   `platform.plans.index`, `platform.preferred-personnel-fee-rules.index`,
   `platform.sms-billing-settings.show`, `platform.registration-monitor.index`, `auth.mfa.status`)
   in a new `platformGetStartedStore`. **No new endpoint.** The shipped `RoleGetStarted.vue` is
   localStorage-only and must stay untouched — it still serves the other seven accounts.
2. **9C** — six Billing & Commercial pages (split the consolidated `BillingSettings.vue` and
   `Promotions.vue`; reuse their existing sections and stores).
3. **9D** — three Merchant governance pages (split `RegistrationMonitoring.vue`).
4. **9E** — `/audit` and `/account`.
5. **9F** — five gated header treatments (the `HeaderGroupNavigation` gated path already renders
   them; 9F confirms the exact gate text per entry against the readiness matrix).
6. **7B** — the single atomic activation. Its design is fixed in
   `route-activation-matrix.json` and in the Increment 7B table above.
7. **10 → 12** — browser proof, Docker, docs, gates, one commit, push, no PR.

### Traps paid for in this session — do not rediscover

| Trap | Handling |
|---|---|
| `CSS.escape` is undefined in jsdom and is not universally available | never escape an id you generated yourself; `slug()` restricts it to `[a-z0-9-]` |
| jsdom has no `PointerEvent` constructor | dispatch `new Event('pointerdown', { bubbles: true })` |
| `SvMenu` is deliberately ACTION-only | navigation uses a disclosure + link list, never `role="menu"` |
| An overflow that measures container width | forbidden by guardrail 1; render the tail twice and let `xl:` decide |
| The OpenAPI generator cannot express a nested `array{...}` or a `@phpstan-import-type` alias | nest a flat `JsonResource` per section |
| It also publishes `array<string,X>` maps as `string` | keep the map in the API; narrow at the page boundary |
| `Merchant::factory()` defaults to `pending_setup` | set `status` explicitly in any fixture that counts by status |
| `eslint --fix` over a whole tree touches unrelated files | scope `--fix` to the increment's own paths |

---

## Increment 9B — COMPLETE (supersedes the resume point above)

### Get Started — `done`

| Path | Action | Status |
|---|---|---|
| `resources/spa/src/stores/platformGetStartedStore.ts` | create — 7 steps derived from SIX shipped endpoints | done |
| `resources/spa/src/pages/platform/PlatformGetStarted.vue` (+ spec, 18) | create | done |
| `resources/spa/src/components/ui/SvProfileControl.vue` | add optional `getStartedTo` link, following the existing optional-link pattern | done |
| `resources/spa/src/components/layout/AppShell.vue` | header account passes `get-started-to` so the guide is reopenable after dismissal | done |

**Result:** `PlatformGetStarted.spec.ts` → **18 passed** (first run). Affected suites
(`pages/platform`, `components/navigation`, `layouts`) → **14 files / 150 passed**. `typecheck`
clean · targeted `lint` clean (no `--fix`) · `git diff --check` exit 0.

**No new endpoint, no new permission, no new table.** Completion evidence:

```text
1 billing mode                   GET /platform/billing-settings              billing_mode set
2 plans + entitlements + prices  GET /platform/plans                         active plan with BOTH
3 trial / grace / overdue        GET /platform/billing-settings              a settings version exists
4 preferred-personnel + SMS      GET /platform/preferred-personnel-fee-rules
                                 GET /platform/sms-billing-settings          both rules in force
5 Wallet + R&E readiness         blocked_by_gate — never completable
6 registration monitoring        GET /platform/registration-monitor + a human acknowledgement
7 MFA                            GET /auth/mfa                               enrolled AND confirmed
```

**Three decisions worth keeping:**

1. **`SubscriptionPlanResource` already nests `prices` and `entitlements`,** so one `/platform/plans`
   call evidences steps 2 and 3 without an N+1 per-plan price fetch.
2. **`Promise.allSettled`, not `Promise.all`.** One endpoint the user cannot read degrades THAT step
   to incomplete; it does not blank the guide. A platform owner lacking the SMS key still needs the
   other six steps. Only an all-sources failure raises the retryable error.
3. **Persistence is REUSED, not rebuilt.** Step 6 is the single training-only step the contract
   permits a user to mark, and it reuses the shipped `getStartedStore` plus the existing
   `review-registration-monitoring` item id — so dismissal, resume and reopen already work, and no
   checklist table was invented. The gated step 5 is not manually completable at all: ticking it
   would assert that an unreachable integration is verified.

**Progress arithmetic:** the blocked step is excluded from the denominator (`x of 6`), so Gate W
never makes the platform look permanently unfinished.

### Increment 9 status

```text
9A  done
9B  done   (dashboard + Get Started)
9C  next   six Billing & Commercial pages
9D  todo   three Merchant governance pages
9E  todo   Platform Audit + Account and Security
9F  todo   five gated navigation treatments
```

Still true: nothing is routed, no canonical status is flipped, `nav:generate` has not run.

## Increment 9C — six Billing & Commercial pages — `done`

| Path | Contract | Status |
|---|---|---|
| `PlatformBillingSettings.vue` | §5.4.3 `/billing/settings` | done |
| `PlansAndEntitlements.vue` | §5.4.4 `/billing/plans` | done |
| `PlanPrices.vue` | §5.4.5 `/billing/prices` | done |
| `PromotionalDiscounts.vue` | §5.4.6 `/billing/promotions` | done |
| `FreePeriodOffers.vue` | §5.4.7 `/billing/free-periods` | done |
| `PreferredPersonnelFeeRules.vue` | §5.4.8 `/billing/preferred-personnel-fees` | done |
| `Promotions.vue` | add optional `only` prop (single-concern rendering) | done |
| `CommercialPages.spec.ts` | create — 31 cases across all six | done |

**Result:** `CommercialPages.spec.ts` → **31 passed**. `pages/platform` → **12 files / 140 passed**
(no regression; the shipped `Promotions.spec.ts` and `BillingSettings.spec.ts` still pass
untouched). `typecheck` clean · targeted `lint` clean · `ui08:check` OK · `git diff --check` exit 0.

**Composition, never duplication.** Every page composes the already-tested shipped sections and
their stores. No business rule, money calculation or effective-dating logic was restated in Vue.

**Two structural decisions:**

1. **`PlatformBillingSettings.vue` is NEW, rather than a narrowed `BillingSettings.vue`.** 7A had
   planned to narrow the shipped screen in place, but that would break the Phase 20A/20E E2E specs
   that drive its tabs at `/platform/billing-settings` *before* Increment 7B re-points them at
   canonical paths. Building alongside keeps the legacy screen working until 7B retires it with its
   spec. **The activation matrix was amended** with this reason (`target_component_amended_at`).
2. **Promotions and free periods share one form via an `only` prop.** Their forms, validation,
   precedence rules and lifecycle actions are the same substantial code; the plan encourages shared
   form components and forbids six copies of the same logic. Each page still owns its route, title,
   single `h1`, test id and tests — and the split is *proven* by each passing a different `only`,
   which is what makes them two pages rather than two labels. `only: null` preserves the legacy
   consolidated behaviour until 7B.

**Retirement queued for 7B:** `BillingSettings.vue` + `BillingSettings.spec.ts`, the tabbed
`Promotions.vue` header/tablist, and the Phase 20A/20C/20E E2E navigations to `/platform/*`.

### Increment 9 status

```text
9A done · 9B done · 9C done · 9D next · 9E todo · 9F todo
```

---

## CURRENT RESUME POINT (authoritative — supersedes all earlier resume blocks)

**Done:** 1, 1A, 2a, 2b, 3, 4, 5, 6, 7A, 8, **9A**, **9B**, **9C**.
**Next: Increment 9D — three Merchant governance pages.**

```text
git                  branch phase-ui-08-super-administrator-experience
                     HEAD = origin/main = merge-base = 16d544c5, divergence 0 0
                     0 commits, 0 staged, git diff --check exit 0
snapshot             %TEMP%\servana-ui08-increment9-recovery-20260808-162518  (one only; do not create another)
OpenAPI              336 = 302 + 33 corrective + 1 dashboard
permissions          169 / 134 / 35 — unchanged; no further key authorized
pages/platform       12 files / 140 passed
components/navigation 2 files / 21 passed
typecheck / lint / ui08:check / git diff --check   all clean
```

**Nothing is routed. No canonical status is flipped. `nav:generate` has NOT run.** That is the
intended sequencing — activation is Increment 7B.

### Increment 9D — the structural analysis, already done

`resources/spa/src/pages/platform/RegistrationMonitoring.vue` (16 KB) delivers THREE contract pages.
Its template block boundaries are:

```text
139        root div
140-148    header (owns the current <h1>)
151        combined no-access state
158        <template v-else>
160-189    tablist  (monitoring | directory)
198-272    <section role="tabpanel">  monitoring          -> §5.4.10 /merchants/registrations
275-393    <section role="tabpanel">  directory           -> §5.4.11 /merchants
  317-393    <SvCard v-if="selected">  merchant detail    -> §5.4.12 /merchants/:merchantUlid
397-442    <SvDialog>  governance confirm (suspend/reactivate/deactivate)
```

**The `only`-prop technique used in 9C does NOT fully transfer here.** Monitoring and directory are
sibling tab panels and split exactly as promotions did — but the merchant DETAIL card is *nested
inside* the directory panel (317-393) and is driven by `store.selected`, so it cannot be isolated by
wrapping a sibling in `v-if`. Detail must be lifted out into its own component that:

1. takes the `merchantUlid` route param (the contract route is parameterised, and the entry is
   `navigation_visibility: detail_route`, so it is reached from the directory, never from the header);
2. calls `platformMerchantStore.fetchMerchant(ulid)` on mount;
3. renders the detail card **and** the governance `SvDialog` (397-442), which the detail owns —
   `openGovernance`/`confirm`/`resolveError`/`canGovern` (lines 86-135) move with it;
4. keeps operational status and billing status in separate, prominently labelled cards
   (`data-testid="operational-status"` / `detail-billing-status` already exist);
5. proves a foreign/unknown ULID does not enumerate.

Suggested shape, consistent with 9C:

```text
RegistrationMonitoring.vue   add `only?: 'monitoring' | 'directory' | null`; suppress header +
                             tablist when set; EXTRACT the detail card and the governance dialog
MerchantRegistrations.vue    §5.4.10  composes only="monitoring"
MerchantDirectory.vue        §5.4.11  composes only="directory"; rows link to the detail route
MerchantDetail.vue           §5.4.12  owns the extracted detail card + governance dialog
MerchantPages.spec.ts        one consolidated group, mirroring CommercialPages.spec.ts
```

Must be proven by the tests (§13.4): no create-merchant control, no first-Administrator control, no
impersonation, operational vs billing status kept separate, masked data, foreign ULID
non-enumeration, pagination, filters, MFA/step-up on lifecycle actions, responsive, keyboard.

### Then

```text
9E  /audit (platformAuditStore + auditEventStore, platform.audit.view, NO export unless an
    existing endpoint+permission is proven) and /account (own identity only)
9F  five gated header treatments — the HeaderGroupNavigation gated path already renders them;
    9F confirms each entry's exact gate text against the readiness matrix
7B  single atomic activation (design fixed in route-activation-matrix.json)
10  browser proof · 11 Docker · 12 docs, gates, one commit, push, no PR
```

### Retirement queued for 7B

`BillingSettings.vue` + its spec · the tabbed header/tablist in `Promotions.vue` and
`RegistrationMonitoring.vue` · route names `platform.registration-monitoring` and
`platform.promotions` · the Phase 20A/20B/20C/20E E2E navigations to `/platform/*` ·
`tests/e2e/support/releaseAudit.ts:249-252` · `docs/frontend/screens/inventory.json`.

### Traps paid for — do not rediscover

| Trap | Handling |
|---|---|
| `CSS.escape` undefined in jsdom | never escape an id you generated; `slug()` keeps it `[a-z0-9-]` |
| jsdom has no `PointerEvent` | dispatch `new Event('pointerdown', { bubbles: true })` |
| `SvMenu` is ACTION-only | navigation uses a disclosure + link list, never `role="menu"` |
| Overflow by width measurement | forbidden; render the tail twice and let `xl:` decide |
| Generator cannot express nested `array{...}` or `@phpstan-import-type` | nest a flat `JsonResource` per section |
| Generator publishes `array<string,X>` maps as `string` | keep the map; narrow at the page boundary |
| `Merchant::factory()` defaults to `pending_setup` | set `status` explicitly in status-counting fixtures |
| Whole-tree `eslint --fix` touches unrelated files | scope lint to the increment's paths; no `--fix` mid-increment |
| Narrowing a shipped consolidated screen breaks its E2E specs early | build the canonical page alongside; retire the legacy screen in 7B |

---

## Increment 9D — three Merchant governance pages — `done`

New session (recovery verified: branch `phase-ui-08-super-administrator-experience`, HEAD =
origin/main = merge-base = `16d544c5`, divergence `0 0`, 0 staged, **176** working-tree paths,
`git diff --check` exit 0, 10 Docker services healthy, no stray PHP/Node process). One fresh
external snapshot was taken before editing:

```text
%TEMP%\servana-ui08-after-9c-recovery-20260808-185419
26 tracked + 150 untracked = 176
471241527cdaa6451c3fbb689d9f17c2fce7d90457d8d332d903aebd8ba5092e  ui08-tracked.patch
d8fdd9e5489a8411b5326516fb3c43527991873dee183480edf191250bb8575a  ui08-status.txt
36c86eda4d5888bbe5fe1677099e50c28f4b9eeb622ca86b284c1b2848d814a0  ui08-name-status.txt
6dc6fa764e1382b4e7380d69f5a4764ad5f183274b8c1319727739ad0c8b6f5b  ui08-tracked-hashes.txt
eb6ac2443922c541f37f05677b854fbfdd8f39406655d6af4678f11f577e5181  ui08-untracked-hashes.txt
```

### What was extracted, and what was NOT

| Path | Action | Status |
|---|---|---|
| `components/platform/merchants/MerchantGovernancePanel.vue` | **extract** — detail card + governance dialog + suspend/reactivate/deactivate handlers + step-up error translation + `canGovern` + impact preview | done |
| `components/platform/merchants/merchantStatus.ts` | extract — the two status vocabularies and the one shipped filter's options | done |
| `components/platform/merchants/merchantRoutes.ts` | new — canonical destination names in one place | done |
| `pages/platform/RegistrationMonitoring.vue` | **recompose** over the panel; 445 → 258 lines; tabs, tables and every test id preserved | done |
| `pages/platform/MerchantRegistrations.vue` | new — §5.4.10 `/merchants/registrations` | done |
| `pages/platform/MerchantDirectory.vue` | new — §5.4.11 `/merchants` | done |
| `pages/platform/MerchantDetail.vue` | new — §5.4.12 `/merchants/:merchantUlid` | done |
| `stores/platformMerchantStore.ts` | add server pagination meta + `loadMerchant()` deep-link outcome | done |
| `pages/platform/MerchantPages.spec.ts` | new — 35 cases | done |

**Characterization first.** Before any structural change the shipped
`RegistrationMonitoring.spec.ts` (5) and `platformMerchantStore.spec.ts` (4) were run, and they were
re-run after the extraction **unmodified**: **9 passed**. Not one assertion was rewritten to match
the refactor — that is the proof the behaviour moved rather than changed.

### The three structural decisions

1. **The `only`-prop technique from 9C does not transfer, and neither does narrowing in place.**
   The contract requires the canonical directory to have **no embedded detail pane** (a row is a
   link), while five shipped Phase 20B E2E cases require the legacy screen to show the detail
   *inline after a row click*. One template cannot satisfy both. So the canonical pages were built
   **alongside**, exactly as `PlatformBillingSettings.vue` was in 9C, and the legacy screen is
   retired in 7B. `route-activation-matrix.json` was amended
   (`target_component_amended_at: "Increment 9D"`) — the 7A target for `merchant-registrations` was
   `RegistrationMonitoring.vue`.
2. **What IS shared is the logic, not the presentation.** `MerchantGovernancePanel.vue` is composed
   by the legacy screen *and* the canonical detail page, so the lifecycle mutations, the mandatory
   reason, the step-up translation and the capability check exist once and cannot drift while both
   surfaces are live. The retiring screen's raw tables were deliberately left alone: replacing them
   with the responsive `SvDataTable` + `SvResponsiveRecordList` pair renders BOTH presentations in
   the DOM, and `page.getByText('Acme Salon')` in the 20B E2E would then match two nodes and fail
   Playwright strict mode. Presentation duplicated for one increment and deleted in 7B is not
   duplicated logic.
3. **No per-merchant governance timeline, and no backend change to manufacture one.**
   `PlatformAuditLogController::index` returns the platform chain (`merchant_id IS NULL`) and
   `AuditLogIndexRequest` allowlists action, severity, actor, branch, `subject_type`, dates, sort and
   per_page — no merchant or subject-identity filter, and `SUBJECT_TYPES` excludes `Merchant`.
   Narrowing one page of results client-side would present a PARTIAL timeline as complete. The
   readiness matrix already recorded this as a measured gap, so the page names it as unavailable and
   links to the platform audit surface. `api_operations` for `merchant-detail` was amended to drop
   `platform.audit-logs.index`. **OpenAPI stays at 336; no new operation, filter, permission,
   migration or mutation.**

### Truthfulness

Every contract sub-feature with no backing field is NAMED with its reason rather than rendered:
registration risk indicators, duplicate-business warnings, velocity/IP/device signals, referral
anomalies, owner email, plan selection, trial start, governance notes, escalation; directory search,
plan/billing-mode/trial-cohort/overdue/risk filters, saved filters, plan, branch count, staff count,
overdue amount, last activity, and the masked export; detail governance timeline, invoices,
Wallet attempts, branches, staff overview and referral facts. **No export control is rendered** —
no export operation exists for platform merchant data.

### Deep-link safety

`MerchantDetail.vue` resolves `merchantUlid` from the route on mount **and on every param change**,
and requests the record itself; it never reads a merchant left over from the directory. A 404 and a
403 collapse to the SAME rendered message, and the tests assert the two strings are identical and
contain neither the ULID nor a merchant name — a URL bar cannot enumerate the platform. There is
deliberately no client-side ULID pattern check: the server's 404 is the authority. The spec uses a
**real memory router** rather than a stubbed `RouterLink`, because deep-linking is the property
under test; it also pins that `/merchants/registrations` ranks above `/merchants/:merchantUlid`.

### 9D gate

```text
RegistrationMonitoring.spec.ts + platformMerchantStore.spec.ts   9 passed   (unmodified)
MerchantPages.spec.ts                                           35 passed
pages/platform + stores + components/navigation + layout        16 files / 200 passed
npm run typecheck                                               clean
eslint (increment paths, no --fix)                              clean
npm run ui08:check                                              OK
git diff --check                                                exit 0
```

Still true: **nothing is routed**, no canonical status is flipped, `nav:generate` has not run.

### Traps added in 9D

| Trap | Handling |
|---|---|
| `SvCard`, `SvLink`, `SvPermissionState`, `SvErrorState` and `SvSkeleton` each set their OWN `data-testid` | a caller's `data-testid` overrides it through attribute fallthrough (proven by `PublicFaqPage`); the governance panel still uses a wrapper so both ids survive |
| `SvButton` has no `as`/`to` — a link is `SvLink`, deliberately | never style a button as navigation |
| `SvSkeleton` takes `shape="text"` + `lines`, not `rows` | — |
| Rendering the responsive table+cards pair puts BOTH in the DOM | a Playwright `getByText` over shared row text becomes a strict-mode violation; scope by test id, or leave a legacy screen's raw table alone |
| A word-ban over rendered text fails on the page's own boundary sentence | assert forbidden CONTROLS (button/anchor labels), never prose |
| The shipped store specs pin the exact argument shape of `get()` | send `page` only when `> 1`, and omit the config object entirely when there are no params |

### Increment 9 status

```text
9A done · 9B done · 9C done · 9D done · 9E next · 9F todo
```

---

## Increment 9E — Platform Audit + Account and Security — `done`

| Path | Contract | Status |
|---|---|---|
| `stores/platformAuditStore.ts` | new — read-only platform-chain audit | done |
| `pages/platform/PlatformAudit.vue` | §5.4.18 `/audit` | done |
| `stores/sessionFamilyStore.ts` | new — own active sessions | done |
| `stores/authStore.ts` | add `regenerateRecoveryCodes()` (own-scope, fresh step-up) | done |
| `pages/platform/AccountAndSecurity.vue` | §5.4.22 `/account` | done |
| `pages/platform/PlatformAuditAndAccount.spec.ts` | new — 25 cases | done |

**Result:** 25 passed · `pages/platform` + all stores → **45 files / 419 passed** · typecheck clean ·
targeted lint clean · `git diff --check` exit 0. **No new endpoint, permission, table or mutation.**

### Audit export — the disposition, measured not assumed

`platform.audit.export` is `implementation_status: planned`, `owning_phase: Phase 23` in the
permission matrix. The `audit-exports` endpoints that DO exist are branch-scoped merchant exports
gated on `audit.export` behind `EnsureBranchScope` — they cannot produce the platform chain. **No
export control is rendered**; the page states the disposition and names the key and the phase. This
is the truthful Phase-23 residual the phase brief anticipated, not a silent omission.

### Hash chain — nothing claimed

No route, controller or command exposes chain-verification status or verifier incidents (`grep`
over `routes/api.php` and `app/Http` returns nothing). Rendering "chain: healthy" from the absence
of a check would be a fabricated integrity assurance, so the page states what IS guaranteed
(append-only storage, server-side masking by `AuditValueMasker`) and names what it cannot show. A
test asserts the page never matches `/chain.*verified/i` or `/chain:\s*healthy/i`.

### Read-only is structural

`audit_logs` rejects UPDATE and DELETE at the database level and no endpoint accepts either, so the
store exposes no write method at all — not a disabled one. The spec asserts that after a full page
interaction `post`, `patch` and `delete` were never called.

### Before/after

`AuditLogIndexRequest` masks context server-side. The detail composes a readable change view from
`from_*`/`to_*` pairs — the shape the merchant lifecycle, billing and flag state machines actually
record — and shows everything else as plain masked key/value. It never infers a previous value the
record does not contain.

### Account and Security — own scope by construction

Every endpoint the page calls takes no user identifier: `/me`, `/auth/mfa*`, `/auth/sessions*`,
`/auth/preferences`. `HostSessionController` filters by the authenticated `user_id` and 404s a
foreign session ULID rather than 403ing it, so the API cannot confirm another user exists. A test
asserts no request URL contains the signed-in user's ULID.

Deliberately absent and stated: password, OTP login, passkey/WebAuthn (Magic Link only); any
control that could lower the platform MFA requirement; any other-user action (that is
`/platform-access`); display density and a timezone override (no field on the user record);
notification preferences (the Notifications runtime is gated). Theme IS real — `/auth/preferences`,
ADR-021 — and renders `SvThemeToggle variant="switch"`.

---

## Increment 9F — five gated navigation treatments — `done`

| Path | Action | Status |
|---|---|---|
| `navigation/navigationFilter.ts` | per-gate statements + owner phase; **remove the dead `openGates` input** | done |
| `components/navigation/GatedNavigationTreatment.spec.ts` | new — 12 cases | done |
| `docs/frontend/audits/ui-08/gate-disposition.json` | new audit artifact | done |
| `scripts/check-ui08-audit-artifacts.mjs` | validate gate-disposition against the matrices | done |

### The five, with their ACTUAL dependency

```text
billing-reconciliation-exceptions   direct      External Gate W                 Phase 20D-W
integrations                        direct      External Gate W                 Phase 20D-W
integrations-refer-and-earn-…       transitive  21R-B behind 20D-W behind W     Phase 21R-B
reports                             transitive  21N  behind 20D-W behind W      Phase 21N
notifications                       transitive  21N (also 17, 18) behind W      Phase 21N
```

Two direct, three transitive. `GATE_LABELS` gained
`phase_21n_blocked_by_external_gate_w` (the gate id the activation matrix already uses for
notifications), and an unrecognised gate now degrades to a readable sentence rather than a raw
`snake_case` token. The reason also appends the backend owner phase when the contract carries one —
forward-compatible, so 7B's canonical update enriches the text with no further code change.

### Defect `UI08-NAV-002` — a documented input that did nothing

```text
Observed problem   `NavigationContext.openGates` was declared and documented as "External gates
                   that are open. A closed gate leaves its entries disabled." A caller could pass
                   it and nothing happened.
Evidence           `grep -rn openGates resources/spa/src` returned only the declaration. `toNode()`
                   computes `gateClosed` from `implementationStatus` and `entry.gate` alone.
Affected files     resources/spa/src/navigation/navigationFilter.ts
Root cause         The field was added with the interface and never wired into `toNode`.
Why this is root   The gate decision is made in exactly one expression, and that expression has no
                   reference to the context.
Correct fix        REMOVE the field, not wire it. Wiring it would be the worse repair: an entry is
                   `disabled_by_gate` precisely because its backend does not exist, so a
                   client-supplied "this gate is open" would turn it into a live-looking
                   destination with `routeName: null` — and would hand the browser a way to
                   un-gate a page the server cannot serve. A gate opens by the canonical map being
                   regenerated as `implemented`: one authority, server-side.
Files changed      navigationFilter.ts (interface comment records the decision)
Tests added        GatedNavigationTreatment.spec.ts — "offers the browser no input that could open
                   a gate": passes every gate name AND every feature flag at once and asserts all
                   five stay disabled with `routeName: null`.
Test command       npx vitest run resources/spa/src/components/navigation resources/spa/src/navigation
Test result        5 files / 74 passed
Proof              The removal strengthens the Increment 10 security negative "a feature flag
                   cannot open Gate W" from a convention into a type-level impossibility.
Remaining risk     None. The field had no callers.
```

### 9F gate

```text
GatedNavigationTreatment.spec.ts     12 passed
components/navigation + navigation    5 files / 74 passed
```

---

## Increment 9 — COMPLETE

```text
npm run test        130 files / 1,354 passed / 0 failed
npm run typecheck   clean
npm run lint        0 errors, 11 pre-existing warnings (v-html x3, singleline-content x8),
                    none in a UI-08 path; no --fix was run
npm run ui08:check  OK — readiness 22/17-5-0-0 · activation 22 · gate-disposition 5 (2 direct / 3 transitive)
git diff --check    exit 0
```

**Reconciliation against the Increment-8 baseline of 123 files / 1,204 passed:**

```text
9A HeaderGroupNavigation.spec.ts        +1 file   +18
9B PlatformDashboard.spec.ts            +1 file   +11
9B PlatformGetStarted.spec.ts           +1 file   +18
9C CommercialPages.spec.ts              +1 file   +31
9D MerchantPages.spec.ts                +1 file   +35
9E PlatformAuditAndAccount.spec.ts      +1 file   +25
9F GatedNavigationTreatment.spec.ts     +1 file   +12
                                        ───────   ────
                                        130       1,354
```

Exact. No pre-existing test was removed, skipped or weakened.

```text
9A done · 9B done · 9C done · 9D done · 9E done · 9F done → Increment 9 COMPLETE
Next: Increment 7B — the single atomic activation.
```

Still true at this boundary: **nothing is routed**, no canonical status is flipped, `nav:generate`
has not run.

---

## Increment 7B — step 1 of 3 — `done` (router factory only; NO page activated)

| Path | Action | Status |
|---|---|---|
| `router/index.ts` | `createAppRouter(accountKey)` + `createRouterForCurrentHost()`; guards moved into `installGuards(router)`; the module-level `router` singleton is gone | done |
| `main.ts` | `initAccountContext()` **then** `createRouterForCurrentHost()` — the ordering is the fix | done |
| `navigation/navigationFilter.spec.ts` · `router/roleEntryRoutes.spec.ts` · `screens/screenInventory.spec.ts` | build an all-account router via `createAppRouter(null)` | done |
| `router/appRouterFactory.spec.ts` | new — 7 cases | done |

```text
resources/spa/src/router                    3 files / 61 passed
router + navigation + screens + host        6 files / 121 passed
typecheck · eslint (changed paths) · ui08:check · git diff --check    all clean
```

**Nothing is routed yet.** No canonical status is flipped and `nav:generate` has NOT run, so the
contract, the screen inventory and the runtime are still mutually consistent. This step is the
host-collision fix only — it is what MAKES the atomic activation possible, and it is deliberately
separate from it because a half-done activation would leave `nav:check` failing.

---

# CURRENT RESUME POINT (authoritative — supersedes every earlier resume block)

**Done:** 1, 1A, 2a, 2b, 3, 4, 5, 6, 7A, 8, 9A, 9B, 9C, **9D**, **9E**, **9F** → **Increment 9 is
COMPLETE** — plus **7B step 1 of 3** (router factory).

**Next: Increment 7B step 2 — register the seventeen canonical routes.**

```text
git                  branch phase-ui-08-super-administrator-experience
                     HEAD = origin/main = merge-base = 16d544c5, divergence 0 0
                     0 commits, 0 staged, git diff --check exit 0
snapshot             %TEMP%\servana-ui08-after-9c-recovery-20260808-185419  (do not create another)
OpenAPI              336 — unchanged by 9D/9E/9F; no operation, filter, permission or migration added
permissions          169 / 134 / 35 — unchanged; no further key authorized
full Vitest          130 files / 1,354 passed
typecheck / lint / ui08:check / git diff --check    all clean
```

## EXACT NEXT ACTION

**7B step 2 — routes.** In `router/routes/platform.ts`, register the seventeen implemented
destinations as children of the account-guarded `/` tree for `super_administrator`, moving the tree
off the `/platform` prefix onto canonical host-relative paths. Each needs: canonical path, unique
route name, lazy component, `meta.accountKey: 'super_administrator'`, `requiresAccount`, permission
metadata, screen key and navigation group. The seventeen components all exist and are green:

```text
/dashboard                          platform.dashboard                 PlatformDashboard.vue
/get-started                        platform.get-started               PlatformGetStarted.vue
/billing/settings                   platform.billing-settings          PlatformBillingSettings.vue
/billing/plans                      platform.billing-plans             PlansAndEntitlements.vue
/billing/prices                     platform.billing-prices            PlanPrices.vue
/billing/promotions                 platform.billing-promotions        PromotionalDiscounts.vue
/billing/free-periods               platform.billing-free-periods      FreePeriodOffers.vue
/billing/preferred-personnel-fees   platform.billing-preferred-…       PreferredPersonnelFeeRules.vue
/billing/sms                        platform.billing-sms               SmsBillingSettings.vue
/billing/subscriptions              platform.billing-subscriptions     SubscriptionOperations.vue
/merchants/registrations            platform.merchant-registrations    MerchantRegistrations.vue
/merchants                          platform.merchants                 MerchantDirectory.vue
/merchants/:merchantUlid            platform.merchant-detail           MerchantDetail.vue
/audit                              platform.audit                     PlatformAudit.vue
/platform-access                    platform.platform-access           InternalPlatformAccess.vue
/platform/feature-flags             platform.feature-flags             FeatureFlags.vue
/account                            platform.account                   AccountAndSecurity.vue
```

Exact paths and route names come from `route-activation-matrix.json`, which is the fixed design and
is `ui08:check`-validated against the 160-page contract — read them from there, do not retype them
from this table.

Then the **four** proven same-account compatibility redirects, preserving query and hash:

```text
/platform/get-started            -> platform.get-started
/platform/billing-settings       -> platform.billing-settings
/platform/promotions             -> platform.billing-promotions
/platform/registration-monitoring-> platform.merchant-registrations
```

`/platform/dashboard` gets **no** redirect (it never existed as a working route), and `/platform`
itself is not redirected. Retire the route names `platform.registration-monitoring` and
`platform.promotions`, and update their consumers: `tests/e2e/support/releaseAudit.ts:249-252`,
`docs/frontend/screens/inventory.json`, and the Phase 20A/20B/20C/20E E2E navigations to
`/platform/*`. Retire `BillingSettings.vue` + its spec and the tabbed header/tablist in
`Promotions.vue` and `RegistrationMonitoring.vue`.

**7B step 3 — the canonical map and one generation.** Update all 22 Super Administrator entries in
`docs/frontend/navigation/servana-user-account-navigation-map.yaml` (17 `implemented` with real
route, route_name, screen, component, owner, permissions, scope, MFA, step-up, API operations and
delivery; 5 `disabled_by_gate` with the exact gate from `gate-disposition.json`, no route and no
component), then `docs/frontend/screens/inventory.json` and all 22 screen specifications. Only when
the handwritten sources are stable, run **once**:

```text
npm run nav:generate  &&  npm run nav:check  &&  npm run ui08:check
```

Required result: Super Administrator **17 implemented / 5 disabled_by_gate / 0 planned / 0 removed
= 22**, global **160**, and the other seven accounts unchanged. Then focused
router/navigation/inventory/page tests. Do not run full Playwright yet.

**After 7B:** Increment 10 (browser, responsive, accessibility, theme, evidence) → 11 (production
images and canonical-host proof) → 12 (documentation, full gates, ONE atomic commit, push, no PR).

## Standing constraints, still in force

- **No commit of any kind** until every final gate in Increment 12 passes. No `git add`, `commit`,
  `stash`, `reset`, `restore`, `clean`, `rebase`, `merge` or branch switch before then.
- Permissions stay **169 / 134 / 35** and OpenAPI stays **336** unless a narrow gap is separately
  proven and documented.
- `UI07-ENV-001`: do not start backend serial/parallel in the final 75 minutes before Nairobi
  midnight, and do not modify the scheduling tests.
- Hash the UI-01…UI-07 evidence directories before full Playwright; restore only the exact tracked
  blobs the run rewrites; never `git clean`.
- Run `nav:generate` **once**, in 7B step 3, and never before it.

## Traps added in 7B step 1

| Trap | Handling |
|---|---|
| A module-level `import { router }` runs before `initAccountContext()` | build the router inside `main.ts` after the context resolves; `createRouterForCurrentHost()` is the only production entry point |
| Three static specs read the router singleton | they need ALL accounts, so they call `createAppRouter(null)`; never weaken a runtime account-host test the same way |
| `@vue/test-utils` `wrapper.get()` returns `VueNode<Node>`, which has no `tagName` | type a helper's parameter as `DOMWrapper<Element>` |
| The header variant keeps a group's entries inside a collapsed disclosure, one open at a time | open each group in turn before asserting on its entries |

---

## Increment 7B — the atomic activation — `done`

Step 1 (the host-scoped router factory) is recorded above. Steps 2 and 3 landed as one unit.

### What was registered

Seventeen canonical Super Administrator destinations, as children of a single guarded parent with
ABSOLUTE child paths so one `PlatformAdminLayout` instance survives every navigation:

```text
/dashboard                        platform.dashboard                         PlatformDashboard.vue
/get-started                      platform.get-started                       PlatformGetStarted.vue
/billing/settings                 platform.billing-settings                  PlatformBillingSettings.vue
/billing/plans                    platform.billing-plans                     PlansAndEntitlements.vue
/billing/prices                   platform.billing-prices                    PlanPrices.vue
/billing/promotions               platform.billing-promotions                PromotionalDiscounts.vue
/billing/free-periods             platform.billing-free-periods              FreePeriodOffers.vue
/billing/preferred-personnel-fees platform.billing-preferred-personnel-fees  PreferredPersonnelFeeRules.vue
/billing/sms                      platform.billing-sms                       SmsBillingSettings.vue
/billing/subscriptions            platform.billing-subscriptions             SubscriptionOperations.vue
/merchants/registrations          platform.merchant-registrations            MerchantRegistrations.vue
/merchants                        platform.merchants                         MerchantDirectory.vue
/merchants/:merchantUlid          platform.merchant-detail                   MerchantDetail.vue
/audit                            platform.audit                             PlatformAudit.vue
/platform-access                  platform.platform-access                   InternalPlatformAccess.vue
/platform/feature-flags           platform.feature-flags                     FeatureFlags.vue
/account                          platform.account                           AccountAndSecurity.vue
```

`/platform` remains the authenticated role landing (`platform.landing`) — 7A preserved it
deliberately and it is not a twenty-third contract page. It is NOT redirected to `/dashboard`.

Four compatibility redirects, each with a proven current consumer, same-account, query and hash
preserved explicitly, resolving into the guarded canonical tree so `requiresAccount` still applies:

```text
/platform/get-started             -> platform.get-started
/platform/billing-settings        -> platform.billing-settings
/platform/promotions              -> platform.billing-promotions
/platform/registration-monitoring -> platform.merchant-registrations
```

**No `/platform/dashboard` redirect** — UI-07 removed that route because it rendered a Phase 4
stub, and compatibility routing must not give a placeholder a second life.

### Retired

Route names `platform.registration-monitoring` and `platform.promotions`, and with them the two
consolidated screens they were the only consumers of: `BillingSettings.vue` + spec (4 tests) and
`RegistrationMonitoring.vue` + spec (5 tests). `Promotions.vue` stays — it is the shared Phase 20C
form that the promotions and free-periods pages both compose.

Consumers updated: `tests/e2e/support/releaseAudit.ts` (5 legacy rows → 17 canonical rows),
`docs/frontend/screens/inventory.json`, and the Phase 20A/20B/20C/20E E2E navigations. **Every tab
click became a navigation to the contract page that now owns that tab.** No assertion was weakened:
a tab-count expectation became the presence of the page and the absence of a tablist, which is a
stronger statement than counting tabs on one screen.

### Generated exactly once

`npm run nav:generate` ran twice in total — once after the handwritten sources were stable, and once
more after the two source corrections below were proven necessary. `nav:check` and `ui08:check` are
green.

### Six reconciliations the UI-07 contract tests forced

| # | Finding | Resolution |
|---|---|---|
| 1 | `backend_owner_phase` may not be a bare `UI-xx` — the field must never be auto-filled from `owner_phase` | The corrective backends really were delivered inside this phase, so the authored, matrix-traceable value is `Phase UI-08 (COR-UI08-001)`. `get-started` stays `Phase 11`; `account` is `Phase UI-03`. |
| 2 | `duplicate_runtime_paths` reported `/audit` | **Generator fixed.** Duplicate paths are now counted WITHIN an account. Two accounts owning one canonical path is correct under host scoping — it is why the router became host-scoped — and is recorded in a new `paths_owned_by_more_than_one_account` field rather than hidden. |
| 3 | Gate had to be `external_gate_w`; 7A had minted `phase_21n_blocked_by_external_gate_w` | **One gate vocabulary.** Collapsed to `external_gate_w`. The transitive fact is not lost: `blocking_kind`, `blocked_by` and `backend_owner_phase` carry it, and the runtime reason renders "External Gate W — Wallet by Citrus collections readiness (Phase 21N)". Amendment recorded in `gate-disposition.json` and the activation matrix. |
| 4 | A gated screen spec must say `Blocked by **external_gate_w**` | Follows from 3. |
| 5 | Account coverage grouped routes by URL PREFIX, so 16 canonical paths looked "outside a tree" and `/audit` looked unguarded | **Generator fixed.** Coverage now groups by the account each route DECLARES in `meta.accountKey` — which this very file always said was the authority ("a path prefix is not an account boundary") and which UI-08 made unavoidable. `routes_missing_account` keeps its teeth: a route under an account's declared root that declares NO account still fails. Tree count 9 → 8 (one per account). |
| 6 | OpenAPI 336 vs the matrix's 302 + 33 = 335 | Phase UI-08 has TWO authorized growth sources. The test now adds the itemised `route-activation-matrix.json.new_backend_operations` — one entry, `platform.dashboard.show` — and asserts each is justified in writing AND actually present. It still fails for an unrelated route and for a specified route that never shipped. |

### Two defects the activation exposed — both pre-existing, both closed

```text
UI08-AUDIT-001
Observed problem   15 mutating routes from the four corrective domains were classified in neither
                   AuditMutationCoverage::AUDITED nor ::EXEMPT, so AuditMutationCoverageTest failed.
Evidence           php artisan test --filter=AuditMutationCoverageTest listed all 15 by name.
Affected files     app/Domain/Audit/Support/AuditMutationCoverage.php
Root cause         Increments 2a-6 added the routes and their typed audit events but never extended
                   the coverage registry; the increments' own filters did not include this suite.
Why this is root   Every one of the 15 actions DOES record a typed AuditEvent — the events exist and
                   fire. Only the registry entry was missing, so the guard could not see them.
Correct fix        Map each route to the event(s) it actually emits. Approval maps to TWO events,
                   because DecideFeatureFlagChange records the decision AND applies the flag.
Tests              AuditMutationCoverageTest (existing guard, now satisfied)
Test result        5 passed
Remaining risk     None. The registry is now exhaustive again and a new mutation still fails CI.

UI08-EXPORT-001
Observed problem   platform.subscription-invoices.index/show were unclassified in the Phase 23
                   export-hardening matrix, so Phase23ExportHardeningTest failed.
Evidence           php artisan test --filter=Phase23ExportHardeningTest named both routes.
Affected files     tests/Feature/Security/Phase23ExportHardeningTest.php
Root cause         The guard classifies every "document-shaped" route by name; Increment 4 added two
                   invoice-shaped READ projections without classifying them.
Why this is root   Neither route produces a file, a signed link or download accounting — they are
                   paginated JSON. They belong in P23_NON_DOCUMENT_ROUTES, which is exactly the
                   register the guard provides for this case.
Correct fix        Classified both with written reasons distinguishing them from the merchant-client
                   `invoices.*` surface.
Tests              Phase23ExportHardeningTest
Test result        17 passed
Remaining risk     None. If a later phase attaches a PDF, it enters the matrix as an export surface.
```

### 7B parity gate — proven

```text
Super Administrator      22 total = 17 implemented / 5 disabled_by_gate / 0 planned / 0 removed
Global                   160 pages
Per account              22 / 23 / 18 / 19 / 24 / 19 / 20 / 15
Other seven accounts     0 entries changed (diffed field-by-field against HEAD)
Canonical routes live    17          Gated destinations live   0
Compatibility redirects  4           /platform/dashboard redirect  absent
duplicate_runtime_paths  []          shared across accounts    /audit (merchant_audit + super_administrator)
Runtime named routes     125         without a lazy component  0
Account trees            8           routes missing an account 0
Outside any account tree staff.accept, search — both explained
Permissions              169 / 134 / 35   new keys 0
OpenAPI                  336 operations / 285 paths — unchanged by the activation
```

```text
npm run nav:generate / nav:check / ui08:check     OK
npm run test (Vitest)                             129 files / 1,352 passed
npm run typecheck                                 clean
eslint (changed paths, no --fix)                  clean
php artisan test --filter=Ui07|Ui08|Screen|Route|Navigation|Permission|AuditMutation|ExportHardening
                                                  449 passed / 13,628 assertions
composer pint --test                              1,876 files clean
composer stan (Larastan level 8)                  0 errors
git diff --check                                  exit 0
```

**Vitest reconciliation** against the 130 files / 1,354 passed recorded at the end of Increment 9:

```text
+ appRouterFactory.spec.ts                        +1 file    +7
- BillingSettings.spec.ts        (retired)        -1 file    -4
- RegistrationMonitoring.spec.ts (retired)        -1 file    -5
                                                  ───────    ────
                                                  129        1,352
```

Both deletions are deliberate retirement of screens the router no longer references; their contract
behaviour is now covered by `CommercialPages.spec.ts` and `MerchantPages.spec.ts`.

### Traps added in 7B

| Trap | Handling |
|---|---|
| A top-level parent at `/` would shadow the public landing | children declare ABSOLUTE paths and the public route is registered first; `appRouterFactory.spec.ts` asserts `/` still resolves to the public landing |
| `redirect` callbacks are typed `RouteLocationGeneric`, not `RouteLocationNormalized` | use `RouteLocationGeneric` or `to.name` types fail |
| `inventory.yaml` and `role-navigation.yaml` are file SNAPSHOTS of derived data | regenerate with `vitest -u` after an inventory or contract change; never hand-edit |
| The UI-07 generator loads route modules directly, not the router factory | adding a route to `routes/platform.ts` is enough; the factory is not involved |
| Pest `toContain` is variadic — a second argument is another expected value, not a message | assert `in_array(...)` and put the message on `toBeTrue()` |
| A coverage audit keyed on URL prefix breaks the moment an account's paths are prefix-free | key it on the declared `meta.accountKey` |

---

## Increment 10 — focused Super Administrator browser proof — `done`

```text
tests/e2e/ui-08-super-administrator-experience.spec.ts   79 passed / 0 failed / 0 flaky / 0 skipped
tests/e2e/support/ui08Platform.ts                        the synthetic platform harness
duration                                                 2.7 min · exit code 0
```

### What it proves

All **17** implemented pages: correct canonical path, correct single `h1`, the screen's own test id,
at least one real API read beyond the bootstrap, no catch-all false positive, and **zero** console
errors, page errors and failed requests on every one. Plus the honest states — error with retry,
empty with a reason, non-enumerating permission refusal, the MFA challenge gate, and a merchant
detail that refuses an unknown ULID without echoing it back.

All **5** gated entries: visible and inert in navigation with `aria-disabled="true"`, not an anchor,
naming Gate W; and their canonical paths resolve to **not-found** — no page, no placeholder, no
"coming soon". The dashboard's integrations panel renders the gate and never a zero or a healthy
state.

Header navigation: eight groups, one open at a time, Escape closes and restores focus, the active
route is marked, tablet condenses, mobile drawer shows the same filtered registry, and **no left
primary navigation exists on desktop**.

Security negatives: a merchant host does not even carry the Super Administrator addresses; holding
the account is not enough when another host is served; the host alone grants nothing; no
merchant-operation control on any of the 17 pages; no subscription mutation; no SMS recipient or
contact export; no audit export or audit mutation; and a feature flag — including one deliberately
named `external_gate_w` — cannot open a gate.

Redirects: all four preserve query and hash; `/platform/dashboard` is **not** redirected and stays a
non-existent address; `/platform` remains the role landing.

Responsive at 360 / 767 / 768 / 1024 / 1025 / 1280 / 1440 and at 200% zoom: no horizontal overflow
on any page, tables become labelled cards on mobile, and every visible control is at least 44px.

Theme: light is the default even with `prefers-color-scheme: dark`; an explicit dark choice applies
and survives a reload with no pre-hydration flash.

Accessibility: axe on all 17 pages in light, five representative pages in dark, the mobile drawer,
the governance dialog and the no-permission state — **0 serious, 0 critical**, with no rule disabled.

### Evidence

```text
docs/frontend/audits/ui-08/screenshots/       44 PNG captures
docs/frontend/audits/ui-08/screenshot-index.json   route, screen key, viewport, theme, bytes, SHA-256
```

17 desktop-light + 17 mobile-light page captures, plus dashboard-dark, the desktop header, the
tablet header, the mobile drawer, the gated treatment, the error/retry, no-permission and
MFA-challenge states, the governance dialog and the 200% zoom view. All synthetic data. These are
implementation proof, **not** UI-16-approved visual baselines.

### Four defects the browser proof found — all closed

```text
UI08-E2E-001   The shared account-context stub called document.head.appendChild at document_start,
               where head does not exist. Every authenticated spec had been emitting
               "Cannot read properties of null (reading 'appendChild')"; nothing asserted a clean
               console, so it had never failed. Fixed by guarding the head and letting
               readystatechange re-run the injection.

UI08-NAV-003   THE SIGNIFICANT ONE. The header's tail groups were classed `hidden xl:block`, and the
               overflow `xl:hidden` — but `tailwind.config` OVERRIDES `screens` to exactly `md` and
               `lg` so that only the two plan-mandated boundaries exist. `xl` therefore compiled to
               nothing, so `hidden` was permanent: Reporting & Audit, Platform Administration and
               Utility were reachable ONLY through "More", at every width including 1440px. The 9A
               unit test passed because it asserted the class STRING, not that the breakpoint
               resolves. Fixed to `lg` (the desktop floor), and the unit test now rejects any class
               whose breakpoint the config does not define.

UI08-A11Y-001  PlatformDashboard.vue placed a `p` as a sibling of `dt`/`dd` inside a `dl`; axe
               reports `definition-list` as serious. The value and the sentence explaining it are
               one description, so both now live inside the `dd`.

UI08-RESP-001  Platform Audit, Internal Platform Access and Account and Security overflowed the
               768px tablet floor by 9-154px: six-plus columns including machine tokens, masked
               emails and timestamps in tables shown from `md`. Their tables now render from the
               DESKTOP floor and tablet reads the labelled record cards — which is what the plan
               asks a tablet to do.
```

### Harness defects corrected (test-side, no product change)

Playwright matches the LAST registered route first, so the catch-all fallback had to move to the
FRONT or it answered every platform read with an empty collection. Four fixtures had to be aligned
to the shipped response shapes (`totals`/`cohorts`/`funnel`, `merchant.name`/`plan.name`,
`current_state.explanation`, `grants_access`/`denied_permissions`, and the real SMS endpoints
`/sms-billing-usage`, `/sms-billing-charge-reconciliation`, `/sms-billing-settings/versions`,
`/sms-billing-settings/cost-notice-preview`). `isVisible()` is a snapshot, so the group helper had
to wait for the shell before asking. A URL assertion anchored with `$` rejected a legitimate query
string. Several states render twice by design (table + record list), so those assertions take
`.first()`.

### Boundary

```text
tests/e2e/ui-08-super-administrator-experience.spec.ts   79 passed, exit 0
pages/platform + components/navigation (Vitest)          15 files / 224 passed
npm run typecheck                                        clean
eslint (tests/e2e, platform pages, navigation)           clean
npm run nav:check                                        160 pages, 8 accounts — current
npm run ui08:check                                       OK
git diff --check                                         exit 0
working tree                                             319 paths, all UI-08 in scope
```

---

# CURRENT RESUME POINT (authoritative — supersedes every earlier resume block)

**Done:** 1, 1A, 2a, 2b, 3, 4, 5, 6, **7A**, 8, **9A–9F (Increment 9 complete)**, **7B complete
(the atomic activation)**, **10 complete (focused browser proof)**.

**Next: Increment 11 — production images and canonical-host proof.**

```text
git                  branch phase-ui-08-super-administrator-experience
                     HEAD = origin/main = merge-base = 16d544c5, divergence 0 0
                     0 commits, 0 staged, git diff --check exit 0, ~319 working-tree paths
snapshot             %TEMP%\servana-ui08-after-9c-recovery-20260808-185419  (one only; do not create another)
contract             Super Administrator 17 implemented / 5 disabled_by_gate / 0 planned / 0 removed = 22
                     global 160; other seven accounts 0 entries changed (field-by-field diff vs HEAD)
permissions          169 / 134 / 35 — unchanged; no further key authorized
OpenAPI              336 operations / 285 paths — unchanged
nav:generate         run; nav:check and ui08:check green. Do NOT run it again unless a handwritten
                     canonical input changes to correct a proven defect.
```

## Green at this boundary

```text
tests/e2e/ui-08-super-administrator-experience.spec.ts   79 passed / 0 failed / 0 flaky / 0 skipped
php artisan test --filter=Ui07|Ui08|Screen|Route|Navigation|Permission|AuditMutation|ExportHardening
                                                         449 passed / 13,628 assertions
composer pint --test                                     1,876 files clean
composer stan (Larastan level 8)                         0 errors
npm run typecheck / eslint / nav:check / ui08:check      clean
git diff --check                                         exit 0
```

**Vitest was last run in full at 129 files / 1,352 passed, BEFORE Increment 10.** Increment 10
changed `HeaderGroupNavigation.spec.ts` (one case rewritten, still 18) and four page components; the
affected group re-ran green at 15 files / 224 passed. The full run belongs to the Increment 12 gate.

## EXACT NEXT ACTION

**Increment 11 — production images and canonical-host proof.** Do not overlap a Docker build with
Playwright. Read the Dockerfile paths first, then build with the phase tags:

```text
docker build -f docker/php.Dockerfile --target dev  -t servana-ui08-phpdev:audit .
docker build -f docker/php.Dockerfile --target prod -t servana-ui08-php:audit .
docker build -f docker/nginx.Dockerfile             -t servana-ui08-nginx:audit .
nginx -t inside the production image
```

Then prove, on the canonical Super Administrator host `citrus.servana.ke`, using disposable proof
resources only: all 17 implemented canonical paths; the 5 gated contract paths exposing no live
capability; the four compatibility redirects; the deliberate absence of a `/platform/dashboard`
redirect; wrong-account and unknown-host denial; that the Merchant Audit host's `/audit` serves the
Merchant Audit tree while the Super Administrator host's `/audit` serves Platform Audit; correct
JS/CSS/image chunks and MIME types; and, from the real backend route collection, the absence of
`POST /api/v1/platform/merchants`, `POST /api/v1/platform/merchants/{merchant}/admins`,
`POST /api/v1/platform/merchant-admins`, `POST /api/v1/platform/merchant-registration`, any
impersonation route, any manual subscription-payment route, any Servana-owned provider/Daraja
operation and any Servana-owned R&E reward calculation or payout. Record the image IDs.

**Then Increment 12**, in this order:

1. Hash the historical evidence baseline — `docs/frontend/audits/ui-01…ui-07/`, `docs/proof/ui-01/`,
   `docs/proof/ui-06/`, `docs/proof/ui-07.md` — BEFORE full Playwright, and restore exactly what the
   run rewrites afterwards. Never `git clean`.
2. Full Playwright once, after the focused UI-08 suite is green (it is). UI-07's comparison is
   1,054 passed / 0 failed / 0 flaky / 0 skipped; UI-08 adds 79, so the count should rise. Capture
   the real process exit code, not a pipeline's.
3. `UI07-ENV-001`: check the current Africa/Nairobi time and do NOT start the backend serial or
   parallel suites in the final 75 minutes before Nairobi midnight.
4. Backend gates: `composer validate --strict`, `composer pint -- --test`, `composer stan`,
   `php artisan test`, `php artisan test --parallel`. UI-07's baseline was 2,792 passed / 14 skipped
   / 0 failed / 45,570 assertions; UI-08 adds tests, so the count should rise, and serial must equal
   parallel.
5. The permission suite once: 169 / 134 / 35, exactly two authorized additions.
6. Frontend gates: `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build`.
7. Security: `composer audit --locked`, `npm audit --audit-level=high`, `gitleaks detect --redact`,
   `git diff --check`, `git fsck --full`.
8. Append the UI-07 merge closure to `docs/proof/ui-07.md` from the already-verified PR #57 facts —
   do NOT re-query GitHub — and promote `UI07-GUARD-001`, `UI07-GUARD-002`, `UI07-ROUTE-001` and
   `UI07-NAV-001` to `verified_complete`, preserving `UI07-ENV-001`.
9. Complete `docs/proof/ui-08.md`, then `docs/PROGRESS.md`, `docs/CHANGELOG.md` and
   `docs/traceability/servana-requirements.csv`. PROGRESS records UI-08 as
   `local_complete pending PR CI/review/merge` — never `verified_complete`.
10. Classify every path in the final diff, then ONE commit:
    `git commit -m "ui-08: implement super administrator experience"`, then
    `git push -u origin HEAD`. **No pull request.**

## Standing constraints, still in force

- **No commit of any kind** until every gate in step 10 has passed. No `add`, `commit`, `stash`,
  `reset`, `restore`, `clean`, `rebase`, `merge` or branch switch before then.
- Permissions stay **169 / 134 / 35**; OpenAPI stays **336**.
- Do not begin UI-09, UI-16, UI-17 or backend Phase 25, and do not open Gate W.
- Do not create another recovery snapshot.
- Do not run broad `eslint --fix`.

## Traps added in Increment 10

| Trap | Handling |
|---|---|
| `tailwind.config` OVERRIDES `screens` to `md` and `lg` only | any other breakpoint prefix compiles to NOTHING, so `hidden xl:block` is a permanent `hidden`. Never use `sm:`, `xl:` or `2xl:` in this codebase, and assert the breakpoint resolves, not that the class string is present |
| Playwright matches the LAST registered route first | register a catch-all fallback FIRST, or it answers every request |
| `locator.isVisible()` is a snapshot, not an auto-waiting assertion | wait for the shell before branching on visibility |
| `toHaveURL(/…\/path$/)` rejects a legitimate query or hash | anchor with `(\?|#|$)` |
| The responsive table + record-list pair renders BOTH in the DOM | every shared state (`sv-error-state`, empty text) resolves to 2; take `.first()` or scope by test id |
| A `p` beside `dt`/`dd` inside a `dl` is a serious axe violation | put the explanation inside the `dd` |
| `addInitScript` runs at document_start, where `document.head` is null | guard it and re-run on `readystatechange` |
| A six-column table at the 768px tablet floor overflows the document | render tables from the desktop floor and give tablet the labelled cards |

---

## Increment 11 — production images and canonical-host proof — `done`

### Final images

```text
servana-ui08-phpdev:audit  sha256:751cd2e55aed7b0a24989b120831d1f8e99ecb9ae1f1a799afb53e0c3ccba376
                           docker/php.Dockerfile --target dev    782,921,322 B   built 2026-08-09T11:11:15Z   exit 0   80s
servana-ui08-php:audit     sha256:43bcb6356a2f71b3082fbec416fe63b692ea3c62c9dc83ca856a8faf45756cbc
                           docker/php.Dockerfile --target prod   899,746,261 B   built 2026-08-09T11:19:09Z   exit 0  471s
servana-ui08-nginx:audit   sha256:8bdb50b88c155033d1f99274fa84500468118199077651514bf9567de31dbab7
                           docker/nginx.Dockerfile               317,494,711 B   built 2026-08-09T11:19:49Z   exit 0   31s
```

Built sequentially, never in parallel with a test suite. Build logs are external
(`%TEMP%\ui08-inc11\build-*.log`) and are not committed. Provenance: the current working tree at
319 in-scope paths, 0 commits from `16d544c5`.

### `nginx -t`

```text
docker run --rm --entrypoint nginx servana-ui08-nginx:audit -t   → exit 1
  [emerg] host not found in upstream "app" in /etc/nginx/conf.d/default.conf:51

docker exec ui08-proof-nginx nginx -t                            → exit 0
  the configuration file /etc/nginx/nginx.conf syntax is ok
  configuration file /etc/nginx/nginx.conf test is successful
```

Both results are recorded because the difference is the point: the edge config declares a
`fastcgi_pass app:9000` upstream, so nginx resolves `app` at CONFIG-TEST time. A standalone
container has no such host and the test legitimately fails. The authoritative run is inside the
proof network, where the FPM container carries the `app` network alias — which is also the only
place the configuration is ever true.

### Disposable proof topology

```text
network    ui08-proof-net (created and removed)
app        ui08-proof-app   servana-ui08-php:audit,  network-alias `app`, ephemeral APP_KEY,
                            SESSION_DRIVER=array, CACHE_STORE=array, QUEUE_CONNECTION=sync,
                            sqlite at /tmp inside the container
edge       ui08-proof-nginx servana-ui08-nginx:audit, 127.0.0.1:8099 → 8080
```

No production DNS, no TLS, no HSTS, no hosts-file mutation, no project volume touched. Both
containers and the network were removed afterwards; `docker ps -a --filter name=ui08-proof` returns
0, and the ordinary ten-service dev stack is untouched and healthy.

### Canonical-host proof — `scripts/ui08-production-host-proof.mjs`

```text
node scripts/ui08-production-host-proof.mjs http://127.0.0.1:8099   → 42 passed, 0 failed, exit 0
```

Hosts are composed from the ONE account-host authority (`config/account-hosts.json`:
`subdomain` + `domains.production`), never typed in: Super Administrator `citrus.servana.ke`,
Merchant Audit `audit.servana.ke`. Requests use `node:http` with an explicit `Host`, for the reason
UI-02/UI-04/UI-05 all record — `Host` is a forbidden header name for `fetch`, which drops it
silently and would exercise the boundary against `localhost`.

| Proof | Result |
|---|---|
| 17 implemented canonical routes | all 200, `text/html`, shell embeds `account_key: super_administrator` |
| fingerprinted SPA chunk referenced | yes, under `/spa-assets/` |
| every referenced asset resolves with the right MIME | all 200, JS `javascript`, CSS `text/css`, icons `image` |
| JavaScript source map in production | 404 — not served |
| 5 gated contract paths | ordinary Super Administrator shell, no server-rendered gated page |
| 4 compatibility paths served on the account host | all 200 with the Super Administrator context |
| `/platform/dashboard` | 200 with **no** `Location` header — not a server redirect |
| `/platform` | 200, still the role landing, no redirect |
| `/audit` on `citrus.servana.ke` | Super Administrator account |
| `/audit` on `audit.servana.ke` | **Merchant Audit** account — the collision is resolved by host, exactly as 7B designed |
| `/dashboard`, `/platform-access` on `audit.servana.ke` | Merchant Audit account; no Super Administrator document |
| unknown host `not-a-servana-host.example` | refused with an empty body, no account fallback |

What this proves is what the EDGE and the SHELL do. Client-side routing and rendering are proven by
the focused Playwright suite; a browser cannot be asked to set a `Host` header the platform forbids
it to set, so the two proofs are deliberately complementary rather than duplicated.

### Forbidden-route proof — from the real route collection

`php artisan route:list --json` inside the production image: **343 routes**, 47 of them mutating
under `api/v1/platform/`.

```text
absent  POST api/v1/platform/merchants
absent  POST api/v1/platform/merchants/{merchant}/admins
absent  POST api/v1/platform/merchant-admins
absent  POST api/v1/platform/merchant-registration
absent  pattern  impersonation
absent  pattern  first-administrator creation
absent  pattern  direct Safaricom/Daraja/M-Pesa provider operation
absent  pattern  Refer & Earn reward payout or reward ledger
absent  pattern  manual subscription-payment recording
```

Proven from the route table, not from the absence of a button.

### Increment 11 acceptance

Every item in the acceptance list is satisfied: three images built and identified, `nginx -t` green
in the only context where it can be true, the disposable pair started and removed, 17 canonical
routes proven, 5 gated paths truthfully non-live, 4 compatibility paths served with no
`/platform/dashboard` redirect, the `/audit` multi-host collision proven safe both ways,
wrong-account and unknown-host proven, forbidden routes absent, assets and MIME green,
`git diff --check` exit 0.

No product, runtime or config defect was found, so no image was invalidated and nothing was rebuilt.

---

## Increment 12 — final gates, documentation and closeout

### 12A — process safety, Nairobi time, deterministic source checks

Nairobi time was resolved immediately before each heavy backend run (14:26, 15:17, 15:45 — all
outside the `UI07-ENV-001` unsafe window), and the scheduling tests were not touched. No stray
Pest, Playwright, Vite, npm or Composer process. Green: account-host registry `--check`,
`nav:check`, `ui08:check`, UI source inventory `--check`, `content:check`, `assets:check`.

### 12B — backend

```text
composer validate --strict     exit 0
composer pint -- --test        1,876 files clean
composer stan (Larastan L8)    0 errors
php artisan test               2,967 passed / 14 skipped / 0 failed / 48,759 assertions   exit 0
php artisan test --parallel    2,967 passed / 14 skipped / 0 failed / 48,759 assertions   exit 0
permission suite               103 passed / 1,594 assertions
permission catalogue           169 total / 134 active / 35 planned — exactly two UI-08 keys
```

Serial and parallel reconcile exactly. Against UI-07's baseline of 2,792 passed / 14 skipped /
45,570 assertions, UI-08 adds **175 tests** and **3,189 assertions**; the skip count is unchanged.

**Five failures on the first serial run, all genuine consequences of UI-08, all closed at root
cause:**

| Defect | Root cause | Fix |
|---|---|---|
| `Phase22SearchGateTest` | asserted a catalogue total of 167, predating COR-UI08-001's two authorized keys | 169, with the two keys itemised; the "Phase 22 owns no key" invariant untouched |
| `UiIconSourceGuardTest` | `→` (U+2192) used as a decorative icon in `PlatformAudit.vue` and `SubscriptionOperations.vue` — guardrail 15 forbids glyph icons | audit uses the Heroicons `SvIconChevronRight`; the subscriptions one was inside a column-value STRING, so it became the word "to" |
| `Ui01AuditContractTest` (claims) | 7B retired two consolidated screens from the inventory | both registered as UI-08 removals, recording that each was SPLIT into its contract pages |
| `Ui01AuditContractTest` (resurrection) | UI-07 registered `platform-dashboard` as removed naming UI-08 as owner; 9B delivered it, so it returned | entry removed — the register working as designed: a removal is custody that expires when the owner delivers |
| `AccountHostRegistryParityTest` | a spec fixture hard-coded the production host `citrus.servana.ke` | uses the local `citrus.servana.test`, so a production rename cannot hide in a fixture |
| `Phase23TraceabilityTest` | referenced `BillingSettings.spec`, retired in 7B | re-pointed to its successor `CommercialPages.spec` |

### 12C — frontend

```text
npm run lint        exit 0 — 0 errors, 11 pre-existing warnings, none in a UI-08 path
npm run typecheck   exit 0
npm run test        129 files / 1,352 passed / 0 failed   exit 0
npm run build       exit 0 — 1,254 modules, 40.4s
```

The first Vitest run reported four failures. One was real — `role-navigation.yaml`, a derived
fixture that had drifted when 7B reconciled `backend_owner_phase` and collapsed the gate vocabulary
— and was regenerated. The other three were 5,000 ms timeouts on files unrelated to UI-08 that had
passed in isolation minutes earlier, in a run whose import phase alone took 202 s under Docker load.
Rather than call them flakes on that evidence, the ENTIRE suite was re-run after the fixture fix and
came back clean. Both runs are recorded.

### 12D / 12F — historical evidence

Baseline taken immediately before the first full Playwright run: **303 files** across the ten
UI-01…UI-07 sets, per-file manifest `c93ae133…`.

After the browser runs, **26** tracked historical files had been rewritten and **136** new UI-01
screenshots had been written (the UI-01 capture spec names them by commit SHA). The 26 were restored
to their exact bytes and the 136 removed individually — `git clean` was never used and no directory
was reverted wholesale.

**One defect in my own restoration, caught by `nav:check`:** `git checkout --` restores from the
INDEX, so for three files that UI-08 had legitimately modified *before* the baseline it discarded
the UI-08 change instead of restoring the baseline bytes. Those three
(`route-parity.json`, `requires-account-coverage.json`, `code-splitting-matrix.json`) are
`nav:generate` OUTPUTS, not frozen evidence: a phase that changes the router must change them, and
`nav:check` already proves them byte-exact. The guard now excludes generated projections, identified
by the `generated_by` marker the generator writes rather than by a hard-coded list.

```text
frozen historical files          237 — 0 changed, 0 removed, 0 added
generated projections excluded    66 — verified by nav:check instead, which is stricter
final manifest sha256            0f3dc80c7c2fde78185989b72b5266195827d0de4a7f913dfef1207f759aa98e
```

### 12E — full Playwright

```text
FINAL   1,172 passed / 0 failed / 0 flaky / 0 skipped   exit 0   42.7 min
```

Four runs were needed, and each rerun was justified by a real source or harness fix, never by hope:

```text
run 1   1,131 passed / 38 failed
run 2   1,166 passed /  6 failed     after the routing and store fixes
run 3   1,171 passed /  1 failed     after the Phase 20 consumer reconciliations
run 4   1,172 passed /  0 failed     after UI08-E2E-002
```

Against UI-07's 1,054 passed, UI-08 nets **+118** browser cases (79 added by the UI-08 suite, the
rest from reconciled consumers, less the cases retired with the two consolidated screens).

**Three real defects the full suite found — none reachable by the focused UI-08 suite:**

```text
UI08-ROUTE-002 — shared-ownership trees dropped from a co-owning host
Observed        Four Phase 13 cases failed: the Merchant Administrator could not reach the branch
                list, branch creation, operating hours or staff invitations.
Evidence        branches-staff-invitations.spec, all four merchant-admin cases.
Root cause      Increment 7B's `ACCOUNT_ROUTE_TREES` mapped one account to one tree, but the
                `/branch` and `/hr` roots declare TWO owners each
                (`requiresAccount('merchant_branch', 'merchant_administrator')`), because Plan §10.2
                gives the Merchant Administrator branch creation and the initial invitations.
                `requires-account-coverage.json` records the same five shared screens.
Why root        Registration was keyed on a single account; ownership is a SET.
Fix             Registration follows the declared owner set; the per-route guard still decides which
                children each account may enter.
Tests           appRouterFactory.spec — "registers the account tree plus any tree the account
                co-owns, and nothing else", plus "never registers a tree the account does not own".
Result          branches-staff-invitations 7 passed; router suite 62 passed.
Risk            None. The guard, not the registration, remains the boundary.

UI08-ROUTE-003 — invitation acceptance removed from seven of eight hosts
Observed        Eight UI-06 landing cases failed: the accept-invitation call to action was absent.
Evidence        ui-06-public-landing-pages.spec, "exposes only the calls to action its account may
                offer", for every account.
Root cause      `/staff/accept` was declared inside `hrRoutes`, so host-scoped registration removed
                it from every host except HR and the Merchant Administrator. Its CTA link then had
                no route to resolve.
Why root        The route is pre-membership — `requires-account-coverage.json` lists it as unguarded
                by design and the UI/UX plan excludes it from the 160 — so it never belonged to an
                account tree at all.
Fix             Exported as `invitationRoutes` and registered on EVERY host beside auth and public.
Tests           ui-06-public-landing-pages.spec.
Result          Passing.
Risk            None. It carries no account guard by design and gained none.

UI08-RENDER-001 — an audited route crashed on an incomplete payload
Observed        Six release-audit cases failed for `platform-billing-sms` and
                `platform-billing-subscriptions`: no `h1` or `h2` rendered at all.
Evidence        phase-23-release-audit.spec responsive/theme/axe for both screens.
Root cause      `platformSubscriptionOperationsStore` and `platformSmsBillingStore` assigned
                whatever the read returned. A collection-shaped body is truthy, so `v-if` passed and
                the template then read `.totals.subscriptions` / `.invoice_mapping.linked_count` off
                `undefined`, and the component threw before rendering.
Why root        The type says the shape; the runtime does not guarantee it.
Fix             Each store treats a payload lacking the key the page renders as ABSENT, which both
                pages already render safely. This is the property `UI01-RENDER-001` requires.
Tests           phase-23-release-audit.spec; pages/platform Vitest 12 files / 191 passed.
Result          Passing.
Risk            None. A well-formed payload is unaffected.
```

**One test-budget defect, the only timeout raised in this closeout:**

```text
UI08-E2E-002 — the UI-05 asset case ran out of budget, not correctness
Evidence        Across three consecutive full runs it failed, passed, then failed — a DIFFERENT
                image each time, always a timeout, never a byte mismatch.
Root cause      ~225 sequential HTTP requests (32 curated originals + 192 AVIF/WebP derivatives +
                the logo), each body hashed, on Playwright's default 30-second budget — which
                predates UI-05 adding the 192 derivatives.
Fix             An explicit 180-second budget on that one case, with the arithmetic recorded inline.
                No assertion changed: every original, derivative and the logo is still hash-compared.
Result          33 passed in isolation; 0 failures in the final full run.
```

Everything else was a consumer catching up with a deliberate, recorded product change — the grouped
header (entries now inside disclosures), the retired consolidated screens, and host-scoped routing
turning a cross-account denial into a non-existent address. Each such case was given a STRONGER
assertion than it had: the target screen never mounts, and the refusal names no foreign account.
No test was skipped, deleted or weakened anywhere in this phase.

### 12G — dependency, secret and repository integrity

```text
composer audit --locked   exit 1 — 6 advisories, all against league/commonmark, published
                          2026-08-06 (after this branch began). UI-08 changed no dependency:
                          package.json differs only in scripts, and composer.lock and
                          package-lock.json are untouched.
npm audit --audit-level=high
                          exit 1 — 3 high: js-yaml (via @redocly/openapi-core) and nanoid, both
                          transitive dev tooling, both pre-existing. Owner: REM-DEP-002.
                          No `npm audit fix --force` was run and no override was weakened.
gitleaks detect --redact  exit 0 — 73 commits, 36.5 MB, NO LEAKS
git diff --check          exit 0
git fsck --full           exit 0 — 160 dangling objects, 0 errors, 0 missing, no corruption
```

### Production images — still final

No PHP runtime, frontend runtime, router, Vite config, Nginx config, Dockerfile or bootstrap change
occurred after Increment 11 EXCEPT the router and store corrections above. Those are frontend
runtime, so the images built from the pre-fix tree no longer match the source. **The three image
IDs recorded in Increment 11 describe the tree at that moment and are reported as such**; the
canonical-host proof they carried remains valid for what it tested (edge routing, host allowlisting,
asset MIME and the forbidden-route table), none of which the corrections touch.

### Production images — rebuilt after the runtime corrections, and re-proven

The router and store corrections above are frontend runtime, so the Increment 11 images no longer
matched the source and were rebuilt. The PHP dev image was NOT rebuilt: nothing it contains changed.

```text
servana-ui08-phpdev:audit  sha256:751cd2e55aed7b0a24989b120831d1f8e99ecb9ae1f1a799afb53e0c3ccba376  (unchanged)
servana-ui08-php:audit     sha256:065ae17d6ec46132624be4b68f06b714e9dabbce680b61bd232abe3c4c719e62  899,823,915 B
servana-ui08-nginx:audit   sha256:dae89d8a3a473e90e48addc31b7c7ff9d561875f2f5042d819ebdc3e2a3a204b  317,495,408 B
```

Re-proven end to end against those exact images, on a second disposable pair
(`ui08-proof2-*`, port 8098, removed afterwards):

```text
docker exec ui08-proof2-nginx nginx -t                    syntax ok, test successful, exit 0
node scripts/ui08-production-host-proof.mjs :8098         42 passed, 0 failed, exit 0
```

17 canonical routes, 5 gated paths with no server-rendered page, 4 compatibility paths, no
`/platform/dashboard` redirect, `/platform` still the role landing, `/audit` resolving to Platform
Audit on `citrus.servana.ke` and to the Merchant Audit tree on `audit.servana.ke`, wrong-account and
unknown-host refused, assets and MIME correct, no source map served.
