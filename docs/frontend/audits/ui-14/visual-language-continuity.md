# UI-14 visual-language continuity

UI-14 inherits the elevated authenticated-app language UI-13 established and extends it with
reusable primitives that UI-15 (Audit) can adopt. It does not fork the design system, introduce a
Personnel-only component library, add raw colours, or redesign UI-08…UI-12.

## UI-13 shared primitives reused

| Primitive | UI-14 use |
|---|---|
| `SvOperationalHero` | Personnel dashboard command hero (greeting, branch/date context, observed time, one dominant action, workday flow rail in its slot) and the Personnel Get Started orientation hero. |
| `SvStateBoundary` | Loading / empty / retryable-error states on every implemented page. |
| `SvStatusBadge` | Every status is a text badge (never colour alone); tones chosen by `utils/personnelStatus.ts`, unknown → neutral. |
| `SvCard`, `SvDialog`, `SvButton`, `SvTextInput`, `SvTextArea`, `SvSelect`, `SvPagination` | Forms, the SMS confirmation dialog and paginated own-scope lists. |
| Semantic tokens (`--sv-color-*`, radius, shadow, motion) | All new surfaces use `color-mix` over tokens; no hex values in UI-14 source. |
| Fixed footer reserve, 44 px targets, light default, explicit dark | Unchanged shell contracts; proven again by the UI-14 browser suite. |

## New shared primitives (all in `resources/spa/src/components/ui/`)

| Primitive | Why shared, not page-local | UI-15 reuse |
|---|---|---|
| `SvTonalPageHeader` | The light companion to `SvOperationalHero`: shaped tonal surface, eyebrow + context pill, single `<h1>`, actions and a facts slot. Drop-in for `SvPageHeader` (keeps `sv-page-header` / `sv-page-title` hooks). Used by 16 Personnel routes (15 page components). | Every Audit list/detail page header (teal tone suits read-only oversight). |
| `SvStatTile` | Toned fact tile generalising the UI-13 flow-strip tile; value is caller-formatted, label always text. | Audit counts (open flags, export requests) without inventing figures. |
| `SvLeadRecord` | Record card led by the deciding fact (position, time, period, date) with separate status/meta/actions slots; mobile-safe. | Audit-log rows led by event time; flagged-event rows led by severity. |
| `SvMaskedIdentity` | Initials avatar + name + server-masked contact with a lock glyph and screen-reader prefix; no link, copy or `tel:`. | Audit views that must show a person without contact data. |

`SvUi14Primitives.spec.ts` covers all four. They are not added to `componentRegistry.ts`, following
the UI-13 precedent for `SvOperationalHero`: the registry hash is pinned by frozen UI-04 audit
artifacts, and registration is a UI-16 convergence task.

## Personnel-specific refinements

- **Workday companion, not a manager dashboard.** One next service dominates; the 01–04 flow rail
  (waiting → booked → with you → done) replaces identical KPI boxes; attention cards appear only for
  real states (asked-for-you requests, open questions).
- **Statement, not wallet.** My Earnings shows "recorded to date", Outstanding / Recorded paid and a
  per-component table of server figures; a three-step "how your pay moves" explainer makes the
  Finance-validation boundary visual. No card art, balances or transfer affordances.
- **Privacy as design.** Masked contact always carries the lock glyph; Served Clients are
  relationship cards whose only action is an in-platform message.
- **Composer.** Recipient panel with selected count, chips, live typed-character aid, server preview
  tiles, a warning-toned exact billing notice, a structured confirmation dialog and a sent-status
  handoff.
- **Profile header.** Account gets a Profile-reference band, avatar and owner-labelled locked facts.

## Tone discipline

Savannah Orange remains the single dominant action colour; Service Teal leads work/client pages;
Acacia Green leads earnings pages; Golden Sun marks attention (outstanding, asked-for-you); Clay
Red is reserved for reversed/cancelled/excluded. Tints are 7–20 % `color-mix` washes over the
raised surface so dark mode stays legible rather than inverted.

## Shared fixes made in UI-14

- `rounded-pill` mapped in `tailwind.config.ts` (UI14-DS-001). Shared badges across all accounts now
  render with the intended pill radius — a visible convergence change UI-16 should confirm.
- `AccountAndSecurity.vue` suppresses its own header only for `experience="personnel"` so the
  Personnel profile header owns the page's single `<h1>`.

## Intentional deviations

- The Personnel sidebar keeps the shared text-only navigation (no icons). Adding icons is a
  cross-account shell change owned by UI-16.
- `Earnings.vue` (legacy consolidated screen) is unrouted but retained because the historical UI-01
  render regression imports it (UI14-RES-001).

## Older patterns for UI-16 to remediate

UI-08…UI-12 still use flat `SvPageHeader` + equal `SvCard` stacks, `bg-primary`/`text-brand-deep`
legacy aliases, raw ISO dates, and 45 page files outside Personnel still use `sm:`/`xl:` utilities that never
compile in this config. UI-16 should migrate them onto `SvTonalPageHeader`, `SvStatTile` and `SvLeadRecord`, sweep
`sm:`/`xl:` usage, and register the UI-13/UI-14 primitives in the component registry with a
regenerated UI-04 contract.
