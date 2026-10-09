# REM-DEP-003 — Dependency security remediation

Status: `local_complete`, pending separate PR / exact-head CI / normal merge.
No independent approval or merge is claimed by this checked-in record.

Authority: product-owner continuation prompt dated 2026-10-08; Development Plan
§9 rule 18, §§75–76. Scope is dependency security and its compatibility consequences.
No permission, financial rule, business feature, legal copy or partner runtime changes are authorized.

## Repository preservation

The isolated worktree is `C:\Users\nderu\Documents\Development\Product\Servana-REM-DEP-003`
on `security/rem-dep-003-audit-remediation`, starting at UI-13 squash
`14cfadcb512d867d2aebefe0fa628fdee3563e02`, tree
`9d91b415c7ee12007f840faa4d88b82d5ac12be5`.

The original repository remains on `phase-ui-14-personnel-experience`, with 242
unstaged handoff paths and no implementation commit. Its authenticated manifest is
unchanged, SHA-256 `a059e89cd1c2d0f6aa49ddb0677db2250366c40a05413bd2fd894a6ea1432b4f`.
No stash, reset, clean, dirty-worktree checkout or original-container mutation was used.

## Bug Fix Protocol

**Observed problem:** required npm and Composer security audits fail against the
UI-13 dependency lockfiles and block UI-14 release.

**Evidence:** fresh Node 20.20.2 / npm 10.8.2 audit reports 13 high and 4 moderate
vulnerable-package entries, zero critical; exit 1. Composer lockfile audit reports
five advisories (two high, one medium, two low); exit 1. Windows Node 24 audit attempts
timed out and are not security results. The Node 20 report independently revalidates
the historical npm counts. Raw reports and command outcomes are retained under
`C:\Users\nderu\.codex\tmp\servana-rem-dep-003-20261008` during remediation.

**Affected files:** `package.json`, `package-lock.json`, `composer.lock`,
`postcss.config.js`, `tailwind.config.ts`, `resources/spa/src/{main.ts,style.css}`;
the new compatibility spec/fixture and this proof's audit/capture records.

**Root cause:** locked direct/transitive versions fall within published advisory
ranges. Tailwind 3.4.19 depends on micromatch 4.0.8 and chokidar 3.6.0, each reaching
`braces` 3.0.3; fast-glob is a second micromatch path. The registry's latest braces
is 3.0.3 and latest micromatch is 4.0.8, so a patched braces or compatible micromatch
resolution is unavailable. Tailwind's newest stable 3.x is 3.4.19 and retains
these dependencies. The retained non-forced `npm audit fix --package-lock-only`
report still has seven high and two moderate entries: braces, chokidar, micromatch,
fast-glob, Tailwind, sharp and brace-expansion remain blocking. It also leaves
postcss-selector-parser/postcss-nested moderate findings. No supported patched
braces version exists to select through a compatible transitive update.

The original diagnostic process exceeded its time limit and was stopped; its
complete retained report and lockfile diff are reviewed rather than claimed to
be a green gate. Explicit package updates supersede that trial. This evidence
exercises the product owner's conditional Tailwind 4 authorization.

**Why this is the root cause:** registry advisory bulk response and actual
lockfile dependency declarations agree. `npm audit fix --force` is not used as
evidence or as a remedy. Unsupported replacements under chokidar/micromatch are
not accepted as compatible updates.

**Correct fix:** targeted supported updates; exercise the conditional Tailwind 4
authorization only after documenting the remaining unpatched chain. Preserve the
semantic-token authority, account layouts, breakpoints, themes and approved images.

**Files changed:** the seven dependency/build consumers above, narrow progress/
changelog entries, and new proof/test records. No application service, controller,
policy, permission, migration, financial calculation or legal document changes.

**Tests added/updated:** one new 17-case build-compatibility spec and its synthetic
platform aggregate fixture, copied from the existing `PlatformDashboard.spec.ts`
response. Existing domain tests and shared-control assertions are unchanged.
Commands, outcomes and resolution evidence follow below.

**Remaining risk:** UI-14 remains blocked until both audits and every invalidated
compatibility, CI and lifecycle gate pass.

## Composer advisory matrix and resolution

| Advisory | Package / prior locked version | Severity | Chosen version |
|---|---|---|---|
| GHSA-jh5r-qr3c-85q8 | laravel/framework 12.62.0 | low | 12.69.3 |
| GHSA-97jj-33gv-5xf9 | league/commonmark 2.9.1 | medium | 2.10.3 |
| GHSA-3q6v-r5mr-hxv8 | league/commonmark 2.9.1 | high | 2.10.3 |
| GHSA-8rr7-cvq3-gmfh | league/commonmark 2.9.1 | high | 2.10.3 |
| GHSA-cxf4-7mrp-vvpr | league/flysystem 3.34.0 | low | 3.36.0 |

`composer update laravel/framework league/commonmark league/flysystem
--with-all-dependencies --minimal-changes --no-install --no-scripts --no-interaction
--prefer-dist` changes exactly those three packages, staying within Laravel 12
and the Composer PHP 8.3.31 platform constraint. `composer validate --strict`
and `composer audit --locked --format=json` both exit 0; the latter reports no
advisories or abandoned packages. Actual PHP 8.3.32 installation, strict validation
and lockfile audit also return exit 0 in the isolated runtime.

