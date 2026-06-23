# ADR-009 — Brand Contrast Tokens (Accessible CTA & Status Colours)

- **Status:** Accepted (Phase R7, REM-OPS-001).
- **Date:** 2026-06-22.
- **Plan refs:** §8 (ADR-009), §12 (brand tokens), §28–§30 (responsive/dark/
  accessibility gates), §79 R7. Brand source: `docs/brand/Servana Brand Identity.md`.

## Context

WCAG 2.1 **AA** requires a contrast ratio of **≥ 4.5:1** for normal-size text
(and ≥ 3:1 for large text and non-text UI). Servana's primary call-to-action uses
the brand's **Savannah Orange** (`--color-primary: #f97316`). The naïve choice of
**white** text on that orange fails AA for normal text, so a deliberate token
decision is required and must be pinned against silent drift.

This ADR records the **measured** ratios of the actually-committed tokens
(`resources/spa/src/style.css`) and the foreground/background pairings used by
the UI kit (`resources/spa/src/components/ui/SvButton.vue` uses
`bg-primary text-brand-deep` for the primary CTA and `bg-error text-white` for
the destructive button).

## Committed tokens (light theme)

| Token | Hex | Role |
|---|---|---|
| `--color-primary` | `#f97316` | Savannah Orange — CTA / active states |
| `--color-brand-deep` | `#4a2208` | Brand Deep — headings, **CTA text** |
| `--color-error` | `#dc2626` | Destructive button background |
| `--color-accent` | `#007c78` | Service Teal — links / accents |
| `--color-text` | `#1f2933` | Body text |
| `--color-bg` | `#f9fafb` | App background |
| `--color-surface` | `#ffffff` | Card / surface |

## Measured contrast ratios

Computed with the WCAG 2.1 relative-luminance formula (see
`tests/Unit/BrandContrastTokenTest.php`, which recomputes these from the
committed hex values):

| Pairing | Ratio | AA (normal ≥ 4.5) |
|---|---|---|
| **Brand Deep `#4a2208` on Primary `#f97316`** (primary CTA) | **≈ 4.92:1** | ✅ PASS |
| White `#ffffff` on Primary `#f97316` (rejected) | ≈ 2.80:1 | ❌ FAIL |
| White `#ffffff` on Error `#dc2626` (destructive) | ≈ 4.83:1 | ✅ PASS |
| Accent `#007c78` on Surface `#ffffff` (links) | ≈ 5.06:1 | ✅ PASS |
| Body `#1f2933` on Background `#f9fafb` | ≈ 14.8:1 | ✅ PASS |

## Decision

1. **The primary CTA uses dark Brand Deep text on Savannah Orange** —
   `bg-primary text-brand-deep` — because white-on-orange (2.80:1) fails AA while
   brand-deep-on-orange (4.92:1) passes. The brand palette is **not** altered; the
   accessible choice is the *text* token, not a new orange.
2. **Approved AA-compliant pairings** are exactly the five "PASS" rows above.
   White-on-orange is explicitly **disallowed** for text.
3. **Buttons/links/focus** keep a visible focus ring (`focus-visible:ring-2
   ring-primary ring-offset-2`) and 44px minimum targets (already in `SvButton`).
   Focus rings are a non-text indicator (AA ≥ 3:1) and use the primary token on
   the page surface.
4. **Dark mode:** the CTA remains Brand-Deep-on-Orange (the orange `--color-primary`
   is preserved in the dark `:root`, and `--color-brand-deep` is not overridden),
   so the CTA ratio is unchanged in dark mode. Dark-mode body text
   (`#f3f4f6` on `#111827`) and surfaces already exceed AA.

## Verification

- `tests/Unit/BrandContrastTokenTest.php` reads the committed `--color-*` tokens
  and **fails** if any approved pairing drops below AA, or if white-on-orange ever
  rises to "pass" (which would mean the orange was changed). This is the
  deterministic guard.
- The broader per-screen **axe** AA sweep across all foundation screens (light +
  dark) remains owned by the Phase 23 release accessibility audit; this ADR pins
  the *token-level* decision the components build on.

## Consequences

- The CTA's accessible text colour is locked; changing it (or the orange) breaks
  the contrast test, forcing a conscious ADR revision.
- No brand-palette rewrite; the decision is documentation + a token-level test,
  not a visual redesign.
- Future status/role colours added to the palette must add a row here and to the
  contrast test before shipping (change control).
