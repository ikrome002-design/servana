# CLAUDE.md — Servana by Citrus

Agent instructions for Claude Code. This file is an IDE operating guide only. It
does not override, replace, or compete with **Servana Software Development
Plan.md**, the primary engineering source of truth. Read the relevant Plan
sections before every task and the complete active phase before implementation.

---

## 1. Project Identity

- **Product:** Servana by Citrus — multi-tenant service-operations SaaS for
  African service-based SMEs (salons, barbershops, spas, massage parlours,
  grooming studios).
- **Operator:** Citrus Labs Limited.
- **Project root:** `C:\Users\nderu\Documents\Development\Product\Servana`
  (all paths below are relative to this root).
- **Stack (pinned, Plan §7 A-09 / ADR-001):** Laravel 12 (installed **12.62.0** per
  `composer.lock`) / PHP **8.3** (Docker dev image) · Vue 3 + TypeScript + Pinia ·
  Tailwind CSS · PostgreSQL 16 · Redis 7 · Meilisearch · S3-compatible storage · Docker ·
  GitHub Actions. **No jQuery. Ever.**
- **Integration ownership (Plan §2.2, ADR-012/013):** Servana owns business-billing truth
  and referral-activity truth. **Wallet by Citrus** (separate product/repository) owns
  money-movement truth. **Citrus Refer & Earn** (separate product/repository) owns
  referral-reward truth. Servana implements **only its own side** of each integration
  contract. **No direct Safaricom/Daraja/provider integration** in Servana — platform
  billing collections are Wallet-orchestrated (Plan §9 rule 20; `NoDirectProviderIntegrationTest`).
  Merchant-client **`mpesa_offline`** payment-method terminology (Phase 18A) is legitimate
  and unrelated to platform billing provider integration.
- **Auth model:** Magic Link only, for all users (Plan §9). No passwords exist.
- **Currency/time:** KES as integer minor units; timestamps UTC; business-day
  logic in `Africa/Nairobi`.

## 2. Source-of-Truth Hierarchy

When documents conflict, higher wins. Stop and ask the human if a conflict is
material.

1. `Servana Software Development Plan.md` — architecture, schema, phases,
   tests, acceptance criteria (root folder)
2. `Servana Project Scope.md` — product behavior and business rules; consult
   only when the Plan requires additional business context or when a material
   business-rule ambiguity remains
   (root folder)
3. `Servana_Role_Specific_UI_UX_Subdomain_Software_Development_Plan.md` (root)
   — the binding delivery authority for **UI-00 … UI-17 only** (see §3A). It
   governs frontend information architecture, role hosts, navigation
   placement, landing-page structure, content mapping, visual identity
   application, responsive/theme/accessibility behavior, browser evidence,
   visual regression, and UI production acceptance. It **never** overrides
   items 1–2 on business, security, financial, tenancy, data-integrity, or
   partner-integration rules.
4. `servana-user-account-navigation-maps.md`, registered at
   `docs/frontend/navigation/servana-user-account-navigation-maps.md` — the
   binding human-readable frontend page and workflow specification (160
   authenticated pages across eight accounts). Generated verbatim from
   Appendix A of item 3; never hand-edit.
5. `docs/auth/permission-matrix.yaml` — canonical permission keys and
   assignment
6. The actual repository — implementation evidence for routes, middleware,
   controllers, policies, migrations, tests, generated artifacts, and CI
7. `docs/PROGRESS.md`, `docs/CHANGELOG.md`, and `docs/proof/` — historical
   context and evidence records, not source-of-truth substitutes. **Never**
   proof that a browser route or page works.
8. `CLAUDE.md` (this file) — IDE workflow guide only; it never overrides,
   replaces, or competes with the Development Plan
9. `docs/brand/Servana Brand Identity.md` — colors, typography, tone, logo
   usage

## 3. Project Document Map

Use these documents where the task needs them. Do not invent content that a
document already defines — read the file.

