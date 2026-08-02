# Theme contract

**ADR-021 · UI/UX plan §12 · closes `UI01-THEME-001`**

## The rule

Light is the default. The operating-system colour scheme is **never** consulted. Dark is an
explicit user choice and only an explicit user choice.

## The defect this replaces

UI-01 observed a clean browser under a dark OS rendering **dark** at first paint. The
pre-hydration script read `window.matchMedia('(prefers-color-scheme: dark)')` and applied dark
whenever no stored value existed. The same rule was duplicated in the theme store, so correcting
only the inline script would have left hydration flipping the theme back — the flash ADR-021 also
forbids.

## Precedence

Identical in the pre-hydration script and in the store, so hydration can never change what was
painted:

1. `data-sv-theme` on `<html>` — a signed-in user's stored choice, stamped server-side by
   `SpaShellController`.
2. `localStorage['servana.theme']` — the explicit per-browser choice.
3. **light.**

A malformed or unrecognised value falls through to light rather than throwing: a display
preference must never be able to break a bootstrap.

## Why the server stamps the attribute

ADR-021 §3 requires an authenticated preference to apply *before the authenticated shell becomes
visible*. Stamping it into the document means it is present when the inline script runs — no extra
request, no flash, and no client-side rule that could drift from the server's answer.

The attribute is emitted **only** when the user has actually chosen. Absence is the "no explicit
preference ⇒ light" case, which is why `users.theme_preference` is nullable with no default: a
stored default would be indistinguishable from a real choice.

## Persistence

| Who | Where | Notes |
|---|---|---|
| Anonymous | `localStorage['servana.theme']` | Per browser. Survives logout by design. |
| Authenticated | `users.theme_preference` | `PATCH /api/v1/auth/preferences`. Own scope. **No permission key.** |

The vocabulary is closed at `light | dark` in the enum, the Form Request and a database `CHECK`.
`system` and `auto` are deliberately unrepresentable — a value meaning "follow the OS" must not
exist anywhere in the stack.

Cross-host synchronisation needs no cross-host storage: every host's `/me` bootstrap adopts the
same user record, so a preference set on one account host applies on the next host's bootstrap.
The theme never travels in a cookie or a URL.

Logout clears the **server-derived** value only. A deliberate per-device choice survives.

## Both shells

`resources/spa/index.html` and `resources/views/spa.blade.php` carry a **byte-identical** script,
asserted by `AccountHostShellContractTest`, which additionally asserts neither contains
`prefers-color-scheme` or `matchMedia`.

## Proof

`docs/frontend/audits/ui-04/theme-matrix.json` — 19 scenarios, each with where it is proven.