## Local validation (2026-10-08; PR acceptance pending)

- Final Node 20.20.2 / npm 10.8.2 `npm ci --no-fund`: exit 0.
- `npm audit --audit-level=high --json`: exit 0, zero at **every severity**;
  [npm-after.json](rem-dep-003/npm-after.json). The before report contains 23
  distinct advisory IDs across 24 package/advisory rows; full paths, ranges,
  parent constraints, reachability and remedies are in
  [advisory-matrix.json](rem-dep-003/advisory-matrix.json).
- ESLint: exit 0, zero errors, 487 warnings; no lint rule weakened.
- Typecheck and final production build: exit 0. The first v4 build's unresolved
  `./files/*.woff2` URLs were a real regression. Moving the existing five
  self-hosted font CSS imports to `main.ts` lets Vite rebase/emit them correctly;
  the repaired builds emit Inter/Manrope WOFF/WOFF2 assets without that warning.
  Browser checks load actual Inter/Manrope font faces and require loaded status.
- Full Vitest, `npm run test -- --pool=threads --maxWorkers=1`: **1,402 passed,
  145 files**, exit 0, no skipped cases or unhandled worker errors. Three earlier
  fork runs failed with test/worker timeouts: default (2 failed + 3 worker errors),
  two workers (1 failed + 2 worker errors), and one worker (2 failed + 1 worker
  error). The isolated affected three suites passed 105/105. The complete thread
  run retains the same assertions, isolation and 5-second test limit. An 8-GB
  host with approximately 729 MiB free was observed during failures; resource
  pressure is the supported environment explanation, not a claimed product fix.
  Existing jsdom connection-refused console diagnostics remain visible.
- PHP 8.3.32 lockfile installation, strict validation and audit: exit 0.
  Pint: 1,916 files clean. Standard parallel Larastan failed its 600-second child
  deadline; serial `vendor/bin/phpstan analyse --debug --memory-limit=1G` checks
  all 1,469 files at the same configured level 8 and returns exit 0/no errors.
- Focused PHP acceptance (`php artisan test tests/Feature/Auth
  tests/Feature/Isolation tests/Feature/DesignSystem tests/Feature/Api
  --fail-on-skipped`): exit 0, **413 passed / four existing placeholders skipped /
  3,053 assertions**, zero failures or warnings, 275.08 seconds. The four explicit
  skips remain in `FutureResourceIsolationTest.php` (historical Phase 16/17/18/19
  resource placeholders); they are not omitted or reported as passes. Actual
  implemented own-scope/tenant/auth tests pass. The first run's four readiness
  failures and 413 warnings were repaired by adding the disposable Redis 7 and
  `.env.example` runtime prerequisites. No original development service changed.
  The final CSS change additionally passes all 12 token-source guard cases
  (20 assertions).
- Tokens/content/navigation checks: current (160 pages / eight accounts).
  `assets:check` → `assets:generate` → `assets:check` all exit 0 on sharp 0.35.5:
  **32 selected / 192 derivatives**, **zero artifacts written**, six unchanged.
  Original artwork, selection, focal/crop policy and predecessor artifacts have
  no working-tree change. No re-encoding or derivative regeneration was needed.
- Secret scan: Git diff and every new remediation report/test/proof pass. An
  exploratory full-directory scan flags the unchanged public
  `DESIGN_TOKEN_SOURCE_SHA256` at `tokens.generated.ts:13`, already documented
  in `.gitleaksignore` with historical commit fingerprints. The Git-mode gate
  preserves those existing fingerprints; no exclusion or security rule added.
- New Playwright checks pass all 17 cases; the two platform cases additionally
  pass a focused rerun after replacing the stale aggregate fixture:
  eight implemented account surfaces × two themes × six widths
  (360/767/768/1024/1025/1440), computed responsive/pill/touch/shadow/blur/focus
  behavior, real fonts, axe and 96 captures. A separate case passes all **224**
  HTTP status/MIME/hash checks for 32 originals and 192 derivatives.
- Existing UI04 shared-control suite: **25 passed**, zero failed/flaky/skipped.
  This proves clean-browser light default under a dark OS, persisted theme,
  responsive controls, disabled/error surfaces, overlay focus/scroll behavior,
  axe in both themes, the manifest and no service worker. Eight capture rows
  (seven unique images) are retained separately in `shared-controls.json`.
  All 15 frozen UI04 predecessor hashes match after restoring only the nine
  actual test-written historical files (`predecessor-restoration.json`).
- Visual inspection covers all eight accounts at representative mobile/tablet/
  desktop widths in both themes, plus shared form/error/disabled surfaces.
  The existing Personnel appointments screen truthfully renders its empty
  own-scope state; its dashboard remains planned on the REM base. All other
  representative surfaces render supplied synthetic data. No UI14/UI15 feature
  is imported into this branch. Fixed footers, text wrapping and token colors
  remain visible; broader visual convergence retains its UI16 owner.
  `shell-captures.json`, `shared-controls.json` and `validation-results.json`
  retain the exact new capture digests, commands and outcomes.

