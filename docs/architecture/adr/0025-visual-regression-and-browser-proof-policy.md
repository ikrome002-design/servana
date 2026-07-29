# ADR-025 — Visual-Regression and Browser-Proof Policy

- **Status:** Accepted (Phase UI-00 plan-adoption PR; baselines deferred to Phase UI-01/UI-16).
- **Date:** 2026-07-28
- **Required by:** UI/UX plan §1.1 (prove the problem), §1.5 (demonstrate resolution), §21.5–§21.7,
  §24 (CI and branch protection), §26 (required phase proof format), §28.9.
- **Related:** ADR-021 (theme), ADR-024 (footer), ADR-020 (navigation parity).

## Context

This corrective programme exists because the browser experience was wrong while the repository
records said the work was complete. The shipped Playwright suite is substantial and green, and yet
the product owner reports jumbled pages, role-confused navigation and a dark-first first paint.

## Problem proven

The gap is a *proof* gap. Existing tests assert behaviour (a route responds, an element exists, axe
finds no violation) but nothing asserts **appearance**, and no phase was required to show the browser
state it actually produced. Plan §1.1 states the rule the programme was missing: `PROGRESS.md`,
`CHANGELOG.md`, screenshots from an older commit, and comments in code are **not** proof that a page
works.

A related failure mode is the opposite one: a visual baseline that is regenerated whenever it fails
proves nothing at all, because the "expected" image simply becomes whatever the code produced.

## Decision

### 1. Browser proof is required and must be provenanced

A phase that claims a page works must show, from the run that produced the claim: the served commit,
the Vite asset hashes, the Docker image identifier, and the service-worker state. A screenshot
without that provenance is an undated photograph and is not accepted.

### 2. Reviewed visual baselines

Baselines are created for: the eight landing-page heroes; the eight authenticated dashboards; the
Super Administrator header shell; the sidebar shell; the mobile drawer; the fixed footer; a legal
page; a form; a table; responsive record cards; light theme; dark theme; and the loading, empty,
error, locked and permission states.

### 3. A snapshot update is a reviewed change, never a convenience

Updated baselines are committed as image diffs in the pull request and are reviewed as deliberate
visual changes. Blanket regeneration to make a suite pass is prohibited. A failing visual test is
triaged as either an intended change (update, with the reason recorded) or a defect (fix the code).

### 4. Determinism before assertion

Visual tests run at fixed viewports with fixed seeded data, animations and transitions disabled, and
fonts loaded before capture. A flaky visual test is a defect in the test and is fixed or removed, not
retried until green — the repository's zero-flake Playwright standard continues to apply.

### 5. Defects follow the Bug Fix Protocol

Every reported visual or routing defect is recorded with observed problem, evidence, affected files,
root cause, why that is the root cause, the fix, files changed, tests, test command, test result,
proof of resolution and remaining risk.

### 6. Scope discipline

The audit phase captures evidence and raises defects. It does not fix them in the same pull request.

## Scope

Visual-regression tooling and baselines, screenshot provenance, the browser-evidence requirement in
each UI phase proof, and the defect register format.

## Non-goals

Capturing any baseline in UI-00; replacing the existing accessibility, component or E2E suites;
making visual tests a substitute for behavioural or authorization tests.

## Security implications

Screenshots and traces are evidence artifacts and can leak data. Captures use seeded demo tenants
only — never production or real personal data. No screenshot may contain a Magic Link token, a
session cookie, a full payment reference, or any credential. Denial-state captures must not reveal
whether a protected record exists.

## Accessibility implications

Visual regression complements accessibility testing; it never replaces it. A pixel-identical page can
still be unusable with a keyboard. axe checks, focus-visibility checks and keyboard-path tests remain
independently required, and both themes are gated.

## Responsive implications

Baselines are captured at each shipped range — mobile ≤767, tablet 768–1024, desktop ≥1025 — because
the layout defects this programme is correcting are breakpoint-specific.

## Operational implications

Baseline images live in the repository and add weight; capture runs in the existing CI browser job on
a pinned image so rendering is consistent. Playwright jobs must not be run concurrently on the
development host — a known constraint recorded during Phase 23.

## Consequences

- "It looks right on my machine" stops being evidence.
- Whole-product regressions in the shell, footer or theme are caught once rather than per page.
- Reviewers gain a visual diff, which is the only practical review surface for CSS changes.

## Rejected alternatives

- **Trust the existing behavioural suite.** Rejected: it was green throughout the period the
  experience was wrong.
- **Auto-update baselines in CI.** Rejected: an always-passing test that asserts nothing.
- **Manual screenshot review only.** Rejected: does not scale to 160 pages across eight accounts and
  two themes, and it is not repeatable.
- **Full-page snapshots of every one of the 160 pages.** Rejected: enormous, brittle, and dominated
  by data noise. Shells, states and representative pages carry the signal.

## Future implementation owner phase

**UI-01** (as-built browser and repository audit) captures the baseline screenshots and creates the
defect register. **UI-16** owns the reviewed visual-regression baselines and the responsive,
accessibility and theme release audit. **UI-17** owns the production browser and performance
closeout. UI-00 adopts the policy only and captures no screenshots.

## Required tests

- (UI-01) provenance capture: served commit, asset hashes, Docker image, service-worker state.
- (UI-16) reviewed baselines for every surface listed in decision 2, in both themes and at all three
  breakpoint ranges.
- A guard that a baseline update is accompanied by a recorded reason.
- Determinism check: two consecutive capture runs produce no diff.

## Traceability links

`SRV-UI-PROOF-001` in `docs/traceability/servana-requirements.csv`; `docs/proof/ui-00.md`.

## Superseded or related ADRs

Related to ADR-021, ADR-024 and ADR-020. Supersedes nothing.
