# Token contract

**Authority:** `resources/spa/src/design-system/tokens.json`
**Generator:** `node scripts/generate-design-tokens.mjs [--check]`

## Layers

| Layer | Count | Rule |
|---|---|---|
| Palette | 48 | Raw brand values. The only place a hex literal is legal. |
| Semantic | 48 | Every one has an explicit **light and dark** value. Components consume these. |
| Component | 62 | Spacing, radius, shadow, motion, z-index, layout, footer, control, table, safe-area. |
| Typography | 3 families, 7 steps, 5 weights | Inter for UI, Manrope for page titles. Scalable `rem`. |

A component may not invent a local colour or an unrelated token set.

## The seventeen approved brand values

Verbatim from UI/UX plan §9.2 and asserted by `DesignTokenSchemaTest`. Changing one fails the
build, which is the point.

## Contrast is computed, not asserted

`tokens.json` carries 35 `contrast_requirements`. `DesignTokenContrastTest` computes WCAG 2.1
relative luminance for **both themes** and fails below the declared ratio. It caught three real
failures on its first run.

That is also why `color-border-input` exists separately from `color-border-default`: a decorative
separator has no WCAG minimum, but the boundary of an **interactive control** is held to 3:1
(WCAG 1.4.11). Collapsing them would have meant either an invisible input border or an
unnecessarily heavy divider everywhere.

`color-text-muted` binds to `#4B5563`, not the brand palette's `#6B7280`, because ADR-009 requires
the darker value for AA on the shipped surfaces. The palette entry is retained because the brand
document defines it — this is exactly what semantic tokens are for.

## Legacy aliases

The pre-UI-04 `--color-*` variables are **generated** as aliases of the semantic tokens, so there
is one source of colour truth and they cannot disagree. Migrating the legacy pages off them
belongs to UI-08 … UI-15.

## Staleness

Each generated file records the source SHA-256. `DesignTokenGenerationTest` compares it against
the live hash — a node-free check, because the PHP image has no Node runtime. CI's Frontend job
additionally runs `--check`, which catches a hand-edit to a generated body.
