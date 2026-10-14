# COR-UI08-UI12-VIS-001 — visual direction

The product owner rejected the visual result of the five oldest authenticated experiences
(Super Administrator, Merchant Administrator, Branch, Human Resource, Finance). This document is
the design decision record for the corrective redesign: what the five accounts now look like, why,
and how it is built. Machine truth (per-page compositions, scores, hashes) lives in the JSON files
beside it.

## 1. Scope switch — one decision, five accounts

`resources/spa/src/design-system/experience.ts` decides the experience from the account identity:
the five corrective accounts get `refined`, every other surface stays `standard`. `AppShell.vue`
writes it to `<html data-sv-experience="refined">` (so teleported dialogs, drawers and toasts
inherit it) and removes it on unmount. Every refinement keys off that attribute:

| Layer | File | Scope |
|---|---|---|
| Token remap, shell, navigation, header, states, badges, tables, buttons, footer, dialogs, toasts, motion | `styles/refined-experience.css` | `:root[data-sv-experience='refined']` only |
| Composition kit (`sv-r-*`) | `styles/refined-components.css` | class-opt-in, used only by in-scope pages; inside `@layer components` so utilities still compose |
| Kit components | `components/refined/SvPanel.vue`, `SvKpiCard.vue`, `SvFeaturePanel.vue` | in-scope pages |
| Shared pages | `AccountAndSecurity`, `RoleGetStarted`, `GetStartedChecklist`, `InvoiceList`, `AuditDomainEvents` | branch on `useRefinedExperience()`; Front Office, Personnel and Audit render their unchanged template |

The experience chooses presentation only. It is never an input to navigation filtering, a route
guard, a policy or a request (ADR-017).

## 2. Tokens (generated, contrast-checked)

Added to `design-system/tokens.json` and regenerated with `npm run tokens` (the generator
enforces 10 new contrast requirements in both themes):

- Palette: `linen` `#F5F3EF`, `linen-50` `#FAF9F6`, `linen-200` `#E9E5DD`, `night-950` `#0F151C`,
  `night-900` `#151D26` — a warm neutral canvas (light) and a deep blue-black (dark), so Savannah
  Orange and Service Teal read as focal accents instead of competing with a cream background.
- Semantic: `color-refined-canvas`, `-sunken`, `-hairline`, `-nav-active-bg/-fg/-icon`,
  `-feature-bg/-fg/-accent`.
- Component: `radius-panel` 1.25rem, `radius-field` 0.75rem, `radius-shell` 1.5rem,
  `shadow-panel`, `shadow-float`, `refined-sidebar-width` 17rem, `refined-shell-inset` 0.75rem.

Brand mapping (Brand Identity wins): Savannah Orange = primary actions and active nav pill;
Deep Earth Brown = the feature surface and headings; Service Teal = operational/financial eyebrows
and links; Acacia Green = success and positive lanes; Golden Sun = warm highlights and warning
lanes; Clay Red = critical lanes and destructive actions only. No reference colour enters a token.

## 3. Shell

- **Sidebar accounts** (Merchant Administrator, Branch, HR, Finance): a floating rounded sidebar
  panel on the linen canvas with the approved logo and account name, group labels in tracked
  small caps, and a filled Deep Earth pill for the active entry. Tablet rail and mobile drawer keep
  their contracts. Gated entries stay visibly unavailable with their reason.
- **Super Administrator**: the header strip becomes a Deep Earth gradient capsule with pill group
  triggers (the current group is a white pill); still header-first, no left sidebar.
- **Header**: account crumb + page title; branch/merchant context chips; the fixed footer becomes
  a translucent band with the same reserved height.

## 4. Page archetypes (the vocabulary, not a template)

| Archetype | Anatomy | Used by (examples) |
|---|---|---|
| Command centre | Dark feature panel leading an asymmetric split; queue beside it; KPI band; ledger band; gate notice | five dashboards |
| Editorial orientation | Wash header, principle cards, feature progress ring, milestone rail | five Get Started pages |
| Rule workspace | Current-state fact strip, next-version form, "how this rule applies" rail | Super Admin billing (6 pages) |
| Ledger | Hairline panel, column header band, hover rows, chips | invoices, payment records, cash-up sheet, staff directory, liabilities |
| Lifecycle board / pipeline | Status lanes or numbered chain with counts | disputes, refunds, payouts, queue, payout preparation |
| Verdict desk / case file | Fact strip + evidence + decision rail; side-by-side comparison with a connector | payment validation detail, duplicate review |
| Statement / contract | Document header, numbered clauses or line items | subscription invoice detail, compensation detail |
| Timeline | Marker rail with dated records | audit streams, compensation history, period locks, price schedules |
| Identity | Dark identity card with luminous ring beside grouped regions | account pages, staff detail, merchant profile |
| Stepped form | Numbered sections with a live preview rail | setup, invite staff, compensation setup |

## 5. States, overlays and motion

- Empty/error states: a soft panel with an icon well and calm copy; loading keeps its skeleton.
- Dialogs (Notifications reference): blurred scrim, hairline sheet with `radius-shell` and
  `shadow-float`, a warm wash behind the heading, sunken footer; same focus trap and roles.
- Motion: one short rise-in on page content and dialog sheets, nav press feedback; all disabled
  under `prefers-reduced-motion` (plus the global reduced-motion rule).

## 6. Light and dark

Both themes are designed, not inverted: light uses the linen canvas with white panels and brown
feature surfaces; dark uses the night canvas with slate panels and the same warm feature surface,
with every text/surface pairing covered by a generator contrast requirement.

## 7. What did not change

No route, permission, API call, store contract, finance arithmetic, legal copy, gate, or Front
Office / Personnel / Audit / public / auth presentation. The scope guard in
`tests/e2e/cor-ui08-ui12-visual-regression.spec.ts` proves the experience attribute is present on
exactly the five accounts.