| Asset | Path |
|---|---|
| Agent instructions | `CLAUDE.md` (root) |
| Development plan | `Servana Software Development Plan.md` (root) — v4 standalone plan of record |
| Project scope | `Servana Project Scope.md` (root) |
| Product authority (combined) | `SERVANA COMBINED.txt` — **not present in this repository**; Plan §2 cites it |
| Wallet integration authority | `Wallet_by_Citrus_Platform_Project_Scope.md` — **not present in this repository** |
| R&E integration authority | `Refer_and_Earn_Project_Scope.md` + `Citrus_Refer_and_Earn_Production_Software_Development_Plan.md` — **not present in this repository** |
| Engineering corrections (historical) | `SERVANA_DEVELOPMENT_PLAN_CORRECTIONS.md` — **not present**; content folded into the v4 plan |
| Brand identity | `docs/brand/Servana Brand Identity.md` |
| **UI/UX delivery plan (UI-00 … UI-17)** | `Servana_Role_Specific_UI_UX_Subdomain_Software_Development_Plan.md` (root) |
| **Binding navigation map (160 pages)** | `docs/frontend/navigation/servana-user-account-navigation-maps.md` — generated; edit Appendix A of the UI/UX plan instead |
| **Canonical 160-page contract (UI-07)** | `docs/frontend/navigation/servana-user-account-navigation-map.yaml` — the one **handwritten** machine-readable authority; pinned to the human map above by `Ui07NavigationContractTest` |
| UI-07 generated projections | `resources/spa/src/navigation/navigationRegistry.generated.ts`, `docs/frontend/screens/contract/{account}/{screen_key}.md` (160), `docs/frontend/audits/ui-07/*.json` ← `npm run nav:generate` (`nav:check`, `nav:negative-controls`) |
| UI source inventories (generated) | `docs/frontend/source-inventory/{navigation-map,role-content,brand-assets,landing-images}.json` |
| UI source generator | `node scripts/generate-ui-source-inventory.mjs` (`--check` for staleness) |
| **Content/asset pipeline contracts (UI-05)** | `docs/frontend/content/{README,content-contract,legal-preservation-contract,image-pipeline-contract}.md` |
| Generated role content (never hand-edited) | `resources/spa/src/content/generated/` ← `scripts/generate-role-content.mjs` |
| Curated landing images + derivatives | `public/assets/landing_page_images/manifest.json`, `.../generated/` ← `scripts/generate-landing-images.mjs`; selection in `config/landing-image-selection.json` |
| Quarantined brand working files (non-public) | `docs/brand/quarantine/ui01-asset-002/`; decision record `config/brand-asset-quarantine.json` |
| Landing page copy (one file per account user) | `docs/landing_page/{role}_landing_page_content.md` |
| Data policies (per account user) | `docs/legal/data_policy/{role}_data_policy.md` |
| Privacy policies (per account user) | `docs/legal/privacy_policy/{role}_privacy_policy.md` |
| Terms of service (per account user) | `docs/legal/terms_of_service/{role}_terms_of_service.md` |
| FAQs (per account user) | `docs/support/faq/{role}_faq.md` |
| **Approved primary logo** | `public/assets/brand/Logo.png` (500×500 PNG) |
| Favicons (exact lowercase casing) | `public/assets/brand/favicon.ico`, `favicon-16x16.png`, `favicon-32x32.png`, `apple-touch-icon.png`, `android-chrome-192x192.png`, `android-chrome-512x512.png` |
| Landing page images (per account user) | `public/assets/landing_page_images/{role}/` |

`{role}` ∈ `merchant_administrator`, `merchant_audit`, `merchant_branch`,
`merchant_finance`, `merchant_front_office`, `merchant_human_resource`,
`merchant_personnel`, `super_administrator`. Each account user has its own
landing page, data policy, privacy policy, terms of service, and FAQ — build
one route/view per role and source copy **verbatim** from these files; never
paraphrase legal text.

