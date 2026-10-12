# Phase UI-16 — Responsive, accessibility, theme and visual regression — implementation checklist and resume register

Branch `phase-ui-16-responsive-accessibility-theme-visual-regression`, base
`088a6cd764534ac5a4e44d1ce8c1e72cebfd480b` (UI-15 PR #66 normal squash, live-verified 2026-10-12).
Claude Code implementation session 1 of 2: **no staging, commit, push, PR or merge** — Codex Prompt 2
owns the GitHub lifecycle.

## Resume pointer

Read this file first on resume. Sources are read once per unchanged hash; do not repeat discovery.

| Source | Pointer |
|---|---|
| UI/UX plan | Phase UI-16 (line ~2552) — acceptance: no horizontal scroll, overlap, clipping or footer obstruction; 0 serious/critical a11y; baselines reviewed; reduced motion. Cross-cutting §9 design system, §11 footer, §12 theme, §13 responsive, §19 accessibility, §21 testing, §26 proof format |
| Canonical contract | `docs/frontend/navigation/servana-user-account-navigation-map.yaml` → `docs/frontend/audits/ui-07/account-page-matrix.json` (unchanged by UI-16): **160 = 132 implemented + 28 disabled_by_gate + 0 planned + 0 removed** |
| Older pages in scope | UI-08 … UI-12 live pages = **82** (Super Admin 17, Merchant Admin 15, Branch 15, HR 15, Finance 20); UI-13/14/15 live = 50 (17 + 19 + 14) |
| Reference images | `C:\Users\nderu\Documents\Development\Product\Template\UI-UX Design Inspiration` — six hashes equal UI-15's record; all six viewed 2026-10-12 (`design-inspiration-notes.md`) |
| Breakpoints | Only `md` (768) and `lg` (1025) exist (`--breakpoint-sm/xl/2xl: initial`); `sm:`/`xl:` compile to nothing — now guarded |

## Increments

| # | Increment | State | Evidence |
|---|---|---|---|
| A | Source and predecessor truth: PR #66 verified merged; branch created from clean `main`; UI-15 reconciled once (append-only) | done | `docs/PROGRESS.md`, `docs/proof/ui-15.md` (appended section), `ui-15/defect-closure.json` `post_merge_reconciliation`, CSV SRV-UI15-*, `Phase23TraceabilityTest` |
| B | As-built browser audit of all 132 live pages (desktop light, desktop dark, mobile light) from the HEAD build; before-score per page | done | `screenshots/before/` (82), `visual-debt-closure.json` before scores |
| C | Shared root-cause defects (dead breakpoints, footer reserve, state-boundary layout, nav strip, sidebar, page titles, dead utilities, status labels, Nairobi dates) | done | `defect-closure.json` (20, all `local_complete`) |
| D | Shared primitives: SvPageHeader→SvTonalPageHeader with account tone, SvDataTable/SvFilterBar surfaces, SvFixedFooter, GatedNavigationEntry, PlatformRuleWorkspace; five primitives promoted to the registry | done | `component-registry-audit.json` |
| E | Tailored redesign of the 82 older pages (24 redesigned, 58 converged) to the UI-13/14/15 bar; UI-13/14/15 preserved (50) | done | `page-visual-acceptance.json`, `visual-debt-closure.json`, `screenshots/after/` (132) |
| F | GetStartedChecklist recomposition; Get Started heroes for merchant/branch/HR/finance | done | `SvUi16Primitives.spec.ts`, after captures |
| G | Responsive/accessibility/theme spec over all 160 rows | done — 307/307 | `responsive-accessibility-theme.json` |
| H | Visual-regression baselines (53 frames × win32 + linux) with a negative control | done — 54/54 per platform | `visual-baseline-index.json` |
| I | Full local acceptance + predecessor evidence restoration + handoff | see gate table in `docs/proof/ui-16.md` | `browser-proof.json`, `historical-evidence-*.json`, `claude-to-codex-handoff.*` |

## Decisions

- **No new route, permission, policy, migration, API operation or business rule.** OpenAPI stays
  307 paths / 358 operations; permission catalogue 169 / 134 active / 35 planned.
- **`sm:` → `md:` and `xl:` → `lg:`** remap scoped to class attributes (UI16-RESP-001), rather than
  re-enabling Tailwind's default screens: the token-owned breakpoints (ADR-021, CLAUDE.md §6.1) are
  the authority.
- **Account tone** (warm / green / teal) is provided once by `AppShell` via `PAGE_TONE_KEY`, so every
  `SvPageHeader` `h1` converges without per-page props; explicit `tone` still wins.
- **Footer reserve** tokens raised to the measured footer block (`footer-height-mobile` 7.25rem,
  `-desktop` 4rem) and the mobile footer wraps deterministically (UI16-FOOTER-001).
- **Gated navigation entries** remain inert (no `href`), now visibly marked with a lock badge and an
  "Unavailable" pill; the gate statement is the `aria-describedby` target with a `useId()` id.
- **Visual baselines are UI-16 candidates.** Agent-reviewed; product-owner acceptance is a separate,
  later decision. They are never mass-updated after a failure.
- **200% zoom** is exercised as a 640×400 CSS viewport (the equivalent reflow width), not by OS zoom.

## Deferred register (owner · entry condition)

| Item | Owner | Entry condition |
|---|---|---|
| Product-owner acceptance of the 106 candidate baselines and the 24 redesigns | Product owner | Review of `screenshots/after/` and the baseline frames |
| Screen-reader (NVDA/VoiceOver) pass | UI-17 / product owner | Assistive-technology environment available; not claimed here |
| Deployed-origin host smoke of the UI-16 build | UI-17 | Deployment of the merged UI-16 build (see `production-host-proof.json`) |
| Notifications (21N), Wallet/Gate-W pages | 21N / 20D-W / 21R-B | External Gate W open — the 28 gated rows stay `disabled_by_gate` |
| Performance budgets, bundle analysis, CSP/CORS, canary, rollback | UI-17 | UI-16 merged |

## Codex release continuation (2026-10-12)

The original Claude handoff remains immutable. `codex-closeout-addendum.json` records all owned
changes and the combined staging allowlist. Independent evidence/scope/reference/contract checks
passed; the original 106 images remain byte-identical. Codex added explicit §21.7 legal/form/state
and mobile-card comparisons, fixed the missing authentication footer, and made self-registration
CTA visibility use the canonical account flag. Detailed Bug Fix Protocol records and local failure
history are appended in `docs/proof/ui-16.md`; image decisions/hashes are recorded separately in
`codex-visual-baseline-index.json`. CI reports successful runs as well as failures, disables automatic
snapshot updates, and retains a bounded 120-minute E2E allowance.

Current release state: **CI_VERIFICATION_PENDING**. Docker's Linux-engine pipe is absent locally;
the added Linux frames must be captured on hosted runners, inspected individually, committed and
compared on a new exact final head. Five-job success, production-origin proof, real governance and
normal merge remain mandatory. Product-owner visual approval and independent human review are not
claimed. UI-17, Phase 25 and Gate W remain unstarted.
