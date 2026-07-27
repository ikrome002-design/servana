# Phase 23 — whole-product responsive and dark-mode release audit

Plan §28 (responsive), §29 (theming/ADR-009), §27.3 (screen inventory).
Increments 6 and 7 of Phase 23. Evidence: `docs/proof/phase-23.md` §13–§14.

Executable matrix: [`tests/e2e/phase-23-release-audit.spec.ts`](../../tests/e2e/phase-23-release-audit.spec.ts)
Harness: [`tests/e2e/support/releaseAudit.ts`](../../tests/e2e/support/releaseAudit.ts)

---

## 1. Scope — what "the whole product" means here

The audited set is **every live screen in `docs/frontend/screens/inventory.json`**: the 100
`implemented` screens plus the 18 `phase_11` foundation screens = **118 live screens**. The five
`planned` screens are owned by phases that genuinely have not shipped and are deliberately **not**
audited as though they exist.

The matrix is **derived from the inventory at run time**, not hand-listed. The
`release-audit coverage` test reads `inventory.json`, compares it against the audited keys and
fails on either direction:

```
missing  → a live screen with no release-audit coverage
invented → an audited key that is not a live screen
```

A screen delivered by a later phase therefore cannot escape this audit — the guard fails until it
is enrolled.

## 2. Viewport and theme matrix

| Axis | Values |
|---|---|
| Viewport | 360 × 780 (mobile), 768 × 1024 (tablet), 1280 × 900 (desktop) |
| Theme | light, dark |
| Zoom | 200 % (proven as a 640 px CSS viewport at 1280 px) |

The three widths are the Plan §28 breakpoint bands (mobile ≤767, tablet 768–1024, desktop ≥1025).
Heights are fixed so a run is reproducible; no screen required additional viewport coverage.

Each responsive test navigates **once** and then **resizes** through the matrix. That is deliberate:
it proves requirement §9.2(18) — a live resize re-lays-out correctly — which a fresh load per width
would not.

## 3. Determinism of the audit itself

| Input | Pinned to |
|---|---|
| Clock | `2026-07-15T09:00:00.000Z` = 12:00 Africa/Nairobi (Wednesday) |
| Business date | `2026-07-15` |
| Future date | `2026-08-12` |
| Identifiers | fixed 26-character constants (`IDS` in the harness) |
| Session | stubbed `/api/v1/me` per screen, derived from the inventory's own role + permission list |
| API | ordered fixture registry; unmatched `GET` → empty paginated envelope |

No wall-clock date, random value or ambient database state reaches a screen. See Increment 9 in
`docs/proof/phase-23.md`.

## 4. Data state each screen was audited in

The preview build has no backend, so the audit stubs the API. Screens are audited in the state
recorded below; **populated** states come from the fixture registry, **empty** states are the real
empty-state rendering (itself a reachable production state and, as §6 shows, the state that
exposed the one accessibility defect).

| State | Screens | Meaning |
|---|---|---|
| `static` | 34 | No list/detail data drives the layout (landings, get-started, auth, onboarding, dashboards). |
| `populated` | 82 | Rendered with fixture rows: tables, detail records, forms, badges, money. |
| `access-state` | 2 | `unsupported-role`, `no-branch-assignment` — rendered boundaries, not routes. |

Populated fixtures deliberately include hostile-but-real content:

- a **68-character merchant name** (`Glow Studio Nairobi Westlands Grooming & Wellness Collective Limited`)
- an unbroken machine token as a heading (`branch.calendar_exception_set`)
- masked contact values (`+2547•••••678`) — the fixture never carries unmasked contact data
- all four calendar exception types, including a modified-hours window

## 5. Per-screen invariants proven (Increment 6)

For all 118 screens at all three widths:

1. no page-level horizontal overflow (`documentElement.scrollWidth ≤ clientWidth`)
2. no element extends past the viewport outside a deliberately scrollable container
3. the screen's own content renders (heading, or the declared access-state marker)
4. the application shell stays inside the viewport
5. navigation remains usable — drawer trigger below the collapse breakpoint, persistent
   sidebar/header navigation above it
6. `<meta name="viewport">` never disables zoom
7. no JavaScript device detection anywhere in the SPA source (static guard over
   `resources/spa/src`, covering `navigator.userAgent/platform/vendor/maxTouchPoints`, `jQuery`)

Headings, form fields, labels, error text, actions, dialogs, tables, cards and badges are covered
by (1)+(2)+(3): an element that clipped, overlapped or pushed the page wide fails one of them.

### 5.1 Merchant Profile (REM-SCR-002A) — §9.3

Proven at 360 / 768 / 1280 and again at 200 % zoom: the form fits; the logo block fits; the
68-character business name wraps rather than overflowing; every contact field fits; `Save changes`
stays reachable; validation text wraps; the read-only business/billing context stays legible; and
**no private object path is rendered** (asserted against `merchants/…/logo`, `s3://`,
`/storage/app/`).

### 5.2 Branch Calendar (REM-SCR-002B) — §9.4