The supported migration uses [Tailwind's upgrade guide](https://tailwindcss.com/docs/upgrade-guide)
and [CSS configuration directives](https://tailwindcss.com/docs/functions-and-directives).
`tailwind3-registry-proof.json` independently records the latest supported 3.x
and unpatched braces/micromatch parent constraints from the npm registry.
The current derivative hashes remain exactly the pre-upgrade hashes.

Two browser-harness errors were corrected without weakening assertions: an
unused `lg:flex` candidate was replaced with the production `md:flex/lg:block`
pair; focus measurements now use auto-retrying computed-style assertions while
the existing nonzero reduced-motion transition settles. Tailwind 4's native
`outline-none` also changed semantics: explicit utilities-layer rules preserve
the old transparent two-pixel outline for the actual focus/focus-visible variants.
The same-named `@utility` attempt failed and is retained as a failed attempt.
Visual inspection identified stale dashboard stub shapes; the existing phase
fixtures are reused, and the platform response matches the already-tested current
aggregate contract. No product dashboard code changed to accommodate fixtures.

The runtime mirror is a `git archive` of the REM base plus its explicitly listed
REM overlay, in a disposable Linux volume, with Git metadata mounted read-only.
It is not another registered worktree and contains none of the original dirty
UI-14 recovery snapshot. Windows bind-mount extraction timed out in the first
installer; Linux vendor/source volumes repair that environment. Installation
errors, interrupted diagnostics and failed validation attempts are retained as
failures, never counted as green.

## Release and deferred ownership

### First exact-head CI failure and narrow correction

PR [#64](https://github.com/ikrome002-design/servana/pull/64), head
`35ba851cb60c233cf0fcf9767f98a5bcbad0e237`, ran all five jobs in
[37832129849](https://github.com/ikrome002-design/servana/actions/runs/37832129849).
Security, Backend, Frontend and Docker succeeded; E2E failed with **1,529 passed /
14 failed**, in 34.6 minutes. This run is retained as a failure, not acceptance.
The successful backend reports 3,554 PostgreSQL passes / five existing skips /
51,865 assertions, plus 3 genuine ClamAV integration passes. Frontend reports
1,402 passes / 145 files, and both dependency audits report zero advisories.

**Observed problem:** three legacy success badges fail AA; eight theme checks
incorrectly report readable translucent white text as black.
**Evidence:** axe measures `#2e7d32` on the newly generated `/15` background
`#e0ebe0` at 4.18:1, below 4.5:1. The text checker reports actual computed
`oklab(... / 0.75)` as black because its parser only recognizes `rgb()/rgba()`.
**Affected files:** `BranchList.vue`, `ServiceCatalogue.vue`, `Compensation.vue`,
and `tests/e2e/phase-23-release-audit.spec.ts`.
**Root cause / why:** Tailwind 4 generates the legacy 15-percent tint, exposing
the unsafe border-token-as-text combination. CSS Color 4 output is valid browser
color data, but the old checker substitutes black for every unrecognized value.
**Correct fix / files changed:** the three active badges use the existing
`sv-success-bg` / `sv-success-fg` semantic pair. The checker asks the browser's
canvas color decoder for sRGB channels, includes alpha composition, recognizes
Color 4 gradient stops, and fails explicitly on an invalid color. No palette,
business rule, assertion threshold, axe rule, retry or timeout is weakened.
**Tests added/updated:** the existing unreadable-text guard gains a positive
Color 4 case and negative controls for transparent text and text matching its
background; the original failing cases and adjacent Front Office theme cases
are rerun unchanged.
**Test command:** `npx playwright test tests/e2e/catalogue-clients.spec.ts
tests/e2e/phase-20f.spec.ts tests/e2e/phase-23-release-audit.spec.ts
tests/e2e/ui-09-merchant-administrator-experience.spec.ts --grep
'lists services and gates|passes axe with zero|theme: (hr-dashboard|finance-dashboard|front-office-)|axe: (merchant-branches|service-catalogue)|branches has no serious|readable text guard'
--reporter=list`.
**Test result / proof of resolution:** **30 passed**, zero failed/flaky/skipped,
1.4 minutes; production build and focused ESLint both exit 0.
The full Vitest rerun passes **1,402 tests / 145 files**, no skipped cases or
worker errors, 421.55 seconds, using `--pool=threads --maxWorkers=1`. Fresh npm
and Composer locked audits again exit 0 with zero findings; both the correction
diff and new CI record pass secret scans. The read-only pre-upgrade bundle has no
`bg-success/15` rule, independently confirming the newly generated tint.
**Remaining risk:** replacement CI must pass all five jobs on the correction's
exact final head before governance or merge. The original UI-14 handoff remains
unmodified. Failed-run details are in `ci-run-37832129849.json`.

REM-DEP-003 PR, exact-head CI, governance, merge and UI-14 integration are pending.
UI-15/16/17 and Phase 25 have not started. External Gate W remains closed.
