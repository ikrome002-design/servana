# ADR-024 — Fixed-Footer Layout and Obstruction Prevention

- **Status:** Accepted (Phase UI-00 plan-adoption PR; runtime deferred to Phase UI-04).
- **Date:** 2026-07-28
- **Required by:** UI/UX plan §11.1–§11.4, §13.6 (overflow rules), §19 (accessibility), §28.7.
- **Related:** ADR-021 (theme control lives in the footer), ADR-020 (navigation placement),
  ADR-025 (visual regression).

## Context

The product owner requires a footer fixed to the bottom of the viewport on every page, carrying the
dark-mode control, the Citrus Labs social and corporate links, the copyright line, and links to Data
Policy, Privacy Policy, Terms of Service and FAQ.

## Problem proven

A viewport-fixed element is the most common cause of the "jumbled page" class of defect: it is
removed from normal flow, so unless the page explicitly reserves its block size, it sits **on top of**
whatever is at the bottom of the scroll — which on a form is the submit button, on a table is the
last row, and on mobile is the safe-area inset.

Plan §11.2 enumerates what the footer must never cover: primary actions, form fields, validation
messages, pagination, table records, mobile safe areas, focused controls, toast dismissal controls
and modal controls. That list is a defect catalogue, not a wish list.

## Decision

**The footer is fixed, and the layout reserves its exact block size.**

1. The application shell is a layout that allocates a real track for the footer. The footer's block
   size is a responsive design token, and the same token drives the reserved space — one value, so
   the two cannot disagree.
2. Scrollable content regions end above the footer. The footer never overlaps content; content
   scrolls beneath the *space* reserved for it, not beneath the footer itself.
3. Mobile safe-area insets are added to the footer's own padding, so the reserved height accounts for
   the inset rather than the footer sitting under it.
4. Elements that must remain reachable — toasts, modal controls, focused inputs, sticky primary
   actions — stack above the footer, and scroll-into-view accounts for the reserved space so a
   focused control is never scrolled to a position the footer occupies.
5. Composition: desktop may show copyright, corporate link, legal menu, support menu, social icons
   and the theme control. Tablet and mobile use two compact rows with accessible menus for Legal and
   Support and icon-only social controls with visible tooltips and screen-reader labels.
6. The footer never causes horizontal page scrolling. Internal overflow is permitted only as an
   accessibility fallback under extreme zoom.
7. External links use `target="_blank"` with `rel="noopener noreferrer"` where a new tab is
   intended, and every icon link has a clear accessible name.

Required links (plan §11.1): Instagram `@citruske`, X `LabsCitrus`, Facebook (profile id
`100063778943426`), YouTube `@citrus-labs`, LinkedIn `company/citrus-labs`, corporate site
`https://citruslabs.co.ke/`, `© 2026 Citrus Labs. All Rights Reserved.`, plus Data Policy, Privacy
Policy, Terms of Service and FAQ.

## Scope

The application shell layout, the footer component, footer tokens, and the scroll-region contract.

## Non-goals

Changing footer content or wording; building the footer in UI-00; changing navigation placement
(ADR-020).

## Security implications

`rel="noopener noreferrer"` on every new-tab external link prevents reverse-tabnabbing and referrer
leakage to third-party social properties. The footer contains no user data and no authenticated
state beyond the theme control.

## Accessibility implications

Central to this ADR. The footer is a `contentinfo` landmark. It is reachable in the tab order, and
because it is visually last it must also be *logically* last so keyboard order matches reading
order. Every icon-only control has an accessible name and a 44 px minimum target. The obstruction
rules exist to protect keyboard users, who cannot "scroll a little further" to reveal a covered
control. Under 200% zoom the footer must not consume the viewport — the responsive token shrinks it,
and internal overflow is the last-resort fallback.

## Responsive implications

Footer height is a token per breakpoint range (mobile ≤767, tablet 768–1024, desktop ≥1025), applied
through CSS media queries only. No JavaScript measurement, no device detection.

## Operational implications

None beyond the shared shell. Because the footer appears on every page of every host, a regression
here is a whole-product regression — which is why it is a named visual-regression baseline
(ADR-025).

## Consequences

- The theme control has one permanent, predictable home on every page.
- Every page gains a guaranteed-clear region at the bottom of the viewport.
- Any future full-height layout must respect the reserved track rather than using `100vh`.

## Rejected alternatives

- **A normal-flow footer at the end of the document.** Rejected: the product owner requires it fixed,
  and the theme control would be unreachable without scrolling to the bottom of long pages.
- **`position: fixed` with a hard-coded body bottom padding.** Rejected: two values that drift apart
  is precisely how the obstruction defects appear; one token drives both.
- **Hiding the footer on mobile.** Rejected: it carries the legal links and theme control, which are
  required on every breakpoint.
- **Auto-hiding the footer on scroll.** Rejected: unpredictable target position for the theme and
  legal controls, and hostile to motor-impaired users.

## Future implementation owner phase

**UI-04** (design system and shared components) owns the shell and footer. **UI-16** owns the
responsive, accessibility and theme release audit. UI-00 adopts the requirement only; no layout or
component changes here.

## Required tests

- Component tests for desktop, tablet and mobile composition.
- Obstruction tests: at each breakpoint, the primary action, last table row, pagination control,
  validation message and focused input are all fully visible and clickable.
- Focus-order test proving the footer is last and reachable.
- Accessible-name test for every icon-only control.
- `rel="noopener noreferrer"` assertion on every external link.
- No horizontal page scroll at any breakpoint; 200% zoom check.
- Visual-regression baselines for the fixed footer in light and dark (ADR-025).

## Traceability links

`SRV-UI-FOOTER-001` in `docs/traceability/servana-requirements.csv`; `docs/proof/ui-00.md`.

## Superseded or related ADRs

Related to ADR-021, ADR-020 and ADR-025. Supersedes nothing.
