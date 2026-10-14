# COR-UI08-UI12-VIS-001 proof — corrective reference-grade visual redesign of UI-08 … UI-12

Format: UI/UX plan §26 equivalent. Machine truth lives in `docs/frontend/audits/cor-ui08-ui12-vis-001/`;
this document points to it and records the measured results. **Every "after" image is
`agent-reviewed / pending product-owner visual acceptance`.** Nothing was staged, committed, pushed,
opened as a PR, run in hosted CI, merged or deployed.

## Phase and objective

Corrective phase `COR-UI08-UI12-VIS-001` — a product-owner-directed, material visual refresh of all
82 live pages of the Super Administrator (UI-08), Merchant Administrator (UI-09), Branch (UI-10),
Human Resource (UI-11) and Finance (UI-12) experiences, held to six binding reference images, with
the 24 gated pages kept inert. Not UI-18; UI-08 … UI-12 remain functionally complete.

## Governing requirements

UI/UX plan Appendix A §§5.4, 6.4, 7.4, 8.4, 9.4 (page contracts); §§4–9, 11–13, 17–19, 21–22,
24–26, 28 (cross-cutting); `docs/brand/Servana Brand Identity.md` (brand wins); `CLAUDE.md` §6
guardrails 1, 2, 6, 11, 14, 15, 16; ADR-017 (host/experience is never authorization), ADR-021
(light default), ADR-024 (fixed footer).

## Starting point

| Item | Value |
|---|---|
| Start | `main` = `origin/main` = `562ffbbbbc10351a7185e60fda517d51a260f9db`, clean, divergence `0 0` |
| Predecessor | UI-17 PR #68 merged (head `b17474a…`, CI `38353563570` five jobs green) — verified live once; reconciled in `docs/PROGRESS.md` |
| Branch | `design/ui08-ui12-reference-grade-redesign` (the only Git write) |
| References | six images, hashes identical to UI-16's record, re-inspected — `reference-inspection.json` |

## What changed

- **Experience scope** (`design-system/experience.ts`, `AppShell.vue`): `<html data-sv-experience="refined">`
  for the five accounts only; Front Office, Personnel, Audit, public, legal, FAQ and auth unchanged.
- **Tokens** (`tokens.json` → generated): linen/night canvases, refined semantic colours, radii,
  shadows; 10 new contrast requirements enforced by the generator.
- **Shell, navigation, header, states, badges, tables, buttons, footer, dialogs, toasts, motion**
  (`styles/refined-experience.css`) and the **composition kit** (`styles/refined-components.css`,
  `components/refined/*`).
- **82 page compositions** — `page-redesign-acceptance.json` (before issue, root-cause family, new
  composition, reference, rubric) and `visual-direction.md` (archetypes).
- **Defects closed:** COR-DEF-001 … 014 — `defect-closure.json`. 001–007 were found in visual review;
  008–014 were found by the first full Playwright run (contract labels, semantics the redesign had
  dropped, a mid-fade contrast artefact, theme-transition interpolation, 1025px/200% overflow) and
  fixed at their root before the final run.

## Contract and security parity

160 = 132 implemented + 28 gated + 0 + 0 (`nav:check`); corrective scope 106 = 82 + 24
(`page-inventory-and-status.json`). OpenAPI 307 paths / 358 operations (`check-api-contract.mjs`);
permission matrix unchanged (169 / 134 / 35). No path outside `resources/spa/`, `tests/e2e/`,
`docs/`. Details: `functional-security-parity.json`.

## Visual evidence

| Set | Frames | Where |
|---|---|---|
| Before (base source, default fixtures) | 82 desktop light 1440×900 | `screenshots/before/` |
| After | 82 × desktop light, desktop dark 1440×900, mobile light 360×800 = 246 | `screenshots/after/` |
| After, synthetic populated overlay | 22 pages × 3 | `screenshots/populated/` |
| Review comparison sheets | 16 (5 dashboards + 11 archetypes) | `review/`, `product-owner-visual-review.md` |
| Regression baselines | 20 new corrective + 38 refreshed UI-16 frames, per platform (win32 + linux) = 116 | `visual-baseline-index.json` |

All frames come from the deterministic UI-16 harness (pinned Nairobi clock, fixed identifiers,
synthetic privacy-safe identities); hashes are recomputed from disk into the index.

