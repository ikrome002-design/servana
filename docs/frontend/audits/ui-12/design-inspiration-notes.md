# UI-12 design-inspiration audit

Source directory: `C:\Users\nderu\Documents\Development\Product\Template\UI-UX Design Inspiration`.
Six image candidates were inventoried and all six WebP files rendered correctly in the Codex image
viewer. Windows `System.Drawing` does not decode WebP and its initial unreadable result was rejected
as a tooling limitation, not treated as image corruption.

| File | Adopt structurally | Reject for Servana |
|---|---|---|
| `Billings page.webp` | Summary-to-detail rhythm, compact filters, dense readable history | Card/payment-provider metaphor, foreign identity, unsupported billing controls |
| `Dashboard page.webp` | Asymmetric command-center grid, dominant pulse, recent activity | Copied metrics/data, decorative or client-calculated charts, transfer actions |
| `Landing page.webp` | Bold hierarchy and warm compositional fields | Marketing/product imagery inside Finance, copied brand/copy/assets |
| `Login page.webp` | Focused split hierarchy | Password fields, sign-up/auth patterns, foreign illustration and colors |
| `Notifications page.webp` | Grouped alert hierarchy, restrained overlay and background context | Fabricated Phase-21N notification records or controls |
| `Profile page.webp` | Dense labelled sections and durable context | Dark-only presentation, media/profile ornament, unrelated content |

## Servana translation

- Use approved tokens only: orange for primary action, teal for controlled financial information,
  green for approved/valid states, warning/error for real risk, warm sand/cream for calm context and
  brown/charcoal for hierarchy. Color never stands alone and no saturated-card rainbow is allowed.
- Make “what needs action, why, maker, checker eligibility, step-up freshness, period state and safe
  next action” the command-center hierarchy.
- Prefer a severity-sorted action rail, balance/status breakdowns that never combine currencies,
  compact financial tables, mobile labelled cards, sticky review regions and progressive disclosure.
- Use only server-returned amounts and states. Unknown/null is unavailable, never zero.
- Retain the established sidebar/tablet-rail/mobile-drawer shell, fixed-footer reserve, 44px targets,
  visible focus, light-first behavior, persistent dark mode and reduced-motion contract.