Proven at 360 / 768 / 1280: the exception list adapts (`md:table` desktop table ↔ labelled mobile
cards, both fed from the same rows); type and date stay labelled; full-day closures and
modified-hours rows are distinguishable by text (`Closed all day` vs `10:00 – 15:30`), never by
colour alone; add/edit/remove controls stay reachable; the create form and the inline editor fit;
time fields do not clip; reason text wraps; conflict/validation messages stay visible; branch
context stays clear. **No field was removed to make mobile pass.**

## 6. Defects found and fixed — Increment 6

### PH23-RSP-001 — hand-rolled inputs without `w-full` widened the whole document

**Observed** `merchant-profile` and `branch-calendar` scrolled horizontally at 360 px
(`scrollWidth 371 > clientWidth 360`).

**Root cause** Both screens hand-rolled their inputs as
`class="min-h-[44px] rounded-control border border-border bg-surface px-3 text-text"` — the only
two files in `resources/spa/src/pages` to do so, and the only inputs in the product **missing
`w-full`** (the shared `SvInput` has it, `SvInput.vue:62`). A bare `<input>` keeps its intrinsic
`size=20` width (measured: **241 px**). As flex items they could not shrink, so the card (307),
the section (339) and `main` (371) each inflated to that min-content width and the document
scrolled.

**Fix** `w-full` on all 15 inputs/selects across the two screens, matching `SvInput`. The time and
reason fields sit in `flex flex-wrap` rows, so their wrappers additionally got `min-w-[8rem] flex-1`
so a full-width input can share the row and wrap instead of forcing a width.

### PH23-RSP-002 — `main` could not shrink below its content (**pre-existing, shell-wide**)

**Observed** `audit-event-detail` scrolled to 440 px at a 360 px viewport; `audit-event-list`,
`audit-finance`, `audit-compensation` and `finance-audit` to 364 px.

**Root cause** `<main id="main-content" class="flex-1 …">` in `AppShell.vue` is a flex item, so it
defaults to `min-width: auto` and **cannot shrink below its content's min-content width**. One wide
child then widens the entire document instead of being contained. On the audit-event screens that
child is the audit action heading — `branch.calendar_exception_set` is a machine token with no space
to break at, 376 px wide at `text-2xl`.

This is **pre-existing**, not a Phase 23 regression: the shell has been this way since Phase 11 and
any sufficiently long action name reaches it.

**Fix** two parts, because either alone is insufficient:

- `min-w-0` on `main` (`AppShell.vue`) so the shell contains its content — one line, one audited
  place, and the fix for the whole class of defect;
- `break-words` on the audit action heading (`AuditEventDetail.vue`). Note `overflow-wrap:
  break-word` does **not** reduce min-content width, so it only takes effect once `min-w-0` lets the
  container narrow — which is why the heading fix alone did not resolve the failure.

Tables already sit in `overflow-x-auto` wrappers, so with `min-w-0` they scroll inside their
container instead of widening the page — the Plan §28 behaviour.

## 7. Theme audit (Increment 7)

For all 118 screens, in **light and dark**:

- the theme genuinely applied (`html.dark` present/absent as expected — a screen that silently
  stayed light would prove nothing);
- **no text is transparent, and no text resolves to its own background**, with every translucent
  layer composited against its ancestors;
- no horizontal overflow in either theme.

Precise contrast **ratios** are measured by axe (`wcag2a`+`wcag2aa`, which includes
`color-contrast`) in Increment 8, run in **both themes at both widths** — that is where body text,
muted text, headings, links, buttons, borders, inputs, placeholders, validation, disabled states,
status badges, tables, mobile cards, dialogs, empty states and loading states are all checked for
AA. Section 7 catches the failure axe cannot see: a token that resolves to the same colour as the
surface behind it.

### 7.1 False positive corrected in the new guard

The first run reported "text matches its background: Overview (rgb(255,255,255))" on all five
Super-Administrator screens. This was a **defect in the new guard, not in the product**: the active
header nav item is `bg-white/15 text-white` over the dark `bg-brand-deep` header, and the guard was
taking the first non-transparent background instead of compositing it. The intended treatment is
correct and AA-compliant. The guard now composites every translucent layer and compares within an
8/255 per-channel tolerance. **No product change was made for this finding.**

### 7.2 Merchant Profile and Branch Calendar in both themes

Both screens pass §10.2 and §10.3 through the combination above: labels, editable/read-only
distinction, logo surface, error text, focus rings, success feedback, disabled controls, billing
context (Merchant Profile); normal/closure/modified-hours/selected states, borders and card
separation, the inline editor, time inputs, conflict errors, focus and past-date states (Branch
Calendar). No brand token was blanket-replaced; **no deliberate brand colour was altered**, because
no theme defect was found on either screen.

## 8. Result

| Increment | Tests | Result |
|---|---|---|
| Coverage guard | 3 | pass |
| 6 — responsive (118 screens × 3 widths) | 118 | pass |
| 7 — theme (118 screens × 2 themes) | 118 | pass |

Defects fixed: **PH23-RSP-001**, **PH23-RSP-002**. False positives corrected in the audit
harness, with no product change: **1** (§7.1).