> **Path and asset notes (corrected in Phase UI-00 — verified against the
> repository, not assumed):**
>
> - The canonical landing-copy directory is `docs/landing_page/` with an
>   **underscore**. A space-named `docs/landing page/` directory has never
>   existed here; earlier revisions of this file claimed it did and the wrong
>   glob has already cost time in Phase 24. Do not recreate it.
> - `public/assets/brand/Logo.svg` was **deleted under product-owner
>   authority** (commit `49160cd`, 2026-07-07). It must not be restored,
>   referenced, or treated as required. Historical documents may still mention
>   it as fact; no active workflow may depend on it.
> - Favicon filenames are **lowercase**. Linux and CI are case-sensitive, so
>   `Favicon.ico` (as earlier revisions of this file claimed) resolves to
>   nothing.
> - Final landing-page image selection belongs to later UI phases and should
>   normally use approximately **two to four** supplied images per account —
>   never every image in the directory.
> - Legal text is rendered **verbatim** and is never paraphrased.
>
> All of the above are enforced by `tests/Feature/Docs/UiSourceContractTest.php`.

## 3A. Corrective UI/UX Programme (Phases UI-00 … UI-17)

Frontend, role-host, landing-page, navigation, design-system, theme,
responsive, accessibility, and UI remediation work is governed by the
**corrective UI programme**, adopted in Phase UI-00.

**Before any such task, read:**

1. the complete active UI phase in
   `Servana_Role_Specific_UI_UX_Subdomain_Software_Development_Plan.md`;
2. that plan's cross-cutting sections (§2 hierarchy, §4 hosts, §6 frontend
   architecture, §7 navigation/screen contract, §9 design system, §11 footer,
   §12 theme, §13 responsive, §17 content integrity, §18 security, §19
   accessibility, §21 testing);
3. the binding navigation map at
   `docs/frontend/navigation/servana-user-account-navigation-maps.md`;
4. `docs/brand/Servana Brand Identity.md`;
5. the relevant role landing/legal/FAQ sources listed in §3.

Backend and cross-platform invariants remain governed by
`Servana Software Development Plan.md`. The UI plan never relaxes §6 below.

### Binding UI decisions (ADR-016 … ADR-025)

- **Eight account hosts, one application.** `servana.ke` (Merchant
  Administrator), `citrus.servana.ke`, `branch.`, `finance.`, `hr.`,
  `office.`, `staff.`, `audit.` — all served by one Laravel + Vue app.
- **Hostnames are routing/context inputs, never authorization.** The host
  selects the *experience*; every protected request re-evaluates identity,
  membership, role, permission, tenant, branch, own-scope, and MFA from the
  database. UI visibility stays UX only; server authorization is the boundary.
- **Navigation placement.** The **Super Administrator** uses **header**
  primary navigation on desktop. **Every other authenticated account** uses
  **left** primary navigation on desktop, a collapsible rail on tablet, and an
  accessible left-anchored drawer on mobile. This supersession changes
  placement **only** — never ownership, routes, permissions, or scope.
- **Light mode is the default.** `prefers-color-scheme` must not select the
  theme. Dark mode is explicit and persistent (per browser when anonymous;
  per user record when authenticated), applied before hydration.
- **Fixed footer** on every page, with the layout reserving its block size so
  it never obstructs actions, fields, validation, pagination, records, focused
  controls, or mobile safe areas.
- **Icons:** Heroicons for Vue. No emoji icons.
- **Every account** has a role-specific public landing page, FAQ, Data Policy,
  Privacy Policy, and Terms of Service.
- **160 authenticated pages** are required across the eight accounts
  (22/23/18/19/24/19/20/15). The current screen inventory records what is
  *built*; the navigation map records what is *required*. Never conflate them,
  and never mark a page implemented to satisfy a count.

### Phase ownership

`UI-00` source adoption (this contract) · `UI-01` as-built browser audit ·
`UI-02` multi-host foundation · `UI-03` auth/session/account switching ·
`UI-04` design system and shared components · `UI-05` content and asset
pipeline · `UI-06` eight landing pages · `UI-07` navigation registry and
screen contracts · `UI-08 … UI-15` the eight account experiences
(Super Admin, Merchant Admin, Branch, HR, Finance, Front Office, Personnel,
Audit) · `UI-16` responsive/accessibility/theme/visual-regression audit ·
`UI-17` performance, security, deployment, closeout.

