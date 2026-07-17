# Post-Phase-20F Deferred Hardening — Proof

**Branch:** `hardening/resource-contracts-and-accessibility-tokens`
**Base:** `f4bc664b7ba77476f9db01dcb0ec1a526dc20538` (= the Phase 20F PR #39 squash merge on `main`)
**Lifecycle:** `local_complete` pending PR CI/review/merge
**Type:** post-phase hardening / remediation branch — **not** a product-feature phase.

This branch discharges the two follow-ups Phase 20F discovered, deliberately scoped out, and
recorded as deferred:

1. Repo-wide nullable Resource/OpenAPI truth sweep.
2. Repo-wide dark-mode heading / warning-badge contrast sweep.

Phase 20F fixed only its own surfaces (7 resource fields, its own headings/badges, the shared
`SvModal` title) and explicitly left `PlatformFeeConfigurationResource`, `StaffList.vue` warning
badges, unrelated `text-brand-deep` headings and the repo-wide drift alone. That was correct for
Phase 20F. This branch fixes the remainder.

---

## 1. Prerequisite gate — Phase 20F merge state

The §1 gate required that Phase 20F be merged into `main` before this work starts.

| Check | Result |
|---|---|
| `gh pr list --head phase-20f-compensation-plan-commission-rules --state all` | **PR #39 MERGED**, `mergedAt` `2026-07-17T12:11:44Z`, `mergeCommit` `f4bc664b7ba77476f9db01dcb0ec1a526dc20538` |
| `git rev-parse origin/main` | `f4bc664b7ba77476f9db01dcb0ec1a526dc20538` — identical to the merge commit |
| `git branch --contains a42e13e…` / `git branch -r --contains a42e13e…` | empty — **expected**: #39 landed as a **squash** merge, so the original Phase 20F completion commit `a42e13e6…` is not an ancestor of `main`. The squash commit `f4bc664` carries the work. |
| starting branch | `main`, clean, 0 staged, `git diff --check` clean, `git fsck --full` clean |
| `git rev-list --left-right --count origin/main...HEAD` at start | `0	0` |

**Gate PASSED.** Phase 20F is genuinely merged; this branch was cut from updated `main` and is not
stacked on the Phase 20F branch. The pushed Phase 20F branch was not opened, amended, extended or
merged by this work.

**Doc reconciliation.** At branch start `docs/PROGRESS.md` / `docs/CHANGELOG.md` still recorded
Phase 20F as `local_complete pending PR CI/review/merge`. The repository's established convention is
that the *next* branch reconciles the previous phase during its first increment (PROGRESS records
Phase 20E as "Reconciled from `local_complete` during Phase 20F Increment 1", and Phase 20C during
Phase 20E's). This branch is that next branch, and the §6 condition — "do not rewrite Phase 20F as
`verified_complete` unless the Phase 20F PR has actually merged" — is satisfied by PR #39. Phase 20F
is therefore reconciled to `verified_complete` here. No historical Phase 20F evidence was altered
beyond that reconciliation and a pointer noting the deferred items are handled by this branch.

---

## 2. Work item A — nullable Resource/OpenAPI truth sweep

### 2.1 The generator's actual inference rules (measured, not assumed)

Ground truth taken from the merged Phase 20F resource + its published schema:

| Resource expression | Published by the generator | Correct? |
|---|---|---|
| plain model attribute (`$this->notes`, `$this->change_reason`) | `string \| null` / `string` per the model's `@property` docblock | ✅ inferred correctly |
| explicit ternary `$x === null ? null : $x->m()` | `string \| null` | ✅ inferred correctly |
| nullsafe `$x?->m()` | `string`, `required` | ❌ **nullability lost** |
| `whenLoaded('r', fn (): ?string => $this->r?->ulid)` | `string`, optional — **not** nullable | ❌ the `?string` return hint does **not** create nullability |

The generator emits OpenAPI 3.1 `type: [string, null]` (not `nullable: true`), and it reads
attribute nullability from the model `@property` docblocks. The last row is a finding beyond 20F's
note: the closure return type hint is *not* enough — the ternary must be **inside** the closure.

### 2.2 Audit (re-run, not inherited)

The old figures (127 `?->` / 56 files; 92 expressions / 29 Resources) were re-measured rather than
trusted.

| Measure | This branch |
|---|---|
| Resource files | 56 |
| `?->` occurrences (incl. comments) | 127 |
| `?->` non-comment lines | 124 |
| Resource files containing `?->` | 38 |
| field-assignment `?->` published non-null | 110 across 35 Resources |

The count differs from the recorded "92 across 29" because that figure excluded the `whenLoaded`
optional fields and predates Phase 20F's own 7 fixes. Every candidate was classified against the
**model's `@property` docblock** (for attributes) or the **FK column's nullability** (for
relations) — null-reachability, not a blanket rewrite.

### 2.3 Confirmed defects fixed — 104 fields across 34 Resource files

Arithmetic check: 124 non-comment `?->` lines − 20 deliberately kept (§2.4) = **104 fixed**. A
post-fix re-run of the audit reports **0** remaining genuine defects, and the 20 survivors are
exactly the documented keep-list.

| Class | Count | Basis |
|---|---|---|
| Direct attribute, model declares `\|null` | 73 | `@property Carbon\|null $x` etc. vs `string`/required |
| `created_at` / `updated_at` on models whose docblock omits them | 7 | Eloquent types these `?Carbon`; `$table->timestamps()` columns are nullable; Phase 20F's merged precedent publishes `created_at` as `string\|null` |
| Relation via **nullable FK** | 12 | `AuditLog.branch`, `PlatformFeeDispute` (ledgerEntry, subscriptionInvoice, assignedReviewer, resolvedBy), `PlatformFeeLedgerEntry` (branch, sourceInvoiceItem, subscriptionInvoiceItem), `Receipt.reissueOf`, `PaymentReferenceCheck.matchedRecord`, `CommissionRule.serviceCategory`, `CompensationPlan.supersedesPlan` |
| Closure-returned / nested | 12 | `Client.sms_consent` (no SMS consent row), `InvoiceItem.service_session_id`, `ServiceSession.queue_entry_id`, `AuditFlaggedEvent.audit_event.occurred_at`, `AuthenticatedUser.user.email_verified_at`, `setup.completed_at`, `FreePeriodOffer.targets[]` ×3, `PromotionalDiscount.targets[]` ×3 |

`FreePeriodOffer.targets[]` / `PromotionalDiscount.targets[]` are the clearest lie: a target names
**exactly one** of merchant / plan / billing_mode, so two of the three are always null, yet all
three were published `string` + required.

`PlatformFeeConfigurationResource` — the drift Phase 20E introduced and 20F recorded out of scope —
is fixed here (`tier_behavior`, `fee_basis_type`, `effective_to`, `approved_at`).

### 2.4 Deliberately NOT changed — 20 occurrences

Null is **unreachable**, so the existing non-null contract is truthful and the `?->` is defensive:

| Resource | Fields | Why kept |
|---|---|---|
| `PlatformFeeDispute` | `created_by` | `created_by` is a non-null FK |
| `PlatformFeeLedgerEntry` | `merchant_id`, `source_invoice_id` | non-null FKs |
| `CompensationPlan` | `staff_profile_id`, `staff_display_name`, `branch_id` | non-null FKs (20F's judgement upheld) |
| `CompensationPlanHistory` | `actor_display_name` | `actor_user_id` is non-null |
| `ServicePersonnelEligibility` | `service_id`, `service_name`, `staff_profile_id`, `staff_name` | non-null FKs |
| `Service` | `category_id`, `category_name`, `branch_id` | non-null FKs |
| `StaffInvitation` | `branch_id` | non-null FK |
| `StaffProfile` | `role`, `status`, `primary_branch_id` | `merchant_user_id` / `primary_branch_id` are non-null FKs |
| `QueueEntry` | subject `id` ×2 | `(string) $this->walkIn?->ulid` casts to `''` — **never** emits null |

No nullability was invented where the database and resource cannot emit null.

### 2.5 Runtime JSON behaviour

**Unchanged.** `$x?->m()` and `$x === null ? null : $x->m()` are semantically identical in PHP; the
emitted JSON is byte-identical. Only the *published contract* changed — from a lie to the truth. No
status name, money value, date, permission, tenancy, authorization or route behaviour was touched.
The full backend suite (§4) is the regression proof.

### 2.6 Generated contract

| Artifact | Result |
|---|---|
| OpenAPI | **207 paths / 248 operations** — unchanged from Phase 20F |
| `api:contract:check` | OK — 207 paths, 248 operations |
| `servana:permission-types --check` | `permissions.ts` is up to date (permissions untouched) |
| `api.ts` diff | 134 insertions / 110 deletions |
| Determinism | 3 consecutive generation passes → byte-identical SHA-256 for both artifacts |

`openapi.json` SHA-256 `9C44E0A762C3A2BB06498D4FA6195F716E1CD2A372E5246BA991D4DD8ADC04F0`
`api.ts` SHA-256 `69EEA3E6706944DA152F82ADBD8BF8AE27618AEC3FBC9A7B1EF6B6EA3DACEA21`
(identical across passes 1, 2 and 3.)

**Determinism finding (recorded honestly).** A first determinism attempt showed pass 2 diverging.
Root cause was **methodology, not the generator**: that pass ran while the full backend suite was
concurrently migrating/truncating the test database, and the generator introspects DB schema for
model attribute types, so the mid-flight teardown degraded that single run. Re-run against a
quiescent database, three consecutive passes are byte-identical and match the original baseline
hash. No generated file was hand-edited — all three artifacts come from their generators only.

### 2.7 Frontend null-handling (2 files, 3 sites)

The truthful types exposed exactly **3** `vue-tsc` errors — each a genuine latent bug, all fixed
with real null handling (no `!`, no `as`, no null→`''` coercion in any API Resource):

| File | Defect | Fix |
|---|---|---|
| `resources/spa/src/content/platformFee.ts:93` | `tierLabel(config.tier_behavior)` pushed `null` into the terms label → the row would render a literal **"null"** | guard with `!== null`, matching the two adjacent lines' existing idiom |
| `resources/spa/src/pages/platform/billing/PlatformFeeConfigSection.vue:112,114` | `prefill()` assigned a nullable `tier_behavior` / `fee_basis_type` into a `string` form field | prefill `?? ''` (the file's own `effective_to` idiom) so a legacy null cannot **silently propose a different fee behaviour** on edit; the admin must re-select and the Form Request rejects a blank |

---

## 3. Work item B — dark-mode heading / warning-badge contrast sweep

### 3.1 Token facts (verified in `style.css`)

| Token | Light | Dark | Adaptive? |
|---|---|---|---|
| `--color-heading` | `#4a2208` | `#f3f4f6` (`.dark`) | ✅ yes |
| `--color-brand-deep` | `#4a2208` | *not overridden* | ❌ no |
| `--color-warning` | `#f59e0b` | *not overridden* | ❌ no |
| `--color-error` | `#dc2626` | `#f87171` | ✅ yes |
| `--color-success` | `#2e7d32` | `#4ade80` | ✅ yes |

Dark mode is class-based (`.dark` on `documentElement`), and `themeStore` falls back to
`matchMedia('(prefers-color-scheme: dark)')` when no theme is stored — so Playwright's
`emulateMedia({ colorScheme })` correctly drives the real dark theme.

### 3.2 `text-brand-deep` — 128 occurrences reviewed, 105 changed, 23 kept

**Changed → `text-heading`** (105 across 47 files): page/section headings, dialog titles, anchors,
active-tab text and selected-row text sitting on **adaptive** surfaces (`bg-surface`, `bg-bg`,
`bg-card`, `bg-surface-alt`, modal/page backgrounds), where brand-deep on a dark surface renders at
roughly 1.07–1.28:1.

**Kept — no blanket replace** (23):

| Reason | Count | Examples |
|---|---|---|
| Same element paints a **non-adaptive** orange/cream background — brand-deep is the correct CTA/badge foreground (ADR-009) | 16 | `SvButton` `bg-primary text-brand-deep`; `SvEmptyState`/`SvStateBoundary`/`RoleGetStarted`/`RoleLandingScaffold`/`LegalAcknowledgement` CTAs; `SvFileUpload` `bg-savannah-orange`; `Compensation.vue` `draft` badge `bg-cream`; `PlanPricesSection` / `PreferredFeeRulesSection` / `SubscriptionPlansSection` / `PlanManagement` cream+primary badges; `FirstTimeSetup` step pill |
| Comment text (documentation, not a class) | 3 | `SvModal.vue:74`, `Compensation.vue:149`, `Compensation.vue:572` |
| Already carries its own `dark:text-text` override — **proven safe, not failing** | 4 | `PersonnelAvailability.vue:181,273,304,409` |

Four occurrences that a background heuristic flagged as "probably on cream" were **read
individually** and all four proved to be failures: in `SvEmptyState`, `CheckEmail`,
`PlanManagement:215` and `FirstTimeSetup:139` the `bg-cream` belongs to a **sibling icon div or
badge**, not to the heading's own background. Heuristics alone would have wrongly kept them.

### 3.3 Warning badges — the whole repo-wide surface is 2 occurrences

`text-warning` appears **twice, in one file**: `StaffList.vue` `invited` and `suspended`
(`bg-warning/15 text-warning`). Both are fixed to `bg-warning/15 text-text` — the accessible pair
already proven by Phase 20F in `Compensation.vue`. Every other warning surface in the repo already
uses `text-text` on the warning tint. `success` / `error` badges are untouched: their tokens **are**
dark-overridden, so changing them by analogy would have been unproven.

Status is not conveyed by colour alone — the badge label text still carries it (asserted).

### 3.4 Accessibility coverage — the gap that hid the defect is closed

`StaffList` had **no e2e coverage whatsoever**, which is exactly how its warning badges stayed
invisible to every axe run. New spec `tests/e2e/hardening-accessibility.spec.ts` renders **all four**
membership statuses (`invited`, `active`, `suspended`, `deactivated`) in a single list and scans in
**light and dark**.

**Negative control (the test is proven to fail on the defect).** With the badge classes reverted to
`text-warning`, the light-mode scan **fails** with a WCAG 2 AA 1.4.3 `color-contrast` violation
(~2.14:1 on the tinted background). With the fix, it passes. The test is not vacuous.

| Result | |
|---|---|
| axe serious | 0 |
| axe critical | 0 |
| light | pass |
| dark | pass |
| badge variants rendered | 4 / 4 |

No axe rule was suppressed or narrowed; no assertion was removed.

**Coverage honesty.** Fixed **and** newly covered: HR staff roster badges + headings. Fixed and
covered by pre-existing suites: role landings/get-started (`role-foundation-accessibility.spec.ts`,
light+dark), and the Phase 20A/20B/20C/20E/20F screens whose specs already scan light+dark.
**Still not individually axe-covered:** several changed screens have no dedicated axe spec of their
own (e.g. `DashboardStub` pages, `DesignSystemDemo`, some branch/front-office screens). Their change
is a token swap from a **non-adaptive** to the **adaptive** heading token, which strictly improves
dark-mode contrast and cannot reduce light-mode contrast (`--color-heading` is `#4a2208` in light —
identical to `--color-brand-deep`). This is stated rather than claimed as repo-wide success; a
whole-product audit remains Phase 23.

---

## 4. Gates

| Gate | Command | Result |
|---|---|---|
| composer validate | `composer validate --strict` | `./composer.json is valid` |
| Pint | `vendor/bin/pint --test` | **PASS — 1334 files** |
| Larastan L8 | `composer stan` | **PASS — no errors** |
| `OpenApiContractTest` | `php artisan test --filter=OpenApiContractTest` | **9 passed** (15 assertions) |
| `RouteSecurityContractTest` | `php artisan test --filter=RouteSecurityContractTest` | **10 passed** (19 assertions) — filter selected real tests |
| `NoDirectProviderIntegrationTest` | `php artisan test --filter=NoDirectProviderIntegrationTest` | **6 passed** (8 assertions) — filter selected real tests |
| **full backend serial** | `php artisan test` | **1469 passed / 7 skipped / 0 failed / 8644 assertions** — exit 0, identical to the Phase 20F baseline |
| **full backend parallel** | `php artisan test --parallel` | **1469 passed / 7 skipped / 0 failed / 8644 assertions** — exit 0, 4 processes, identical to serial |
| permission types | `servana:permission-types --check` | `permissions.ts` is up to date |
| contract check | `npm run api:contract:check` | **OK — 207 paths, 248 operations** |
| ESLint | `npm run lint` | **0 errors**, 138 warnings — **identical to the `origin/main` baseline (138)**, measured by stashing this branch's changes and re-running; **no new warnings** |
| vue-tsc | `npm run typecheck` | **clean** |
| Vitest | `npm run test` | **404 passed / 84 files** |
| production build | `npm run build` | PASS |
| Playwright (new spec) | `npx playwright test tests/e2e/hardening-accessibility.spec.ts` | **4 passed** |
| Playwright (full) | `npx playwright test` | **368 passed / 0 failed** (7.5m, exit 0) — the Phase 20F baseline of 364 **plus exactly the 4 new hardening tests**. First attempt hit a `config.webServer` 120s timeout (environment/load flake, §4.1 F3: concurrent Docker builds; no test executed or failed); this is the isolated re-run. |
| composer audit | `composer audit --locked` | **No security vulnerability advisories found** |
| npm audit | `npm audit --audit-level=high` | 2 moderate — **exit 0**, below the high gate (matches the 20F baseline) |
| gitleaks | `gitleaks detect --no-git --redact` | **no leaks found** (~17.19 MB scanned) |
| Docker dev app / prod app / prod nginx | `docker compose build app`, `-f docker-compose.prod.yml build app`, `… build nginx` | all three built — `servana-app:dev`, `servana-app:prod`, `servana-nginx:prod`, exit 0 each |

---

### 4.1 Failure log (protocol §8)

| ID | Command | First failing signal | Classification | Root cause | Fix | Rerun |
|---|---|---|---|---|---|---|
| F1 | 2nd determinism pass (`servana:openapi` + `api:types`) | pass-2 SHA-256 diverged from baseline/pass-1 | environment/load flake — **not** a generator defect | the pass ran while the full backend suite was concurrently migrating/truncating the shared test database; the generator introspects DB schema for attribute types | re-ran the 3-pass sequence against a quiescent DB; **no code change** | 3 byte-identical passes (§2.6) |
| F2 | `npm run typecheck` after truthful `api.ts` | 3 × TS2345/TS2322 `string \| null` not assignable | frontend null-handling defect (latent bug exposed by contract truth) | `platformFee.ts` passed a nullable tier into `tierLabel`; `PlatformFeeConfigSection.prefill()` assigned nullable tier/basis into `string` form fields | real null handling (§2.7); no `!`, no casts | vue-tsc clean; ESLint 0 errors |
| F3 | `npx playwright test` (full, 1st attempt) | `Timed out waiting 120000ms from config.webServer` | environment/load flake | three concurrent Docker image builds saturated the CPU during the webServer's Vite build; **no test executed or failed** | re-ran the full suite with nothing else running | §4.2 |
| F4 | `npx playwright test hardening-accessibility --grep axe` with badge classes deliberately reverted | WCAG 1.4.3 `color-contrast` serious violation (light) | **deliberate negative control**, not a regression | proves the new spec genuinely fails on the pre-fix `text-warning` classes | fix restored | 4/4 pass |

Nothing was deleted, weakened, suppressed or narrowed to obtain these results.

### 4.2 Full Playwright (re-run, isolated)

`npm run build` ✓ (12.89s — confirming F3 was load contention, not a build defect), then
`npx playwright test`: **368 passed / 0 failed in 7.5m, exit 0**. The Phase 20F full-suite baseline
was 364; the +4 delta is exactly the new `hardening-accessibility.spec.ts` (2 badge-rendering tests
+ light/dark axe). The shared-surface heading changes (47 files) are regression-proven by the full
suite, including the pre-existing light+dark axe suites for role landings/get-started and the
20A–20F screens.

## 5. Scope purity

Changed path categories, all allowed:

- nullable Resource contract-truth corrections — `app/Http/Resources/**`
- generated OpenAPI from those corrections — `docs/api/openapi.json`
- generated TypeScript from those corrections — `resources/spa/src/types/generated/api.ts`
- frontend null-handling required by the truthful types — `platformFee.ts`, `PlatformFeeConfigSection.vue`
- dark-mode heading token corrections — `resources/spa/src/pages/**`, `resources/spa/src/components/**`
- warning-badge contrast correction — `StaffList.vue`
- axe coverage for the changed state — `tests/e2e/hardening-accessibility.spec.ts`
- proof/documentation — `docs/proof/post-20f-deferred-hardening.md`, `docs/PROGRESS.md`, `docs/CHANGELOG.md`

**Forbidden categories confirmed absent:** no migrations, no new permissions, no new API routes, no
new compensation preview endpoint, no `selected_services` substrate, no boundary-transition
scheduler, no Phase 20F feature change, no Phase 20G salary/commission ledger, no Phase 20H
payouts/earnings, no 20D-W Wallet/provider runtime, no unrelated refactor, no
`test-results`/`playwright-report`/coverage/node_modules/vendor/.env/secrets.

## 6. Residual risk

- Several changed screens have no dedicated axe spec (§3.4); the change is a strictly-improving
  token swap, but they are not individually proven.
- `created_at`/`updated_at` are now published nullable wherever the model/Eloquent types them
  `?Carbon`. This follows the merged Phase 20F precedent and the nullable `timestamps()` columns,
  but it does mean consumers must null-check a field that is never null for a persisted row.
- The 23 kept `text-brand-deep` occurrences are judged by their **own element's** background; a
  future refactor that moves one of them onto an adaptive surface would silently reintroduce the
  defect. No test pins that.
- Residuals unchanged and out of scope: Gate W closed / 20D-W blocked; multi-branch HR fail-closed
  behaviour; `selected_services` has no membership substrate; no compensation boundary-transition
  scheduler; pre-approval impact preview stays descriptive; E2E uses repository-standard stubbed
  APIs for frontend behaviour.