## Rubric

15 dimensions × 0–2, threshold ≥ 29 with dimensions 1–6 and 15 at 2. Result: 82 / 82 meet it
(73 at 30, 9 at 29 — the 1s are honest notes on raw-identifier dialog fields, raw ULID references and
a long mobile availability grid). This is the agent's judgement against before/after/populated
frames, never a substitute for the owner's review.

## Tests and gates

`test-matrix.json` records each command, exit code, duration and summary line against the final
working-tree digest. Final-source results:

| Gate | Result |
|---|---|
| Full Playwright (whole product, `--update-snapshots=none`) | **2,218 passed / 0 failed / 1 skipped / 0 flaky** (1.4 h) |
| UI-16 accessibility sweep (132 live pages, light 1440 + dark 360) | 132 / 132 — 0 serious / 0 critical |
| Corrective populated axe sweep (82 pages, light 1440 + dark 360) | 82 / 82 |
| Visual regression (UI-16 ×2 + corrective), win32, compare-only | 107 / 107, both negative controls fail as intended |
| Visual regression + populated axe, linux (`playwright:v1.62.0-noble`), compare-only, final source | 188 passed / 1 failed — the failure was a browser-session crash (`Protocol error … session closed`) before the page rendered, not a pixel difference; that test re-run alone in the same image: 1 / 1 passed |
| Vitest | 1,465 / 1,465 (153 files) |
| Lint · typecheck · build | 0 errors (3 pre-existing `v-html` warnings) · pass · pass |
| UI-17 bundle budget · edge security contract | pass · pass (CSP hash unchanged) |
| tokens / nav / content / assets / source-inventory / api-contract / ui08…ui15 checkers | 15 / 15 exit 0 |
| npm audit · composer audit | 0 vulnerabilities · no advisories |
| `git diff --check` | clean |

**Run history, stated plainly:** the first full run on intermediate source was 2,005 passed /
131 failed. Every failure was traced (COR-DEF-008 … 014) and fixed at its root; a targeted re-run
and the UI-16 sweep then passed, and the final full run above is on the exact handoff source. The
first run is recorded in `test-matrix.json` and is not claimed as a pass. Each full run's known
rewrite of predecessor evidence (386 tracked files + 85 untracked UI-01 screenshots) was restored
by exact path from HEAD; no predecessor file is part of the handoff.

## Residual risks

- Product-owner aesthetic acceptance is pending; the review package is ready.
- Before frames exist only with the default fixtures (no populated "before").
- Raw-identifier inputs in four dialogs/filters are the shipped API contract (no picker endpoint).
- `tokens.generated.ts` carries a regenerated digest that gitleaks will fingerprint (Codex).
- Production remains `blocked_external` (UI-17); Phase 25 and Gate-W work not started.

## Handoff

`claude-to-codex-handoff.json` / `.md` — NUL-safe path/status/size/SHA-256 inventory; manifest
digest in the `.md`.

## Codex release verification and COR-REL-002

PR #69 publishes one corrective code phase. Codex authenticated the frozen 651-path handoff,
reviewed six references and 36 before/after comparisons, and verified 510 frame hashes.
The original manifest remains unchanged; current Codex deltas are recorded independently in
`docs/frontend/audits/cor-ui08-ui12-vis-001/codex-release-addendum.json`.

First hosted run `38505857221`, head `6f25b07`, retained its real failure: Backend reported
1 failed / 5 skipped / 3,639 passed (53,025 assertions), with `UiIconSourceGuardTest` identifying
three date-separator glyphs. The local guard reproduced exactly 1 failed / 16 passed.
Root cause: a template-string range and two isolated screen-reader spans presented an arrow
as a standalone glyph to the line-based icon guard. Precise correction: use the explicit word
"to" for these date ranges; no icon-policy/test weakening, date/business-rule change or new library.
The unchanged icon guard then passed 17/17 (23 assertions). Only the populated Compensation
Detail evidence is visually invalidated; the two Subscription Dashboard separators are hidden.
Focused browser/unit/type checks and fresh five-job final-head CI are required before merge.