UI-00 is source adoption only. **UI-01 owns the browser audit** — no UI phase
may cite `PROGRESS.md`, `CHANGELOG.md`, or an old screenshot as proof that a
page renders.

## 4. The AI Manifesto (apply in every phase, every task)

1. **Prove the Problem.** Never guess. Before building or fixing anything,
   show evidence: the Plan/Scope section requiring it, the failing test, the
   missing route, the schema gap. State what must be built, why, which
   requirement it satisfies, what fails if omitted, and how it will be
   verified.
2. **Root Cause Analysis.** Inspect the actual code. Separate root cause from
   symptom. Name affected files, functions, routes, tables, workflows. No
   superficial patches.
3. **Fix with Precision.** Smallest correct change addressing the proven root
   cause. No broad rewrites, no styling fixes for logic defects, no
   frontend fixes for backend authorization issues, no temporary hacks, no
   duplicated logic, no silent failure handling.
4. **Test Thoroughly.** Add/update unit, feature, API, authorization,
   tenant-isolation, validation, and (where relevant) component/E2E and
   security regression tests. Run them.
5. **Demonstrate Resolution.** Show test output, API transcripts, DB query
   results, authorization-denial examples, tenant-isolation proof, and
   edge-case checks. Save evidence to `docs/proof/phase-{n}.md`.

**Bug Fix Protocol (mandatory format for any defect):**
Observed problem · Evidence · Affected files · Root cause · Why this is the
root cause · Correct fix · Files changed · Tests added/updated · Test command ·
Test result · Proof of resolution · Remaining risk.

## 5. Phase Workflow

- Execute the active **v4 roadmap (Plan §§79–80) strictly in order**: Phase V
  (as-built verification) → R1–R7 (pre-feature remediation, §79) → the **§1.3 v4
  plan-adoption PR** (mandatory before Phase 20) → feature phases (10, 10F, 11,
  15A…25, §80). The pre-feature gate (§5.4) is **closed and satisfied** (all
  PRE_FEATURE_REMEDIATION items `verified_complete`). One phase = one reviewed
  branch/PR. Do not start Phase 20D-W until External Gate W (§80.2) is open.
- Backend roadmap position: Phase **24 is verified_complete** (PR #49 merged as
  `db3827b`). Phase **25** (deployment) is the only remaining backend phase and
  needs its own authorization. Phases **20D-W**, **21R-B**, **21N** stay blocked
  behind Gate W.
- The **corrective UI programme (UI-00 … UI-17, §3A)** runs against the UI/UX
  plan and is sequenced independently of the backend roadmap. Execute UI phases
  strictly in order; one UI phase = one branch = one reviewed PR = one proof
  file. Do not start UI-01 until the UI-00 adoption PR is merged.
- At phase start: restate the phase objective, list the Plan sections it
  implements, list files to create/modify, and the tests you will write.
- At phase end: run the full suite, write `docs/proof/phase-{n}.md` (UI phases
  use `docs/proof/ui-{nn}.md`), update `docs/CHANGELOG.md`, summarize residual
  risks.
- Track progress in `docs/PROGRESS.md` (phase, status, PR, proof link).

## 6. Non-Negotiable Guardrails (reject your own work if violated)

1. No jQuery; no JS device detection; responsive via CSS media queries only
   (mobile ≤767, tablet 768–1024, desktop ≥1025). Never disable browser zoom.
2. Frontend checks are UX only — every mutating route has `auth:sanctum`, a
   Form Request, and a Policy. Backend authorization is the security boundary.
3. Every tenant-owned model uses the `BelongsToMerchant` scope (branch-owned
   adds `BelongsToBranch`). `withoutTenancy()` only inside Platform services.
   Route bindings resolve ULIDs **inside** tenant scope → foreign IDs 404.
4. No secrets in code, repo, images, or logs. `.env` only. Never log Magic
   Link tokens, references in full, or any credential.
5. Financial invariants live in the database too: unique invoice/receipt
   numbers, receipt-only-after-validation trigger, appointment exclusion
   constraint, append-only hash-chained `audit_logs` (no UPDATE/DELETE).
