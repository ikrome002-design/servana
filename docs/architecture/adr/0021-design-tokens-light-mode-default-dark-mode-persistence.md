# ADR-021 — Servana Design Tokens, Light-Mode Default, and Dark-Mode Persistence

- **Status:** Accepted (Phase UI-00 plan-adoption PR; runtime deferred to Phase UI-04).
- **Date:** 2026-07-28
- **Required by:** UI/UX plan §9 (brand identity and design system), §9.4 (icon system), §12.1–§12.4,
  §28.6; `docs/brand/Servana Brand Identity.md`.
- **Related:** ADR-009 (brand contrast tokens), ADR-024 (fixed footer), ADR-025 (visual regression).

## Context

The repository already ships brand contrast tokens (ADR-009) and passed a responsive/dark-mode audit
in Phase 23 (`docs/frontend/phase-23-responsive-dark-audit.md`). The product owner reports that a
fresh browser context can render dark mode first, which is not the intended default.

## Problem proven

UI/UX plan §12.1 requires that a fresh browser context render **light** mode, and names the five
mechanisms that must not silently force dark: operating-system preference, browser preference, CSS
`prefers-color-scheme`, a stale local-storage default, and a server default inherited from another
account. A dark-first first paint means at least one of those is winning.

Plan §12.3 additionally requires that the initial theme be set **before Vue hydration**, or the page
flashes the wrong theme regardless of which value eventually wins.

Separately, plan §9.4 forbids emoji icons and requires one consistent icon system; without a recorded
decision, each role phase would pick its own.

## Decision

### 1. Tokens

One Servana design-token layer derived from `docs/brand/Servana Brand Identity.md` — colour,
typography (Inter for UI, Manrope for page titles), spacing, radius, elevation, motion and component
tokens. Both themes are expressed as token values, not as scattered per-component overrides.
ADR-009's accessible CTA and status colours are carried forward, not replaced.

### 2. Light mode is the default

A fresh browser context renders light. `prefers-color-scheme` **does not** select the theme. Dark
mode is an explicit user choice and only an explicit user choice.

### 3. Persistence ownership

- **Anonymous:** the explicitly selected theme is stored per browser, scoped to the host. Absence of
  a selection means light.
- **Authenticated:** the preference is persisted to the user preference record, is synchronised to
  the host-local value after login, and is applied **before the authenticated shell becomes
  visible**. The server record is authoritative for a signed-in user; the browser value is the
  fallback.

### 4. No theme flash

A tiny inline script sets the initial theme class before hydration. It may read **only** the explicit
Servana theme preference. It performs no device detection.

### 5. Icons

Heroicons for Vue is the single icon system; custom SVG only where genuinely required. No emoji
icons. Every icon-only control has an accessible name; decorative icons are hidden from assistive
technology; status never depends on icon shape or colour alone.

### 6. Both themes are release gates

Contrast, visible focus, visible borders, disabled states, validation states, status distinctions,
legible charts and tables, a legible fixed footer, correct logo treatment and correct landing-image
contrast must all hold in **both** themes.

## Scope

Token definitions, the theme store, the pre-hydration initialiser, the user preference field, the
theme control in the fixed footer, and the icon dependency.

## Non-goals

Redesigning any page in UI-00; adding new tokens for a component that does not exist yet; changing
brand colours; introducing a third theme.

## Security implications

Minimal. The pre-hydration script must remain a fixed, non-interpolated literal so it cannot become
an injection point, and it must read only the theme key — never any other stored value. The
persisted preference is non-sensitive and is not logged.

## Accessibility implications

This is primarily an accessibility decision. Forced dark mode has caused real contrast failures in
this codebase before (ADR-009). Both themes must meet WCAG AA contrast, and focus must remain
visible in both. The theme control is keyboard reachable with an accessible name and announces its
state.

## Responsive implications

Tokens include responsive scales. Theme selection is orthogonal to breakpoint: all four
combinations (light/dark × the shipped breakpoints) are valid states and are all release-gated.

## Operational implications

Requires an expand-only migration for the user theme preference if the field does not already exist.
The token layer becomes the single place a brand change is applied.

## Consequences

- One recorded default removes an entire class of "why is it dark?" defects.
- Every later UI phase inherits tokens instead of inventing colours.
- Emoji and ad-hoc icons become lintable violations rather than review opinions.

## Rejected alternatives

- **Respect `prefers-color-scheme` on first visit.** Rejected: directly contradicts plan §12.1, and
  it is what produces the reported dark-first paint.
- **Store theme only on the server.** Rejected: anonymous visitors on public landing pages have no
  user record, and it would guarantee a flash on every load.
- **Store theme only in the browser.** Rejected: the preference would not follow a user across
  devices or hosts.
- **Emoji icons.** Rejected by plan §9.4; they render inconsistently across platforms and carry no
  reliable accessible name.

## Future implementation owner phase

**UI-04** (design system and shared components) owns tokens, the theme initialiser and the icon
system. **UI-16** owns the release-gate audit across both themes. UI-00 adopts the decisions only —
no token, theme, or icon code changes in this phase.

## Required tests

- A fresh context with `prefers-color-scheme: dark` renders light.
- An explicit dark selection survives reload and is applied with no flash.
- A signed-in user's server preference wins over a stale host-local value.
- Contrast assertions in both themes on the gated pages.
- A source guard rejecting emoji icons in UI source (plan §21.8).

## Traceability links

`SRV-UI-THEME-001` in `docs/traceability/servana-requirements.csv`; `docs/proof/ui-00.md`;
`docs/frontend/phase-23-responsive-dark-audit.md` (prior audit substrate).

## Superseded or related ADRs

Extends ADR-009 (brand contrast tokens) — it is not superseded; its accessible colour work carries
forward. Related to ADR-024 and ADR-025.
