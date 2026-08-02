# Fixed-footer contract

**ADR-024 · UI/UX plan §11**

## The obstruction problem

A fixed element is removed from normal flow. Unless the page explicitly reserves its block size it
sits **on top of** whatever is at the bottom of the scroll — the submit button on a form, the last
row of a table, the safe-area inset on mobile. ADR-024's list of what the footer must never cover
is a defect catalogue, not a wish list.

## The mechanism

**One token per breakpoint drives both the footer and the reserve.**

| Band | Token | Value |
|---|---|---|
| Mobile ≤767 | `--sv-footer-height-mobile` | 5.5rem |
| Tablet 768–1024 | `--sv-footer-height-tablet` | 4.5rem |
| Desktop ≥1025 | `--sv-footer-height-desktop` | 3.5rem |
| Extreme zoom | `--sv-footer-height-zoom-fallback` | 9rem, with internal scrolling |

`.sv-fixed-footer` sets the height; `.sv-footer-reserve` allocates exactly the same value as page
padding. Both live in `resources/spa/src/style.css` and read the same variable, so they cannot
drift. The shell root carries `.sv-footer-reserve`.

Mobile safe-area insets are added to the footer's own padding, so content clears the home
indicator rather than sitting under it. Under a viewport shorter than 26rem the footer caps its
height and scrolls internally rather than consuming the screen.

Overlays sit above it by token: `z-footer` (20) < `z-drawer` (40) < `z-dialog` (50) <
`z-popover` (60) < `z-toast` (70). `SvToast` additionally offsets its stack by the footer-height
token, so its dismiss control is never covered.

> **Implementation note.** The reserve class must stay on the shell's **root** element and the
> template must keep a single root: a leading comment node makes the component a fragment, which
> silently moves the class off the mounted element. That regression was caught by
> `RoleLayouts.spec.ts` during UI-04.

## Required content

Theme control · Instagram `@citruske` · X `LabsCitrus` · Facebook (profile `100063778943426`) ·
YouTube `@citrus-labs` · LinkedIn `company/citrus-labs` · `https://citruslabs.co.ke/` ·
`© 2026 Citrus Labs. All Rights Reserved.` · Data Policy · Privacy Policy · Terms of Service · FAQ.

Every external link opens in a new tab with `rel="noopener noreferrer"` — a security control, not
styling. `noopener` closes reverse-tabnabbing; `noreferrer` stops the Servana URL leaking to
third-party social properties. `SvLink` makes the pairing structural.

## Content boundary

The three legal links point at the existing role-scoped `legal.document` route, so one account
never receives another's documents.

**The FAQ link renders only when the caller supplies a route.** No role-aware FAQ route exists yet,
so the product footer omits it. Shipping a dead link to satisfy a label is what §11.4 forbids;
activating the real route belongs to UI-05/UI-06.
