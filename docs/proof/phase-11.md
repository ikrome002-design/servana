# Phase 11 — UI Layout Foundation & Role Navigation Proof (REM-SCR-001 substrate)

**Branch:** `phase-11-ui-layout-role-navigation` · **Base:** `9b493e6` (merged Phase 10F, PR #22).
**PR:** #23 (base `main`). **Implementation commit:** `0482e10`. **CI remediation commit:** `bb04d87`.
**Status:** `ci_passed` / `ready_to_merge` — PR #23 open; five required checks green on CI run
`28314016145` (head `bb04d87`); reviewDecision blank (one eligible maintainer; no independent
review claimed). REM-SCR-001 stays `local_complete` (Phase 11 substrate) and is **not**
`verified_complete` until PR #23 merges. The merge commit does not exist yet. Frontend
visibility is UX only; the API remains the security boundary.

## Branch safety / base

Started from clean `main` at `9b493e6` (== PR #22 mergeCommit == origin/main), 0/0 ahead/behind.
PR #22 MERGED; CI Backend/Frontend/Docker/Security/E2E—Playwright all SUCCESS; reviewDecision
blank (solo-maintainer governance exception, `docs/governance/solo-maintainer-review-exception-pr-22.md`
— not independent approval). Created `phase-11-ui-layout-role-navigation`.

## Phase 10F lifecycle correction (recorded)

Phase 10F → `verified_complete` (PR #22, merge `9b493e6`): five-gate CI all SUCCESS incl.
`E2E — Playwright`; the genuine ClamAV EICAR CI test passed without skipping. Implementation
commit `431dde2`; ClamAV CI correction `c54016d` preserved. The local Windows Playwright
timeout was not claimed as a pass — Linux CI is authoritative. REM-FILE-001 → `verified_complete`.
Stale `local_complete`/`pending PR #22`/`pending CI/review/merge` wording removed from
PROGRESS/CHANGELOG/proof/register/traceability. The governance exception is a solo-maintainer
record, not independent approval.

## Before-state inventory (repository evidence)

- **Routes:** `home`, `dev.design-system`, `not-found`; auth (`auth.login/register/check-email/verify/mfa.setup/mfa.challenge`, `staff.accept`); `onboarding.first-time-setup`; one layout + a single stub/index per role area (`platform.dashboard`, `merchant.dashboard`, `branch.list/create/detail/operating-hours`, `hr.staff/invitations/permission-preview/staff-profile`, `finance.dashboard`, `front-office.dashboard`, `personnel.dashboard`, `audit.dashboard`).
- **Layouts:** 8 shells existed with **empty nav bodies** (`<!-- Phase 11: final navigation items -->`); no theme/profile/logout/context wiring; no drawer.
- **Role entry:** no landing or get-started routes/pages for any role; `Home.vue` was a static Phase-1 card; `Verify`/`MfaChallenge`/`MfaSetup`/`FirstTimeSetup` all redirected to `merchant.dashboard` (not role-aware).
- **Navigation:** no typed registry, no fixture, no parity test.
- **Specs:** no `docs/frontend/screens/` at all.

## Screen inventory & coverage results

- Source of truth `docs/frontend/screens/inventory.json` → generated `inventory.yaml` (snapshot-enforced). 44 §27.1 spec files generated under `docs/frontend/screens/{domain}/` (`node scripts/generate-screen-specs.mjs`) for every implemented production route, all 16 Phase-11 landing/get-started screens, and 2 access-state screens. Remaining §27.3 screens are listed `planned` with truthful owner phases and **no routes/components**.
- Coverage guard `screenInventory.spec.ts` (8 tests) passes and fails on: implemented route without spec, inventory status vs router conflict, planned screen inventing a route, entry missing an owner phase, duplicate keys/routes, and every implemented production route being covered.

## Role → identity → layout → navigation-placement mapping

| Backend role | Content identity | Layout | Primary nav placement | Landing route | Get-started route |
|---|---|---|---|---|---|
| super_admin | super_administrator | PlatformAdminLayout | **header** (mobile disclosure) | platform.landing | platform.get-started |
| merchant_admin | merchant_administrator | MerchantLayout | sidebar + drawer | merchant.landing | merchant.get-started |
| branch_manager | merchant_branch | BranchLayout | sidebar + drawer | branch.landing | branch.get-started |
| hr | merchant_human_resource | BranchLayout | sidebar + drawer | hr.landing | hr.get-started |
| finance | merchant_finance | FinanceLayout | sidebar + drawer | finance.landing | finance.get-started |
| front_office | merchant_front_office | FrontOfficeLayout | sidebar + drawer | front-office.landing | front-office.get-started |
| personnel | merchant_personnel | PersonnelLayout | sidebar + drawer | personnel.landing | personnel.get-started |
| audit | merchant_audit | AuditLayout | sidebar + drawer | audit.landing | audit.get-started |

Placement enforced in one place (`AppShell` via resolved identity) and proven by
`RoleLayouts.spec.ts` and `role-navigation-keyboard.spec.ts` (Super-Admin header nav present /
no sidebar; merchant roles no header nav / sidebar present / drawer trigger present; drawer
focus returns to the trigger on close).

## Mandatory role-content matrix

| Role | Landing content source | Image folder / images used | FAQ source | Terms of Service | Privacy Policy | Data Policy | Layout | Primary nav | Landing route | Get-started route | Tests |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Super Administrator | docs/landing_page/super_administrator_landing_page_content.md (hero verbatim) | public/assets/landing_page_images/super_administrator/ (1.png hero; 2–10 available) | docs/support/faq/super_administrator_faq.md | docs/legal/terms_of_service/super_administrator_terms_of_service.md | …/privacy_policy/super_administrator_privacy_policy.md | …/data_policy/super_administrator_data_policy.md | PlatformAdminLayout | header | platform.landing | platform.get-started | roleNavigation, roleEntryRoutes, RoleLayouts, RoleLandingContent, e2e×4 |
| Merchant Administrator | docs/landing_page/merchant_administrator_landing_page_content.md | …/merchant_administrator/ (1.png hero; 2–8) | …/faq/merchant_administrator_faq.md | …/merchant_administrator_terms_of_service.md | …/merchant_administrator_privacy_policy.md | …/merchant_administrator_data_policy.md | MerchantLayout | sidebar+drawer | merchant.landing | merchant.get-started | as above + getStartedStore, GetStartedChecklist |
| Branch Manager | …/merchant_branch_landing_page_content.md | …/merchant_branch/ (1.png hero; 2–9) | …/faq/merchant_branch_faq.md | …/merchant_branch_terms_of_service.md | …/merchant_branch_privacy_policy.md | …/merchant_branch_data_policy.md | BranchLayout | sidebar+drawer | branch.landing | branch.get-started | as above |
| HR | …/merchant_human_resource_landing_page_content.md | …/merchant_human_resource/ (1.png hero; 2–8) | …/faq/merchant_human_resource_faq.md | …/merchant_human_resource_terms_of_service.md | …/merchant_human_resource_privacy_policy.md | …/merchant_human_resource_data_policy.md | BranchLayout | sidebar+drawer | hr.landing | hr.get-started | as above |
| Finance | …/merchant_finance_landing_page_content.md | …/merchant_finance/ (1.png hero; 2–5) | …/faq/merchant_finance_faq.md | …/merchant_finance_terms_of_service.md | …/merchant_finance_privacy_policy.md | …/merchant_finance_data_policy.md | FinanceLayout | sidebar+drawer | finance.landing | finance.get-started | as above |
| Front Office | …/merchant_front_office_landing_page_content.md | …/merchant_front_office/ (1.png hero; 2–6) | …/faq/merchant_front_office_faq.md | …/merchant_front_office_terms_of_service.md | …/merchant_front_office_privacy_policy.md | …/merchant_front_office_data_policy.md | FrontOfficeLayout | sidebar+drawer | front-office.landing | front-office.get-started | as above |
| Personnel | …/merchant_personnel_landing_page_content.md | …/merchant_personnel/ (1.png hero; 2–7) | …/faq/merchant_personnel_faq.md | …/merchant_personnel_terms_of_service.md | …/merchant_personnel_privacy_policy.md | …/merchant_personnel_data_policy.md | PersonnelLayout | sidebar+drawer | personnel.landing | personnel.get-started | as above |
| Audit | …/merchant_audit_landing_page_content.md | …/merchant_audit/ (1.png hero; 2–8) | …/faq/merchant_audit_faq.md | …/merchant_audit_terms_of_service.md | …/merchant_audit_privacy_policy.md | …/merchant_audit_data_policy.md | AuditLayout | sidebar+drawer | audit.landing | audit.get-started | as above |

All content is imported verbatim from `docs/**` via `?raw` (landing/FAQ) and `import.meta.glob`
(legal, lazy per-document) — a single source of truth; no role copy invented, paraphrased, or merged;
legal text never hand-copied into source. `RoleLandingContent.spec.ts` proves each role renders its
own hero/FAQ and that one role never receives another role's legal documents.

## Navigation parity & prohibited-item proof

- `navigation/roleNavigation.ts` is the source of truth; `docs/frontend/navigation/role-navigation.yaml`
  is generated and snapshot-enforced (`roleNavigation.spec.ts`). Branch Manager and Finance labels are
  verbatim from the Scope's explicit nav lists; the other six derive labels from each role's §4.x
  functionality + §3.2 get-started table.
- Live items point only to real routes (`roleEntryRoutes.spec.ts` resolves each against the router);
  planned items carry an owner phase and **no route** (no dead links), rendered clearly disabled ("Soon").
- Prohibited capabilities proven absent: **no** Super-Admin merchant-create item; **no** Personnel
  contact-export item; audit navigation has no mutating verbs; Merchant-Admin has no
  service/pricing/commission-config item; Front-Office has no payment-validation/receipt-issuance item.
- Permission-denied items are hidden (`RoleNavigation.spec.ts`); direct URL backend authority is
  unchanged (guards remain UX-only stubs).

## Router destination proof (all eight roles)

`landingRouteName()`/`activeRoleIdentity()` resolve from the bootstrap: platform staff → `platform.landing`;
each membership role → its `*.landing`; unresolved → `auth.login` (`roleEntryRoutes.spec.ts`). `Verify`,
`MfaChallenge`, `MfaSetup`, and `FirstTimeSetup` now route role-aware. MFA ordering, pending-setup →
first-time-setup, active-merchant guard, and suspension routing are preserved (existing guards/MFA gate
unchanged; `mfa.spec.ts`, `Verify.spec.ts`, `FirstTimeSetup.spec.ts` updated and green).

## Get-started: sources, persistence, dismiss/reopen

- Checklists are verbatim Scope §3.2 items (`content/getStartedContent.ts`) + a mandatory,
  non-prefilled legal-acknowledgement step. Deep links target only live routes; future items show
  their owner phase and never link.
- `getStartedStore` persists to versioned localStorage keyed by **user ULID + role identity**, storing
  only item ids + completion/dismissal/acknowledgement booleans + schema version — never tokens,
  permissions, contacts, secrets, signed URLs, storage paths, or API responses. A schema-version
  mismatch discards old data; unknown ids are pruned. Resumable across reload; dismissible and
  reopenable; isolated per user and per role. Proven by `getStartedStore.spec.ts` (incl. the
  non-sensitive-fields assertion) and the `role-entry-surfaces.spec.ts` reload/dismiss/reopen e2e.
- Local persistence is device-specific by design (documented; no backend preference API exists, none added).

## Responsive / dark-mode / accessibility results (per-feature gate)

- **Responsive (`role-foundation-responsive.spec.ts`, 56 tests):** every role landing + get-started at
  360 / 768 / 1280 has no whole-page horizontal overflow; a drawer trigger is available at 360px for
  every role; sidebar (merchant) / header nav (Super-Admin) present at 1280px.
- **Dark mode + axe (`role-foundation-accessibility.spec.ts`, 32 tests):** every role landing +
  get-started, in light AND dark, has no serious/critical axe violations (wcag2a/wcag2aa).
- **Keyboard (`role-navigation-keyboard.spec.ts`):** skip link focusable/revealed; placement correct;
  mobile drawer opens, closes on Esc, and returns focus to the trigger.
- Reduced motion respected (`motion-reduce:` on transitions). 44px targets throughout.

## Initial failures, root causes, reruns (history; a passing rerun does not erase a failure)

1. **Hero parse empty** — `extractSection` stopped at the inner `# Headline` (level ≤ section level),
   because landing docs nest an un-numbered `# H1` inside a numbered `## N. Hero Section`. Fix: delimit
   on numbered section headings + first-line fallback. Re-run: green.
2. **Vitest fixture path** — `fileURLToPath(import.meta.url)` threw "URL must be of scheme file" under
   vitest. Fix: `import.meta.dirname`. Re-run: green.
3. **Component test timeouts (5s)** — `await router.isReady()` never resolved on a memory router with no
   initial navigation triggered. Fix: `router.push('/')` before `isReady` (+ a `/` route). Re-run: green.
4. **Build bloat** — all legal docs (~3 MB) bundled into one chunk. Fix: lazy per-document legal via
   `import.meta.glob`; landing chunk dropped to ~134 KB gzip. Re-run: build OK.
5. **axe color-contrast** — (a) RoleNavigation dark `text-text` links on the dark Super-Admin header;
   (b) orange `text-primary` label on white; (c) `text-brand-deep` headings on dark backgrounds;
   (d) `text-text-muted` #6b7280 = 4.39 on surface-alt. Fixes: variant-aware nav colors (white in header);
   label → `text-accent`; adaptive `--color-heading` token (`text-brand-deep` kept only on the orange CTA
   per ADR-009); darkened light `--color-text-muted` to `#4b5563`. Re-run: 32/32 axe green (light+dark).
6. **e2e skip-link assertion** — browser Tab focus order made "first focusable" brittle. Fix: assert the
   skip link is focusable + revealed on focus. Re-run: green.

## Test & gate results (local; Linux CI authoritative for browser)

- `npm run typecheck` clean · `npm run lint` 0 errors (37 pre-existing warnings) · `npm run build` OK.
- Vitest: **133 passed** (27 files).
- Playwright (chromium): role-entry-surfaces (11), role-navigation-keyboard (4), role-foundation-responsive (56), role-foundation-accessibility (32) — all passed locally.

## Work skipped → exact owner phase

service catalogue/clients → 15A · eligibility & availability → 15B · appointments → 16A · walk-ins &
queues → 16B · service sessions → 16C · invoicing → 17 · payments/receipts/refunds/cash-up/locks →
18A/18B · audit log & flagged events → 19 · plans/subscriptions/promotions/M-Pesa/%-fee → 20A–20E ·
compensation/payouts/earnings → 20F–20H · reports/notifications → 21N · personnel SMS → 21S · search →
22 · release-wide responsive/dark/a11y audit → 23 · per-role lazy content split (performance) → 24 ·
deployment → 25. No Phase 15A+ business workflow was implemented.

## Residual risks

- The authenticated landing chunk (`roleContent`, ~134 KB gzip) bundles all roles' landing+FAQ markdown;
  per-role lazy split is a Phase 24 item. Legal is already lazy per-document.
- The non-Branch/Finance roles' nav labels are derived (no explicit per-role nav list exists in the Scope);
  Branch/Finance use the verbatim Scope lists.
- Brand-token additions (`--color-heading`, darker light muted) are AA-driven; the release-wide a11y audit
  (Phase 23) revalidates across all launch screens.

## REM-SCR-001 Phase 11 status

`local_complete` (Phase 11 substrate): inventory + coverage guard + §27.1 specs for implemented +
Phase-11 + access-state screens; canonical navigation registry + snapshot fixture + parity test; eight
role layouts/landings/get-started + persistence + state boundaries + role-aware routing. Future feature
screens remain `planned` with truthful owners and no fake routes; each owning phase writes its final spec
before implementing. CI green on PR #23 (`ci_passed`/`ready_to_merge`); REM-SCR-001 is promoted to
`verified_complete` only on the PR #23 merge (governance exception, not independent approval).

## Phase 11 CI remediation (PR #23) — truthful record

The implementation commit `0482e10` was opened as PR #23. The first PR CI run failed on **two**
jobs; the remaining three passed. Both failures were remediated by commit
`bb04d87898e99b77b77cba1404339dbef6d2d8dc` ("fix: align Phase 11 Docker context and E2E routes").
Root causes are proven below from the diff, the failing logs/traces, and the commit — no causes invented.

### Initial failed CI run — failed jobs
- **Docker — build images** (failed)
- **E2E — Playwright** (failed)
- Backend / Frontend / Security passed on that first run.

### Proven Docker root cause
The production Nginx SPA image build (`docker build -f docker/nginx.Dockerfile --target prod`) runs
`npm run build` = `vue-tsc --noEmit && vite build`. Phase 11 introduced documentation-sourced imports
under the `@docs` alias: `screenInventory.spec.ts` imports `@docs/frontend/screens/inventory.json`
(type-checked by `vue-tsc`, which includes `*.spec.ts`), and `content/roleContent.ts` /
`content/legalContent.ts` import `@docs/**` markdown (`?raw` / `import.meta.glob`). The repository
`.dockerignore` excluded the entire `docs` directory from the Docker build context (line `docs`), so the
required documentation path — including `@docs/frontend/screens/inventory.json` — was **not available in
the Docker build context**, and the SPA build could not resolve it → the image build failed. This is a
build-context defect, not a product-code defect.

**Fix (in `bb04d87`):** removed the single `docs` line from `.dockerignore` so the documentation path is
present in the Docker build context. (`*.md` ignore is retained; the `docs/**` tree, incl. the JSON
inventory, is now included.)

### Proven Playwright root cause
Phase 11 re-pathed the role-entry routes: each role area's index (`path: ''`) became the **landing**
page, the pre-existing functional pages moved to explicit sub-paths (`branch.list` `/branch` → `/branch/list`,
`hr.staff` `/hr` → `/hr/staff`), and the post-setup/login redirect target changed from `merchant.dashboard`
to `merchant.landing` (the `/merchant` index now renders the landing, not the "Welcome" dashboard shell).
The three **pre-existing** end-to-end specs still asserted the old routes/headings/redirects, so they
failed against the changed app:
- `tests/e2e/merchant-onboarding.spec.ts` — expected the first-time-setup `redirect: merchant.dashboard`
  and `/merchant$` to show the "Welcome" dashboard heading; after Phase 11 the redirect is
  `merchant.landing` and `/merchant` renders the landing.
- `tests/e2e/branches-staff-invitations.spec.ts` — navigated to `/branch` and `/hr` expecting the branch
  list / staff roster, which moved to `/branch/list` and `/hr/staff`.
- `tests/e2e/auth-magic-link.spec.ts` — asserted the old post-verify destination instead of the role
  landing.

**Fix (in `bb04d87`):** updated those three specs to the changed role-entry routes/selectors
(landing destinations, `redirect: merchant.landing`, the moved list/roster paths). No product code was
changed to satisfy the tests; the specs were corrected to match the deliberate Phase 11 routing.

### Files changed by `bb04d87`
- `.dockerignore` (−1 line: `docs`)
- `tests/e2e/auth-magic-link.spec.ts`
- `tests/e2e/branches-staff-invitations.spec.ts`
- `tests/e2e/merchant-onboarding.spec.ts`
(978 insertions / 419 deletions across 4 files; no `resources/spa/src` product code, no migrations.)

### Targeted + full local regression after remediation
- Targeted: the three updated e2e specs + the Phase 11 e2e (`role-entry-surfaces`,
  `role-navigation-keyboard`, `role-foundation-responsive`, `role-foundation-accessibility`).
- Full: `npm run typecheck` clean · `npm run test` (vitest 133) green · `npm run lint` 0 errors ·
  `npm run build` OK · Playwright suites green on Linux CI.

### Successful CI run
- **Run `28314016145`** (event `pull_request`, head `bb04d87`, conclusion **success**),
  https://github.com/ikrome002-design/servana/actions/runs/28314016145
- Five successful checks: **Backend — Pint, Larastan, Pest** · **Frontend — ESLint, vue-tsc, Vitest,
  build** · **E2E — Playwright** · **Security — gitleaks** · **Docker — build images**.

### Remaining warnings (not errors; not acceptance failures)
- **GitHub Actions Node runtime deprecation annotations** on some marketplace actions — informational
  CI annotations, non-blocking; the run conclusion is `success`.
- **Non-blocking ESLint warnings** (37; mostly pre-existing formatting + two `vue/no-v-html` on trusted,
  version-controlled legal/FAQ content) — `npm run lint` exits with **0 errors**.
These are warnings only. They are not misrepresented as errors, and they are not Phase 11 acceptance
failures; all five required checks succeeded.
