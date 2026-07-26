# REM-DEP-002 — npm audit high-severity remediation

**Branch:** `remediation/rem-dep-002-npm-audit`
**Base commit:** `d8a7a15603c22e41354e570f4d2735935468d973` (origin/main — Phase 21S merge, PR #45)
**Owner:** dependency remediation (not a Plan §80 feature phase)
**Blocks:** the Phase 22 PR's Frontend CI job (`.github/workflows/ci.yml:184`)
**Status at authoring:** local_complete pending PR CI/review/merge

This item is a **frontend dependency-toolchain remediation only**. No backend code,
no Phase 22 search code, no route, no permission key, no policy, no migration and
no generated API contract changed. The OpenAPI surface is byte-identical
(242 paths / 288 operations, unchanged).

---

## Bug Fix Protocol

### Observed problem

`npm audit --audit-level=high` exits 1 with **15 high-severity findings**. CI runs
this exact command as the last step of the Frontend job
(`.github/workflows/ci.yml:183-184`), so every PR opened from a branch based on
`main` — including the pending Phase 22 PR — fails the Frontend check for a reason
unrelated to the phase under review.

### Evidence

Reproduced on a clean branch cut from `origin/main` (`d8a7a15`):

```
$ npm audit --audit-level=high
15 high severity vulnerabilities
EXITCODE=1
```

Machine-readable capture: `storage/app/local-rem-dep-002-npm-audit-before.json`
(local artifact, untracked). `npm audit fix --dry-run --json` capture:
`storage/app/local-rem-dep-002-npm-audit-fix-dry-run.json`.

Parsing the JSON shows the 15 findings are **not 15 independent defects**. There
are exactly **two published advisories**; the other 13 entries are npm's transitive
"depends on a vulnerable version" propagation:

| Advisory | Package | Vulnerable range | Severity |
|---|---|---|---|
| [GHSA-mh99-v99m-4gvg](https://github.com/advisories/GHSA-mh99-v99m-4gvg) | `brace-expansion` | `<=5.0.7` | high |
| [GHSA-r28c-9q8g-f849](https://github.com/advisories/GHSA-r28c-9q8g-f849) | `postcss` | `<=8.5.17` | high |

Propagated entries (no advisory of their own): `minimatch`, `@eslint/config-array`,
`@eslint/eslintrc`, `eslint`, `eslint-plugin-vue`, `@redocly/openapi-core`,
`openapi-typescript`, `@vue/language-core`, `vue-tsc`, `editorconfig`,
`js-beautify`, `@vue/test-utils`, `glob`.

**Exposure classification.** `npm audit --json` metadata reports
`prod: 56, dev: 380, optional: 35`. Every affected package resolves in the **dev**
tree (`"dev": true` on all six `brace-expansion` instances and on `postcss` in
`package-lock.json`). The four production dependencies are `axios`, `pinia`, `vue`
and `vue-router`; none of them appears in either advisory chain. `postcss` is
build-time only (Tailwind/Vite pipeline) and is not shipped in the SPA bundle.
**Production runtime exposure: nil.** The finding is nevertheless a genuine CI gate
failure and is remediated rather than suppressed.

### Affected files

- `package.json` — devDependency ranges + `overrides`
- `package-lock.json` — resolved tree
- `eslint.config.js` — flat-config globals (consequence of the ESLint upgrade)
- `resources/spa/src/pages/platform/BillingSettings.vue` — one statement (consequence of a rule promoted to `recommended` in ESLint 10)

### Root cause

Two independent causes.

**Cause 1 — `postcss` (trivial).** The declared range `^8.4.47` already admits the
patched release; the lockfile was simply pinned to `8.5.15`, below the `8.5.18`
fix line. A lockfile refresh resolves it.

**Cause 2 — `brace-expansion` (the real problem).** The advisory range is
`<=5.0.7`, which spans *every* major line. Checking the registry, the only patched
release is **`5.0.8`**; there is no backport to the `1.x` or `2.x` lines
(`1.1.16` and `2.1.2` are the newest in those lines and both fall inside the
advisory range). `brace-expansion` is pulled in exclusively by `minimatch`:

| minimatch instance | requires | resolved `brace-expansion` |
|---|---|---|
| `node_modules/minimatch` 3.1.5 (ESLint 9) | `^1.1.7` | 1.1.16 |
| `@redocly/openapi-core/…/minimatch` 5.1.9 | `^2.0.1` | 2.1.2 |
| `@vue/language-core/…/minimatch` 9.0.9 | `^2.0.2` | 2.1.2 |
| `editorconfig/…/minimatch` 9.0.9 | `^2.0.2` | 2.1.2 |
| `glob/…/minimatch` 9.0.9 | `^2.0.2` | 2.1.2 |
| `@typescript-eslint/…/minimatch` 10.2.5 | `^5.0.5` | 5.0.7 |

The patched `brace-expansion@5.0.8` is only reachable from `minimatch >= 10.0.3`
(`minimatch` is itself flagged across `2.0.0 - 10.0.2`). So every consumer must be
moved onto `minimatch@10`.

### Why this is the root cause

Directly verified rather than inferred:

1. **Only `5.0.8` is patched.** `npm view brace-expansion versions` shows the
   version list ends `… 4.0.1, 5.0.2 … 5.0.7, 5.0.8`; no `1.1.17` / `2.1.3`.
2. **A blanket `brace-expansion` override is unsafe.** `brace-expansion@5` is a
   dual CJS/ESM package whose CJS entry exports an **object**, not the function
   that `minimatch@1`/`@2` consumers call:
   ```
   $ node -e "const be=require('brace-expansion'); console.log(typeof be, Object.keys(be))"
   object [ 'EXPANSION_MAX', 'EXPANSION_MAX_LENGTH', 'expand' ]
   ```
   Forcing it under `minimatch@3`/`@5`/`@9` would produce `expand is not a function`.
   The fix therefore has to happen at the `minimatch` layer, not the
   `brace-expansion` layer.
3. **A blanket `minimatch@10` override is also unsafe.** `minimatch@10`'s CJS entry
   is likewise an object (`typeof require('minimatch') === 'object'`), so consumers
   calling the module itself break. Grepping the actual consumers separates them:

   | Consumer | Import style | Safe on `minimatch@10`? |
   |---|---|---|
   | `glob/dist/commonjs/*.js` | `minimatch_1.Minimatch`, `.escape`, `.GLOBSTAR` | yes (named) |
   | `editorconfig/lib/index.js:176` | `new minimatch_1.Minimatch(...)` | yes (named) |
   | `@vue/language-core/…/elementProps.js` | `(0, minimatch_1.minimatch)(...)` | yes (named) |
   | `@redocly/openapi-core/lib/utils.js:106` | `minimatch(url, pattern)` | **no** (calls the module) |
   | `eslint/lib/…/eslint-helpers.js:30` | `minimatch.Minimatch` | yes (named) |
   | `@eslint/eslintrc/lib/override-tester.js` | `import minimatch from …` | **no** (default import) |

   This is why the remediation splits into *scoped overrides* where the consumer
   uses named exports, and a *real upgrade* where it does not.
4. **ESLint 9 cannot be cleared by an override.** It depends on `minimatch@^3.1.5`
   and on `@eslint/eslintrc`, which default-imports `minimatch`. `eslint@10.8.0`
   drops `@eslint/eslintrc` entirely and moves to `minimatch@^10.2.5` +
   `@eslint/config-array@^0.23.5`. npm's own `fixAvailable` for this chain is
   `eslint@10.8.0 (isSemVerMajor: true)` — independently corroborating that a
   semver-major lint-toolchain upgrade is genuinely required, exactly as the
   REM-DEP-002 checkpoint predicted.

### Correct fix

Smallest change that clears every finding while keeping the toolchain working.

**A. Real upgrade only where an override is provably unsafe — the ESLint stack.**

| Package | Before | After | Why |
|---|---|---|---|
| `eslint` | `^9.13.0` | `^10.8.0` | only way off `minimatch@3` + `@eslint/eslintrc` |
| `@eslint/js` | `^9.13.0` | `^10.0.1` | peer-locked to `eslint@^10` |
| `eslint-plugin-vue` | `^9.30.0` | `^10.10.0` | v9 does not support ESLint 10 |
| `typescript-eslint` | `^8.12.0` | `^8.65.0` | first line declaring `eslint ^10` support |
| `vue-eslint-parser` | *(transitive)* | `^10.4.1` | v10 of the plugin makes it a **peer**, so it must be declared |
| `globals` | *(transitive 14.0.0)* | `^17.7.0` | see “consequential edits” below |

**B. Scoped `minimatch` overrides where the consumer uses named exports.** These
avoid four further major upgrades (`vue-tsc`, `openapi-typescript`,
`@vue/test-utils`, `glob`) that would otherwise be forced:

```json
"overrides": {
  "js-yaml@4.0.0 - 4.2.0": "^4.3.0",
  "@redocly/openapi-core": { "minimatch": "^10.2.5" },
  "@vue/language-core":    { "minimatch": "^10.2.5" },
  "editorconfig":          { "minimatch": "^10.2.5" },
  "glob":                  { "minimatch": "^10.2.5" }
}
```

**C. `postcss`** — no `package.json` change; the lockfile refresh moves
`8.5.15 → 8.5.23` inside the existing `^8.4.47` range.

### Alternatives rejected (with evidence)

- **`npm audit fix --force`** — rejected. Its plan **downgrades** contract-critical
  tooling: `openapi-typescript@6.7.6` (from 7.4.4) and `@vue/test-utils@2.4.0`
  (from 2.4.11). `openapi-typescript@6` emits a different type shape and would
  churn the committed generated API types.
- **Override `@redocly/openapi-core` to `^2.40.0`** (v2 drops `minimatch` for
  `picomatch`) — **tried and reverted**. It breaks every `openapi-typescript@7.x`:
  ```
  Error [ERR_PACKAGE_PATH_NOT_EXPORTED]: Package subpath './lib/ref-utils.js'
  is not defined by "exports" in .../@redocly/openapi-core/package.json
  imported from .../openapi-typescript/dist/lib/utils.mjs
  ```
  Confirmed against both `openapi-typescript@7.4.4` and the newest `7.13.0`; all
  `7.x` releases declare `@redocly/openapi-core: ^1.34.6` and import v1 subpaths.
  `openapi-typescript` was therefore left **unchanged at its exact pin `7.4.4`**.
- **Upgrade `vue-tsc` 2 → 3** — **tried and reverted**. It cleared the chain but
  `vue-tsc@3` reports a new error in Phase 21S code
  (`ClientSms.vue(34,7): TS6133 'statusRegion' is declared but its value is never read`,
  a template-only ref). Since `@vue/language-core@2` uses minimatch's *named*
  export, the scoped override achieves the same audit result with **zero** feature-code
  change, so `vue-tsc` stays at `^2.1.0`.

### Residual reachability note (`@redocly/openapi-core`)

`@redocly/openapi-core@1.34.17` is the one consumer left on a `minimatch` call
style incompatible with v10. Its single call site is:

```js
// lib/utils.js
async function readFileFromUrl(url, config) {
  for (const header of config.headers) {
    if (match(url, header.matches)) { ... }   // -> minimatch(url, pattern)
  }
```

`match()` is reachable **only** when the bundler fetches a **remote HTTP `$ref`**
and applies configured per-URL headers. In this repository:

- `docs/api/openapi.json` contains **0** remote (`http(s)://`) `$ref`s — verified by grep;
- there is **no `redocly.yaml`** at the repository root, so `config.headers` is empty.

The path is therefore unreachable in Servana's usage, and `npm run api:contract:check`
exercises the real generation path end-to-end (passes, below). If it were ever
reached it fails **loudly** (`TypeError: minimatch is not a function`) rather than
matching incorrectly. Recorded as a monitored residual risk.

### Consequential edits (not optional, not scope creep)

**1. `eslint.config.js` — browser globals.** `eslint-plugin-vue@9` injected
`globals.browser` implicitly from its flat base config
(`lib/configs/flat/base.js`: `globals: globals.browser`, on an entry with **no**
`files` restriction). v10 removed that and no longer depends on `globals`, which
produced **74 `no-undef` errors** for `document`, `window`, `HTMLElement`,
`setTimeout`, … The fix restores exactly the previous semantics, now declared
explicitly:

```js
{
  languageOptions: { globals: { ...globals.browser } },
},
```

This is a restoration, not a relaxation: `no-undef` remains enabled everywhere.

**2. `BillingSettings.vue` — one statement.** ESLint 10 promotes
`no-useless-assignment` into `js.configs.recommended`. It correctly flagged
`let next = index;` in `onKeydown`, where the initialiser is dead — every branch
either reassigns `next` or returns. Changed to `let next: number;`; TypeScript's
definite-assignment analysis covers the `else return`. Behaviour is unchanged.

### Files changed

```
package.json
package-lock.json
eslint.config.js
resources/spa/src/pages/platform/BillingSettings.vue
docs/proof/rem-dep-002.md          (new)
docs/remediation/register.yaml
docs/PROGRESS.md
docs/CHANGELOG.md
```

### Lockfile refresh — full disclosure

npm could not perform the `eslint-plugin-vue` 9 → 10 transition in place; it kept
resolving the locked 9.33.0 and failed with `ERESOLVE` even after `node_modules`
was removed. `package-lock.json` was therefore regenerated from `package.json`.
Consequence: packages with **floating ranges already declared in `package.json`**
re-resolved to current releases. **No `package.json` range was widened for any of
them.** Complete list of resolved-version movement:

| Package | Baseline | Remediated | Cause |
|---|---|---|---|
| `eslint` | 9.39.4 | 10.8.0 | intended |
| `@eslint/js` | 9.39.4 | 10.0.1 | intended |
| `eslint-plugin-vue` | 9.33.0 | 10.10.0 | intended |
| `typescript-eslint` | 8.61.0 | 8.65.0 | intended |
| `vue-eslint-parser` | 9.4.3 | 10.4.1 | intended (now a declared peer) |
| `globals` | 14.0.0 (transitive) | 17.7.0 (direct) | intended |
| `postcss` | 8.5.15 | 8.5.23 | intended (advisory fix) |
| `@axe-core/playwright` | 4.11.3 | 4.12.1 | lock refresh, within `^4.11.3` |
| `@playwright/test` | 1.60.0 | 1.62.0 | lock refresh, within `^1.60.0` |
| `@types/node` | 22.19.21 | 22.20.1 | lock refresh, within `^22.8.0` |
| `@vitejs/plugin-vue` | 6.0.7 | 6.0.8 | lock refresh, within `^6.0.7` |
| `@fontsource/inter` | 5.2.8 | 5.3.0 | lock refresh, within `^5.1.0` |
| `@fontsource/manrope` | 5.2.8 | 5.3.0 | lock refresh, within `^5.1.0` |
| `autoprefixer` | 10.5.0 | 10.5.4 | lock refresh, within `^10.4.20` |
| `vite` | 8.0.16 | 8.1.5 | lock refresh, within `^8.0.16` |
| `vitest` | 4.1.8 | 4.1.10 | lock refresh, within `^4.1.8` |
| `vue` | 3.5.38 | 3.5.40 | lock refresh, within `^3.5.0` |

Unchanged and explicitly verified: `openapi-typescript` 7.4.4, `vue-tsc` 2.2.12,
`@vue/test-utils` 2.4.11, `tailwindcss` 3.4.19, `typescript` 5.9.3, `axios` 1.18.1,
`pinia` 2.3.1, `vue-router` 4.6.4, `jsdom` 25.0.1. Locked package count 436 → 411.

Every refreshed package is covered by the gates below (Vitest 501, Playwright 453,
build, typecheck) — all of which match the pre-remediation baseline exactly.

---

## Verification

### npm audit — after

```
$ npm audit --audit-level=high
found 0 vulnerabilities
AUDIT EXIT=0

$ npm audit                      # all severities, not just high
found 0 vulnerabilities
```

Resolved leaf versions confirming the chains are closed — a single hoisted
instance of each:

```
node_modules/brace-expansion   5.0.8      (was 1.1.16 / 2.1.2 x4 / 5.0.7)
node_modules/minimatch         10.2.5     (was 3.1.5 / 5.1.9 / 9.0.9 x3 / 10.2.5)
node_modules/postcss           8.5.23     (was 8.5.15)
node_modules/@eslint/config-array 0.23.5  (was <=0.22.0)
node_modules/@eslint/eslintrc  (removed — ESLint 10 no longer depends on it)
```

### Gate results

| Gate | Command | Result |
|---|---|---|
| npm audit | `npm audit --audit-level=high` | **PASS** — 0 vulnerabilities (exit 0) |
| ESLint | `npm run lint` | **PASS** — 0 errors, 138 warnings (exit 0) |
| Type-check | `npm run typecheck` | **PASS** — `vue-tsc --noEmit`, 0 errors |
| API contract | `npm run api:contract:check` | **PASS** — `OK — 242 paths, 288 operations` |
| Vitest | `npm run test` | **PASS** — 97 files, **501 passed**, 0 failed |
| Build | `npm run build` | **PASS** — built in 17.82s |
| Playwright E2E | `npm run e2e` | **PASS** — **453 passed** (7.7m), axe 0 |
| gitleaks | `gitleaks detect --source . --no-git --redact` | **PASS** — `no leaks found` (22.99 MB scanned) |
| Whitespace | `git diff --check` | **PASS** — clean (exit 0) |
| Composer manifest | `composer validate --strict` | **PASS** — `./composer.json is valid` |
| Pint | `composer pint -- --test` | **PASS** — 1611 files |
| Larastan | `composer stan` | **PASS** — level 8, 0 errors (1257 files) |
| Backend suite | `php artisan test` | **24 failed / 7 skipped / 1982 passed** — **pre-existing on `main`, not caused by this change**; see §Backend below |

### ESLint warning parity (proof of no lint regression)

The 138 remaining warnings are **pre-existing**, not introduced by the ESLint 10
upgrade. Proven by running the *baseline* toolchain (`origin/main`, ESLint 9) in a
throwaway git worktree with `npm ci`:

| Rule | Baseline (ESLint 9) | Remediated (ESLint 10) |
|---|---|---|
| `vue/html-indent` | 101 | 101 |
| `vue/singleline-html-element-content-newline` | 26 | 26 |
| `vue/max-attributes-per-line` | 4 | 4 |
| `vue/require-default-prop` | 4 | 4 |
| `vue/no-v-html` | 2 | 2 |
| `vue/html-self-closing` | 1 | 1 |
| **errors** | **0** | **0** |
| **warnings** | **138** | **138** |

Identical rule-for-rule. The upgrade adds no new warning and no new error, and the
worktree was removed after measurement.

### Backend — 24 pre-existing failures, reported not hidden

Backend gates were run only to prove no collateral damage, in the running dev stack
(`docker compose exec -T app`, PHP 8.3, PostgreSQL 16). `composer validate --strict`,
Pint (1611 files) and Larastan level 8 (1257 files, 0 errors) all pass. The full
suite does **not**:

```
Tests:    24 failed, 7 skipped, 1982 passed (12300 assertions)
Duration: 1747.30s
```

**These failures are not caused by this remediation, and this is proven, not asserted:**

1. **No backend file is in the diff.** `git diff origin/main --name-only` filtered to
   `app|routes|database|config|tests|composer` returns **nothing**. The four changed
   code files are `package.json`, `package-lock.json`, `eslint.config.js` and one
   `.vue` component. No PHP, no migration, no test, no `composer.json`/`composer.lock`.
   Identical backend code against the same database therefore produces identical
   behaviour.
2. **Empirically confirmed against a pristine tree.** The working tree was stashed
   (`git status` → 0 entries, `git diff origin/main --name-only` → 0 files, i.e. the
   checkout was byte-identical to `origin/main`) and the failing file re-run:

   | Tree | `tests/Feature/ServiceSession/ServiceSessionCouplingTest.php` |
   |---|---|
   | remediation branch | 6 failed, 1 passed |
   | pristine `origin/main` | **6 failed, 1 passed** (identical) |

   The stash was then restored and the working tree verified intact.

**Proximate cause of the failures** (diagnosed for accurate reporting; **not fixed
here** — fixing backend behaviour is outside REM-DEP-002's scope and would violate
the one-concern rule for this branch). The shared helper at `tests/Pest.php:444`
creates a walk-in and immediately calls `POST /api/v1/queue-entries/{ulid}/call`,
expecting 200. `QueueEntryStatus::allowedTransitions()` is:

```php
self::Waiting  => [self::Assigned, self::Transferred, self::Cancelled, self::NoShow],
self::Assigned => [self::Called, ...],
```

`waiting → called` is **forbidden by design** — an entry must be *assigned* first.
The helper therefore only works when the new walk-in is auto-assigned to eligible
personnel; when it is not, the entry stays `waiting` and `/call` correctly returns
422 `A queue entry cannot move from waiting to called.` The failures cluster in
queue/service-session tests, and Phase 21S recorded this same suite at
**2006 passed / 0 failed** a few days ago on unchanged code, so the trigger is
environmental rather than a code regression. The most likely trigger is a
**time-of-day dependency** in personnel availability — these runs executed at
20:00–21:00 UTC, i.e. 23:00–00:00 in `Africa/Nairobi`, at the edge of the business
day. That last point is a **hypothesis**, stated as such; what is *established* is
that the behaviour is identical with and without this change.

**This is flagged for separate investigation, not carried by this branch.** It does
not affect the Frontend CI job that REM-DEP-002 exists to unblock, and it will fail
or pass on the Phase 22 PR exactly as it would have without this remediation.

### Proof of resolution

0. Scope caveat first: the backend suite has **24 pre-existing failures** on this
   machine, identical on unmodified `origin/main` (see §Backend). They are reported,
   not hidden, and are not remediated here.
1. `npm audit --audit-level=high` — the exact command in `ci.yml:184` — exits **0**.
2. The CI Frontend job's full sequence (`npm ci` → lint → typecheck →
   `api:contract:check` → test → build → audit) passes locally end-to-end.
3. `npm ci` was exercised against the regenerated lockfile and installed cleanly.
4. Vitest (501) and Playwright (453) match the pre-remediation Phase 21S baseline
   exactly — no test was changed, skipped, or weakened.
5. The API contract is byte-identical (242 paths / 288 operations).
6. No CI configuration was modified: `.github/workflows/ci.yml` is **not** in the
   diff. The audit step, its `--audit-level=high` threshold, and every other gate
   remain exactly as they were.

---

## Remaining risk

1. **`@redocly/openapi-core@1.34.17` on `minimatch@10`** — the `readFileFromUrl`
   header-matching path would throw if it were ever reached. Proven unreachable
   here (0 remote `$ref`s, no `redocly.yaml`); fails loudly rather than silently if
   that changes. **Monitor:** if a `redocly.yaml` with `resolve.http.headers` is
   ever introduced, or a remote `$ref` is added to `docs/api/openapi.json`, drop
   the `@redocly/openapi-core` override and revisit. The permanent fix is upstream
   support for `@redocly/openapi-core@2` in `openapi-typescript`.
2. **ESLint 10 rule surface.** The upgrade is validated by exact
   error/warning parity against the ESLint 9 baseline, but future rule promotions
   in the 10.x line may surface new findings on unrelated code.
3. **Lockfile refresh breadth.** Nine packages moved within their existing declared
   ranges as a side effect of regeneration. All are covered by the green gates
   above; none required a `package.json` change.
4. **`js-beautify` → `glob@10.5.0`** remains on a deprecated `glob` line (npm
   prints a deprecation notice, not an advisory). It is audit-clean via the scoped
   `minimatch` override. Closing it fully depends on `@vue/test-utils` dropping
   `js-beautify` upstream.
5. **Local Node version drift.** Gates ran on Node v24.15.0 / npm 11.12.1 while
   `package.json` pins `node >=20.0.0 <21` and CI uses Node 20 (`npm warn EBADENGINE`).
   Every upgraded package supports Node 20 (`eslint@10` requires
   `^20.19.0 || ^22.13.0 || >=24`; `brace-expansion@5.0.8` requires `20 || >=22`),
   and `actions/setup-node@v4` with `node-version: '20'` resolves to 20.19+.
   CI on Node 20 is the authoritative confirmation.

## Solo-Maintainer Review Exception - PR #46

- PR: #46
- remediation: REM-DEP-002
- implementation head: 13feb2bfe55a057d7a4082d386e6686963fd230c
- successful initial CI run: 30195126975
- initial Backend check: passed
- initial Frontend check: passed
- initial Docker check: passed
- initial Security check: passed
- initial E2E check: passed
- GitHub reviewDecision: intentionally blank
- governance record: docs/governance/solo-maintainer-review-exception-pr-46.md

This exception applies only to REM-DEP-002 and is not independent reviewer
approval.

A governance-only commit will add this evidence. All required checks must pass
again on that governance commit before PR #46 is merged.

The Phase 22 branch remains preserved at:

edff8c059671b551eec1e6f9617ea3ae6add0d7b

The Phase 22 PR remains unopened until REM-DEP-002 is merged and verified.
