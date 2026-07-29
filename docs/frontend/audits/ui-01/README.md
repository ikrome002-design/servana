# Phase UI-01 — as-built browser and repository audit

**Status:** local_complete pending PR CI/review/merge
**Branch:** `phase-ui-01-as-built-browser-audit`
**Base:** `d3f6e10c1ff9490bc558199940f76fbec9497272` (Phase UI-00, PR #50, merged)
**Proof:** [`docs/proof/ui-01.md`](../../../proof/ui-01.md)

UI-01 proves what the browser actually renders today and reconciles every browser claim with the
repository state that produced it. It is an **evidence and classification phase**. It repairs
nothing: every product defect it finds stays open and is assigned to a later phase.

---

## 1. What this directory contains

| Artifact | Contents |
|---|---|
| `audit-manifest.json` | SHA-256 of every artifact, every evidence input and every source input |
| `served-build-provenance.json` | commit → tree → Docker image → Vite manifest → emitted asset → browser-loaded asset chain |
| `route-component-page-audit.json` | router records, page/layout components, the 160-page contract, and every implementation claim classified |
| `navigation-role-audit.json` | navigation registry vs YAML fixture vs router vs rendered browser, per account |
| `theme-asset-legal-audit.json` | theme bootstrap behaviour, brand assets, landing images, role legal/FAQ source-to-render mapping |
| `baseline-screenshot-manifest.json` | every expected screenshot, its provenance and its SHA-256 |
| `defect-register.csv` | every defect found, with owner phase and future acceptance test |

Raw evidence lives under [`docs/proof/ui-01/`](../../../proof/ui-01/): sanitized network/environment
capture in `network/`, baseline images in `screenshots/`.

## 2. How to reproduce

Run these in order, on one machine, with nothing else heavy running. Playwright must never run
beside a Docker build or the backend parallel suite — resource contention produces false failures.

```bash
npm ci && npm run build
```

```bash
docker build -f docker/nginx.Dockerfile --target prod -t servana-ui01-nginx:audit .
```

```bash
docker build -f docker/php.Dockerfile --target prod -t servana-ui01-php:audit .
```

```bash
docker run -d --name ui01-nginx-probe --network servana_default -p 8099:8080 servana-ui01-nginx:audit
```

```bash
npx vite preview --port 4173
```

```bash
npx playwright test tests/e2e/ui-01-as-built-audit.spec.ts
```

```bash
UI01_PROD_IMAGE=servana-ui01-nginx:audit UI01_PROD_PHP_IMAGE=servana-ui01-php:audit UI01_ORIGIN=http://localhost:8099 node scripts/audit-ui-as-built.mjs --capture
```

```bash
node scripts/audit-ui-as-built.mjs && node scripts/audit-ui-as-built.mjs --check
```

The collector separates **deterministic** from **volatile** work on purpose. `--capture` performs
the one-time host collection (git, Docker, HTTP probes) and writes it to a version-controlled
evidence file. The default pass reads that file and regenerates the artifacts; a second pass
produces no diff, which is what `--check` enforces. Nothing volatile is re-read during `--check`.

## 3. The two origins, which are never conflated

| Origin | What it is | What it proves |
|---|---|---|
| **preview** — `http://localhost:4173` | `vite preview` serving `public/spa` **as the web root**, with the API fully stubbed by `tests/e2e/support/releaseAudit.ts` | Frontend behaviour: routing, layout, role shells, theme, accessibility. This is the origin every existing e2e suite has always used. |
| **served** — `http://localhost:8099` | the real production nginx image, where Laravel owns `/` and the SPA is mounted under `/spa/` | What a user would actually receive from a deployment |

The distinction is load-bearing. An assertion proven on `preview` is a statement about frontend
code, **not** about the deployed product. UI-01 records both and labels every screenshot and route
visit with the origin that produced it.

Two consequences follow, and they are recorded as defects rather than smoothed over:

- No existing browser evidence in this repository — including the 846 passing Playwright tests —
  has ever exercised the served origin.
- Role resolution has only ever been proven against a **stubbed** `/me`. UI-01 did not invent
  production credentials or create real accounts to change that; it used the repository's approved
  fixture helpers and recorded the limitation.

## 4. Page-claim classification contract

Every page currently claimed as implemented by `docs/frontend/screens/inventory.json` receives
exactly one classification.

| Classification | Meaning |
|---|---|
| `true` | Router registration exists, the component import resolves, the component renders without a runtime error, the correct account reaches it through a supported flow, the browser route matches the claimed route, the screen specification describes the same page, and the page is substantive rather than an empty generic placeholder. |
| `false` | The repository claims implementation, but at least one required condition above is absent or materially wrong. |
| `unreachable` | Relevant code exists, but no supported router, navigation, authentication, account-context or redirect flow reached it in this audit. Reachability is **unproven**, not disproven. |
| `stale` | The claim points at deleted, renamed, superseded or orphaned code and no longer describes the current browser. |
| `not_claimed` | The inventory itself does not claim implementation (`status` is `phase_11` or `planned`). Absence of a claim is never reported as `false`. |

The **required 160-page contract** is a separate register and uses its own vocabulary:

| Required status | Meaning |
|---|---|
| `claimed_by_route` | A route is registered at the contract's path for that account. |
| `not_claimed` | No route is registered at the contract's path. The **capability** may still exist today at a different path — several contract pages are approximated by a single consolidated screen. UI-07 owns reconciling route shape to the contract. |

The two registers are never summed. 123 implementation claims and 160 required pages describe
different things, and conflating them would manufacture a coverage number that means nothing.

## 5. Defect severity

| Severity | Meaning |
|---|---|
| `critical` | Security-boundary breach, cross-tenant or cross-role exposure, credential/token exposure, destructive financial or control risk, or app-wide unusability. |
| `high` | A core role workflow is unreachable or materially wrong; widespread role confusion; severe accessibility or routing failure. |
| `medium` | Significant visual, navigation, responsive, theme, content or consistency defect with a usable workaround. |
| `low` | Localized polish, metadata or minor consistency defect. |
| `observation` | Evidence relevant to future architecture that is not currently a product defect. |

`root_cause_status` is `proven` only when evidence establishes the cause. Where the audit can see
a symptom but not its cause, the status is `unproven` and the recorded root cause is written as a
hypothesis for the owning phase to confirm. Speculation is never presented as fact.

## 6. What UI-01 was allowed to change

Permitted: audit scripts, the audit-only Playwright harness, sanitized evidence files, screenshot
manifests and baseline images, audit documentation, the defect register, UI-00 lifecycle
reconciliation, and UI-01 traceability/progress/changelog/proof entries.

Prohibited, and not done: runtime Vue components, router behaviour, navigation behaviour, layouts
and CSS, theme initialization, host resolution, authentication or sessions, APIs, controllers,
middleware, policies, permissions, migrations, role content or legal text, brand and landing-image
assets, and any edit to the existing screen inventory or navigation metadata made to help an audit
claim pass.

Audit-harness defects encountered during the phase were corrected **inside the harness** and are
recorded in [`docs/proof/ui-01.md`](../../../proof/ui-01.md) §Audit-harness defects. Product
defects were left untouched and entered the register.

## 7. Ownership boundaries used by the register

| Defect area | Owner |
|---|---|
| Eight-host registration, nginx, backend host resolver, URL generation | UI-02 |
| Magic Link host binding, cross-host sessions, account switching | UI-03 |
| Tokens, component library, themes, icons, shell, fixed footer | UI-04 |
| Content compiler, legal compilation, image manifest and derivatives | UI-05 |
| Public landing-page implementation | UI-06 |
| Full 160-page route/navigation/screen contract | UI-07 |
| Super Administrator / Merchant Administrator / Branch / HR / Finance / Front Office / Personnel / Audit pages | UI-08 … UI-15 |
| Release-wide responsive, accessibility, theme and visual regression | UI-16 |
| UI performance, security, production deployment, programme closeout | UI-17 |
| Backend business/security defect outside UI authority | existing backend owner or blocking decision |
| Wallet by Citrus / Citrus Refer & Earn capability | external gate or partner owner — never implemented here |

Where more than one phase is named, the **first phase that must act** is recorded in
`future_owner_phase` and the dependency is stated in `notes`.