The five inherited Backend skips are one opt-in Phase 24 wall-clock benchmark
(`SERVANA_RUN_BENCHMARK=1`; performance owner) and four old `FutureResourceIsolationTest`
placeholders owned by Invoicing Phase 17, Payments Phase 18, Exports Phase 18/19 and Personnel
Phase 16. Their historical skip markers remain unchanged; this corrective visual phase introduces
no skipped security test and does not claim those placeholders ran. Current functional/isolation
coverage and the existing UI-15 export-security step remain part of hosted Backend validation.

Code release is pending. Product-owner aesthetic acceptance, manual screen-reader assessment,
UI-17 production prerequisites and Gate-W work remain separately owned and pending.

### COR-REL-003 — populated mobile term readability

Independent review of the fresh 360px Compensation Detail frame found values clipped by an
unconditional 15rem label column. The new negative text-fit test failed at 360px: 82px scroll
width against a 26px value column. The page now stacks labels and values below 768px, retains
the existing wider grid, and lets values wrap. Two light/dark tests assert all five values fit
at seven required widths plus 640×400 (200% equivalent), without raising any timeout.
The focused corrected-source run passed 5/5: one three-variant capture, both affected populated
axe cases and both readability matrices. Targeted lint, typecheck/build and four Subscription
Dashboard unit cases passed. The first local fork-worker startup timeout (zero tests/imports)
and initial concurrent-run axe timeout remain recorded in the release addendum; no test was
weakened or silently waived. The actual clipped frame is retained in `codex-defects/`; only
three populated after frames, their current index and the populated review-sheet column changed.
Historical UI-16 records and all regression goldens remain untouched by this correction.

### COR-REL-004 — wait for the required Personnel own-state read

First hosted run 38505857221 (head 6f25b07) finished E2E with 2,217 passed,
1 flaky retry and 83 capture-only skips. Salary’s section is mounted before the awaited
earnings overview completes; the original immediate request-array assertion therefore races
the subsequent own-salary GET. An 800ms overview delay reproduced exactly the premature
false assertion. The assertion now polls for the same exact API request within the unchanged
default timeout. Browser-error, failed-request and authorization assertions remain intact;
no Personnel product source, permission or route changed. A delayed-overview regression
case was added. The Windows repetition attempt is retained honestly: 6 passed / 4 failed
before the section rendered (status-only page), rather than a clean run. Independent Linux
repeat evidence: 20 passed / 0 failed / 0 retries (ten original and ten delayed cases; Node 20.19.5, Playwright 1.62.0, private software-rendering config). The earlier default-renderer Linux attempt was 16 passed / 4 failed: launch timeout, session-closed protocol failure and two pre-render readiness failures. No request predicate failed in the corrected runs; original timeouts and CI browser configuration remain unchanged. Final whole-product Linux CI remains mandatory.

Hosted CI’s 83 skips are the capture scope writer plus 82 opt-in page evidence writers
when COR_CAPTURE is unset. They are not product/security tests and do not replace any
functional gate. Their owner is the corrective visual evidence harness; entry is an explicit
COR_CAPTURE evidence-generation run. The local handoff’s different one-skip result remains
as originally reported, not substituted for the measured hosted count.

### COR-REL-005 — atomic button colors during theme changes

Run 38510382179 at head f745c14 passed its five jobs but E2E retained 2,219 passed /
1 flaky / 83 capture-only skips. The retry was UI-04 dark contrast, not the salary case.
The root class switches immediately while SvButton interpolates semantic foreground and
background tokens independently for 150ms. A controlled 2s transition probe identified the
Destructive label at ratio 1.14 (#8a9095 over #eb4d4d); the settled theme had zero violations.
The new browser regression failed on the old bundle with 14 active color transitions.
Precise product correction: SvButton uses transition-shadow, so foreground/background
pairs change atomically while shadow feedback remains animated. The existing axe checks
and thresholds stay intact; failure messages now expose exact node details. The added
slow-transition regression rejects active button color interpolation and runs full axe.
No timeout, retry, disabled rule, baseline or permission changed. Ordinary pre-fix dark
axe repetitions passed 50/50, demonstrating why an isolated retry is not root-cause proof.
Corrected-source focused result: 26 full UI04 cases + 30 repeated light/dark/slow-transition cases passed; 0 failures / 0 retries. Node20.19.5, Playwright1.62.0, private software-rendering diagnostic config; hosted defaults unchanged..