6. Money is integer minor units via the `Money` value object — never float.
7. Status fields are backed enums + DB CHECKs; transitions go through state
   machines; invalid transitions → 422 `invalid_state_transition`.
8. Authority boundaries are absolute (Plan §10.2): Merchant Admin never
   configures services/pricing/commissions/personnel assignment; HR is
   same-branch only and cannot self-escalate; Front Office records but never
   validates payments or issues receipts; Audit is read-only; **Merchant
   Personnel contact export does not exist anywhere** — no schema field, no
   endpoint, no UI.
9. Magic Links: hashed at rest, single-use atomic consume, 15-min expiry, all
   seven Scope §2.3 checks at request AND consume time; suspension instantly
   revokes sessions and unused links.
10. Every collection paginates; every request validates; named rate limiters
    per Plan §9.3; structured error envelope per Plan §11.5.
11. Accessibility and both themes are release gates: labels, focus rings,
    44px targets, axe clean on gated pages, AA contrast light + dark.
12. Never edit a shipped migration; expand/contract for schema changes.
13. Tests run against PostgreSQL (service container), never SQLite.
14. **Hostnames are never authorization.** The account host selects the
    experience; it is never an input to a policy, gate, or query scope
    (ADR-017).
15. **Light mode is the default** and `prefers-color-scheme` must not select
    the theme (ADR-021). No emoji icons in UI source.
16. **A page is not "implemented" without browser proof.** `PROGRESS.md`,
    `CHANGELOG.md`, and old screenshots are never evidence that a route
    renders (ADR-025).

## 7. Commands

```bash
make up           # docker compose dev stack
make fresh        # migrate:fresh --seed (demo tenants, local only)
make test         # composer pint --test && composer stan && php artisan test --parallel
npm run test      # vitest
npm run e2e       # playwright (critical tag)
php artisan test --filter={TestName}   # targeted
node scripts/generate-ui-source-inventory.mjs           # regenerate UI source inventories
node scripts/generate-ui-source-inventory.mjs --check   # fail if they are stale
npm run content:generate   # recompile the 40 approved role documents (Phase UI-05)
npm run content:check      # fail if the generated content artifacts are stale
npm run assets:generate    # re-derive the landing-image manifest and derivatives
npm run assets:check       # fail if a manifest path, hash or derivative is stale
node scripts/ui05-negative-controls.mjs   # prove the UI-05 generator guards still fire
npm run nav:generate       # rebuild the UI-07 navigation registry, 160 screen specs and matrices
npm run nav:check          # fail if any UI-07 projection is stale
npm run nav:negative-controls   # prove the UI-07 contract guards still fire
```
Never hand-edit anything under `resources/spa/src/content/generated/` or
`public/assets/landing_page_images/generated/` — change the source and regenerate.
The backend suite runs **in the app container** (`make test`); the host PHP has no
`pdo_pgsql` driver.
Quality gates before any commit: Pint clean · Larastan level 8 · all tests
green · no `npm audit`/`composer audit` high+critical · gitleaks clean.

## 8. Conventions Quick Reference

- Domain logic in `app/Domain/{Context}/`; controllers are thin
  (validate → authorize → service → Resource). Plan §5.1 layout is binding.
- Test names/files follow Plan §25 exactly — they are part of the spec.
- API: `/api/v1`, kebab-case nouns, ULIDs external, `Idempotency-Key` on
  financial POSTs.
- UI: components in `resources/spa/src/components/ui/` (`Sv*` prefix), brand
  tokens from the Brand Identity doc, Inter for UI / Manrope for page titles,
  sentence-case buttons, role navigation lists verbatim from the Scope.
- Commits: `phase-{n}: {imperative summary}`; PR description must cite Plan
  section IDs and include the Manifesto evidence.

## 9. When to Stop and Ask

Stop and ask the human before: deviating from the pinned stack, altering the
permission matrix (Plan §10.3), changing any financial calculation or status
machine, touching legal/policy copy, deleting any data, skipping a failing
test, or resolving a Plan↔Scope conflict.
