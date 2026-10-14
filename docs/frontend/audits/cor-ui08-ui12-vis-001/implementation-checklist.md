# COR-UI08-UI12-VIS-001 — implementation checklist (durable resume log)

Corrective, product-owner-directed visual redesign of the 82 live UI-08 … UI-12 pages.
Branch `design/ui08-ui12-reference-grade-redesign`, base `562ffbbbbc10351a7185e60fda517d51a260f9db`
(UI-17 PR #68 merge, verified live). **Uncommitted handoff — Claude never stages, commits or pushes.**

## Waves

| Wave | Scope | Status |
|---|---|---|
| A | Reference inspection (6 images, hashes == UI-16), 82 before frames, refined tokens, experience scope, shell, nav, header, states, composition kit | done |
| B | Super Administrator — 17 live pages | done (first pass reviewed; platform-access overflow fixed) |
| C | Merchant Administrator — 15 live pages | done (first pass reviewed) |
| D | Branch 15 + Human Resource 15 | done (first pass reviewed) |
| E | Finance 20 | done (first pass reviewed; InvoiceList + AuditDomainEvents branch on the refined experience) |
| F | Integration: responsive/theme/a11y/perf audits, regression suites, baselines, evidence, handoff | done — see below |

### Wave F progress (2026-10-13)

- [x] Populated synthetic overlay `tests/e2e/support/corPopulated.ts` + `COR_CAPTURE=populated` (20 pages × 3 variants)
- [x] Full after capture (82 × 3) and review of light/dark/mobile sheets; fixes COR-DEF-001…004
- [x] Refined dialog/toast treatment (RC-08) + overlay baseline
- [x] Corrective visual spec (20 frames, scope guard, negative control) — win32 + linux baselines
- [x] UI-16: 38 frames of the five accounts re-baselined per platform (23 out-of-scope linux frames proven byte-identical); negative control repointed
- [x] Contract gates: tokens/nav/content/assets/source-inventory/api-contract/ui08…ui15 checkers/ui17 edge — all exit 0; lint 0 errors; typecheck + build exit 0; composer audit clean
- [x] Full Playwright run 1 (intermediate source): 2005 / 131 failed → every failure traced and fixed as COR-DEF-008…014; predecessor evidence restored by exact path (384 + 85, then 125)
- [x] Targeted re-run + UI-16 accessibility 132/132 + visual compare 107/107 on the final source
- [x] Populated axe sweep (82 pages × light 1440 + dark 360) added to the corrective spec — passing
- [x] Final static gates: lint 0 errors, typecheck, Vitest 1465/1465, build, ui17 bundle budget, npm audit 0, composer audit clean, 15 contract checks
- [x] After (246) and populated (66) frames re-captured on the final source; artifacts and review sheets regenerated
- [x] Final full Playwright on the final source: 2218 / 0 failed / 1 skipped; predecessor evidence restored (386 + 85)
- [x] Linux compare-only on the final source: 188 passed + 1 browser-session crash, re-run passed
- [x] test-matrix.json, proof, PROGRESS, CHANGELOG, NUL-safe handoff manifest + .md

**Phase state: local_complete — uncommitted handoff.** Exact next action belongs to Codex Prompt 2:
verify `claude-to-codex-handoff.json` (and the .md digest), stage exactly its paths, PR, five jobs.

## Architecture decisions (do not re-derive)

- **Scope switch:** `design-system/experience.ts` → AppShell sets `data-sv-experience="refined"` on
  `<html>` for exactly the five corrective accounts. Front Office, Personnel, Audit, public and auth
  never carry it.
- **Token overrides:** `styles/refined-experience.css` (all rules under
  `:root[data-sv-experience='refined']`) re-maps canvas, radii, shadows, nav-active colours, and
  restyles shell/nav/header/states/badges/tables/footer/buttons.
- **Composition kit:** `styles/refined-components.css` (`sv-r-*` classes, unscoped, used only by
  in-scope pages) + `components/refined/{SvPanel,SvKpiCard,SvFeaturePanel}.vue`.
- **New tokens (tokens.json):** palette `linen`, `linen-50`, `linen-200`, `night-950`, `night-900`;
  semantic `color-refined-*` (9); component `radius-panel/field/shell`, `shadow-panel/float`,
  `refined-sidebar-width`, `refined-shell-inset`; 10 contrast requirements (generator-verified).
- **Shared pages:** `pages/platform/AccountAndSecurity.vue` branches on `refined` (only the five
  accounts render the refined Profile hero + grid); `pages/get-started/RoleGetStarted.vue` still to do
  (shared with Front Office, Personnel, Audit — must branch the same way).
- **Iteration loop:** scratchpad `shot.ps1 -Only <prefix>` (vite build + capture spec);
  `sheets.mjs` makes contact sheets. Capture spec: `tests/e2e/cor-ui08-ui12-capture.spec.ts`
  (runs only with `COR_CAPTURE=before|after`).

## Exact next action

None for Claude: all six waves are done and the handoff manifest is written (see the end of this
file). Codex Prompt 2 owns staging, commit, PR and CI. If a later session must touch this branch,
regenerate the manifest after any edit — it hashes every path.
Trap: PowerShell double-quoted strings eat `${...}` template literals — splice code with single-quoted
here-strings or the Edit tool.

Notes: `refined-components.css` sits inside `@layer components` so utilities compose with it;
`RoleGetStarted` + `GetStartedChecklist` branch on `useRefinedExperience()`; `FirstTimeSetup`
calls `applyExperience('refined')` itself because it renders outside the shell.
