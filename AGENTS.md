# AGENTS.md — Servana by Citrus

Agent instructions for Codex. This file is an IDE operating guide only. It does
not override, replace, or compete with **Servana Software Development Plan.md**,
the primary engineering source of truth. Read the relevant Plan sections before
every task and the complete active phase before implementation.

---

## 1. Project Identity

- **Product:** Servana by Citrus — multi-tenant service-operations SaaS for
  African service-based SMEs (salons, barbershops, spas, massage parlours,
  grooming studios).
- **Operator:** Citrus Labs Limited.
- **Project root:** `C:\Users\nderu\Documents\Development\Product\Servana`
  (all paths below are relative to this root).
- **Stack (pinned, Plan §7 A-09 / ADR-001):** Laravel 12 (installed 12.62.0) /
  PHP 8.3 · Vue 3 + TypeScript + Pinia · Tailwind CSS · PostgreSQL 16 · Redis 7 ·
  Meilisearch · S3-compatible storage · Docker · GitHub Actions. **No jQuery.
  Ever.** (Upgraded from Laravel 11 in PR #11; do not call any version "LTS".)
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
   — binding delivery authority for **UI-00 … UI-17 only**; governs frontend
   information architecture, role hosts, navigation placement, landing pages,
   content mapping, theme, responsive, accessibility, and browser evidence. It
   never overrides items 1–2 on business, security, financial, tenancy, or
   partner-integration rules.
4. `docs/frontend/navigation/servana-user-account-navigation-maps.md` — the
   binding frontend page and workflow specification (160 authenticated pages
   across eight accounts). Generated from Appendix A of item 3; never
   hand-edit.
5. `docs/auth/permission-matrix.yaml` — canonical permission keys
6. The actual repository — implementation evidence for routes, middleware,
   controllers, policies, migrations, tests, generated artifacts, and CI
7. `docs/PROGRESS.md`, `docs/CHANGELOG.md`, and `docs/proof/` — historical
   context and evidence records, not source-of-truth substitutes, and never
   proof that a browser route or page works
8. `AGENTS.md` (this file) — IDE workflow guide only; it never overrides,
   replaces, or competes with the Development Plan
9. `docs/brand/Servana Brand Identity.md` — colors, typography, tone, logo
   usage

> The full corrective UI programme brief — binding UI decisions (ADR-016 …
> ADR-025), navigation placement, light-mode default, fixed footer, and
> UI-00 … UI-17 phase ownership — is in `CLAUDE.md` §3A. Read it before any
> frontend, role-host, landing-page, navigation, or design-system task.

## 3. Project Document Map

Use these documents where the task needs them. Do not invent content that a
document already defines — read the file.

| Asset | Path |
|---|---|
| Agent instructions | `AGENTS.md` (root) |
| Development plan | `Servana Software Development Plan.md` (root) |
| Project scope | `Servana Project Scope.md` (root) |
| Brand identity | `docs/brand/Servana Brand Identity.md` |
| **UI/UX delivery plan (UI-00 … UI-17)** | `Servana_Role_Specific_UI_UX_Subdomain_Software_Development_Plan.md` (root) |
| **Binding navigation map (160 pages)** | `docs/frontend/navigation/servana-user-account-navigation-maps.md` — generated; edit Appendix A of the UI/UX plan instead |
| UI source inventories (generated) | `docs/frontend/source-inventory/{navigation-map,role-content,brand-assets,landing-images}.json` |
| UI source generator | `node scripts/generate-ui-source-inventory.mjs` (`--check` for staleness) |
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
>   existed here; earlier revisions of this file claimed it did. Do not
>   recreate it.
> - `public/assets/brand/Logo.svg` was **deleted under product-owner
>   authority** (commit `49160cd`, 2026-07-07). Never restore, reference, or
>   require it.
> - Favicon filenames are **lowercase** — Linux and CI are case-sensitive, so
>   `Favicon.ico` resolves to nothing.
> - Final landing-page image selection belongs to later UI phases and should
>   normally use approximately **two to four** supplied images per account.
> - Legal text is rendered **verbatim** and is never paraphrased.
>
> All of the above are enforced by `tests/Feature/Docs/UiSourceContractTest.php`.

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

- Execute the active **v3 roadmap (Plan §§79–80) strictly in order**: Phase V
  (as-built verification) → R1–R7 (pre-feature remediation, §79) → the feature
  phases (10, 10F, 11, 15A…25, §80). The pre-feature gate (§5.4) must be closed
  before any feature phase begins. (This supersedes the old §27 "Phases 1–25"
  roadmap that earlier docs reference.) One phase = one reviewed branch/PR. Do
  not start the next phase until the current phase's acceptance criteria are
  demonstrably met and the human approves.
- At phase start: restate the phase objective, list the Plan sections it
  implements, list files to create/modify, and the tests you will write.
- At phase end: run the full suite, write `docs/proof/phase-{n}.md`, update
  `docs/CHANGELOG.md`, summarize residual risks.
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

## 7. Commands

```bash
make up           # docker compose dev stack
make fresh        # migrate:fresh --seed (demo tenants, local only)
make test         # composer pint --test && composer stan && php artisan test --parallel
npm run test      # vitest
npm run e2e       # playwright (critical tag)
php artisan test --filter={TestName}   # targeted
```
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
