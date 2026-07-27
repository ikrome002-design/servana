# Phase 23 — whole-product accessibility release audit

Plan §30 (accessibility release gate), §27.3 (screen inventory), guardrail 11.
Increment 8 of Phase 23. Evidence: `docs/proof/phase-23.md` §15.

Executable matrix: [`tests/e2e/phase-23-release-audit.spec.ts`](../../tests/e2e/phase-23-release-audit.spec.ts)
Companion: [`docs/frontend/phase-23-responsive-dark-audit.md`](../frontend/phase-23-responsive-dark-audit.md)

---

## 1. Automated axe coverage

Every one of the **118 live screens** (100 `implemented` + 18 `phase_11`; the 5 `planned` screens
are not audited as though they exist) is analysed with `@axe-core/playwright` under
`wcag2a` + `wcag2aa`, in **four combinations**:

| Combination | Viewport | Theme |
|---|---|---|
| mobile-light | 360 × 780 | light |
| mobile-dark | 360 × 780 | dark |
| desktop-light | 1280 × 900 | light |
| desktop-dark | 1280 × 900 | dark |

**472 axe analyses. Serious violations: 0. Critical violations: 0.**

Coverage was **not** reduced to shorten the run: all four combinations run for every screen, in
every role family (public, super administrator, merchant administrator, branch, HR, finance, front
office, personnel, audit) and across the interaction states each screen reaches on load —
including the loading, empty and error boundaries, which is where the one defect below was found.

No axe rule is suppressed anywhere in the audit. `withTags(['wcag2a', 'wcag2aa'])` selects the
rule set; violations are filtered **only** by impact, to the `serious`/`critical` release gate the
Plan defines, and the failure message prints every offending rule and node.

## 2. Defect found and fixed

### PH23-A11Y-001 — `aria-controls` pointed at a tabpanel that did not exist

**Observed** `platform-registration-monitoring @ mobile-light`:
`aria-valid-attr-value (critical) — 1 node(s): #tab-monitoring`.

**Evidence** The Super Administrator governance screen renders an ARIA tablist whose tabs declare
`aria-controls="panel-monitoring"` / `aria-controls="panel-directory"`. Both tabpanels were nested
**inside** `SvStateBoundary`, which renders its slot only in the `success` state.

**Root cause** In the `loading`, `empty` and `error` states the boundary renders its own status
element instead of the slot, so **no tabpanel exists** and both `aria-controls` references dangle.
Those states are reachable on every page load (loading), on a platform with no registrations
(empty), and on any API failure (error) — the screen only passed previous phase audits because
those specs always stubbed populated data.

**Fix** (`resources/spa/src/pages/platform/RegistrationMonitoring.vue`) Both
`<section role="tabpanel">` elements now always exist, the inactive one carrying `hidden`, with the
state boundary rendered **inside** each panel. The directory panel's grid moved from the section to
an inner wrapper, because a `display: grid` class outranks the `hidden` attribute's user-agent
rule and would have left the inactive panel visible.

**Verification** `platform-registration-monitoring` passes all four axe combinations, plus the
responsive and theme sweeps. `npm run typecheck` clean.

## 3. Behavioural and manual verification

Automated, in `accessibility behaviour` (executed against the authenticated role shell, which is
the shared chrome for all 104 shell screens):

| Requirement | How it is proven |
|---|---|
| Skip link is the first focus stop | `Tab` from page load focuses `a[href="#main-content"]`, and it becomes visible |
| Skip link works | `Enter` moves focus to `#main-content` (which is `tabindex="-1"`) |
| Landmarks | exactly one `header`, one `main#main-content`, one named `Primary navigation` |
| Dialog initial focus | opening the mobile drawer moves focus inside `role="dialog"` |
| Dialog dismissal | `Escape` closes the drawer |
| Focus restoration | focus returns to the trigger that opened it |
| Visible focus indicator | real `Tab` traversal of the whole focus ring; every stop has an outline or ring |
| 200 % browser zoom | 640 px CSS viewport: no horizontal overflow, labelled fields and the primary action remain visible |
| Reduced motion | under `prefers-reduced-motion: reduce`, no element animates or transitions longer than 200 ms |
| Viewport scaling | `<meta name="viewport">` never sets `user-scalable=no` or caps `maximum-scale` |

Covered by axe across all 118 screens × 4 combinations: input labels and descriptions,
required-field indication, error association (`aria-describedby`/`aria-invalid`), button and link
accessible names, table headers and captions, list semantics, mobile-card accessible names, role
validity, and colour contrast in both themes.

### 3.1 A false positive corrected in the audit, with no product change

The first version of the focus-indicator check called `element.focus()` in the page and read the
computed style. `:focus-visible` — which is what draws the ring throughout this SPA — only matches
keyboard-initiated focus, so a programmatic focus reported "no ring" for a control that is in fact
correctly styled. The check now performs a **real `Tab` traversal** and inspects the genuinely
focused element. No product change was made for this finding.

## 4. Merchant Profile (REM-SCR-002A) — §11.3

| Requirement | Evidence |
|---|---|
| Every field has a persistent visible label | `<label for>` on all seven editable fields; axe `label` rule clean in 4 combinations |
| Read-only values announced clearly | `<dl>`/`<dt>`/`<dd>` for business name and country |
| Logo preview / control accessible name | the logo is a named link to a short-lived signed URL; when absent, "No logo uploaded yet." is announced as text |
| Validation messages associated | `aria-invalid` + `aria-describedby` → `#mp-*-error` |
| Save result announced | toast; the determinism test asserts "Business profile saved." becomes visible |
| Keyboard-only update works | the determinism test fills and submits without a pointer, then reloads and re-reads the persisted value |
| 200 % zoom usable | proven at a 640 px CSS viewport |
| Focus order logical | full `Tab` traversal reaches every control with a visible indicator |
| View-only state | when `merchant.profile.update` is absent the fields are `disabled` and the reason is stated in text |

## 5. Branch Calendar (REM-SCR-002B) — §11.4

| Requirement | Evidence |
|---|---|
| Exception rows have accessible names | desktop `<table>` with `<caption class="sr-only">` and `scope="col"` headers; mobile `<li>` cards repeat date, type, hours and reason as labelled text |
| Date, type and time range announced together | each row/card renders `date — type` and an explicit `Hours:` value |
| Full-day closure distinguishable without colour | the literal text `Closed all day` |
| Modified hours distinguishable without colour | the literal range `10:00 – 15:30` |
| Create/edit/remove buttons named | `Add exception`, `Edit`, `Remove`, `Save`, `Cancel` — all text buttons |
| Errors associated | `aria-invalid` + `aria-describedby` → `#bc-*-error`; the conflict banner is `role="alert"` |
| Keyboard-only create/edit/remove | the two determinism tests create both a closure and a modified-hours exception with keyboard input only |
| Mobile cards have accessible names | the `md:hidden` list mirrors every table column |
| 200 % zoom usable | no overflow at 640 px; controls remain reachable |
| View-only state | without `branch.calendar.manage` the form and row actions are absent and the reason is stated |

## 6. Critical workflow coverage (§11.5)

Accessibility of a workflow is proven where the workflow's screens live. All 28 workflow screens
are inside the 118-screen axe sweep; the column below names the behavioural spec that drives the
workflow itself.

| # | Workflow | Behavioural spec |
|---|---|---|
| 1 | Magic-Link login | `tests/e2e/auth-magic-link.spec.ts` |
| 2 | MFA enrollment | `tests/e2e/mfa.spec.ts` |
| 3 | MFA challenge | `tests/e2e/mfa.spec.ts` |
| 4 | Tenant switching | **no implemented surface** — see §6.1 |
| 5 | Branch switching | **no implemented surface** — see §6.1 |
| 6 | Merchant Profile view/update | `tests/e2e/phase-23-release-audit.spec.ts` (REM-SCR-002A determinism) |
| 7 | Branch Calendar create/edit/remove | `tests/e2e/phase-23-release-audit.spec.ts` (REM-SCR-002B determinism) |
| 8 | Client create | `tests/e2e/catalogue-clients.spec.ts` |
| 9 | Client edit | `tests/e2e/catalogue-clients.spec.ts` |
| 10 | Appointment | `tests/e2e/appointments.spec.ts` |
| 11 | Walk-in | `tests/e2e/queue.spec.ts` |
| 12 | Queue | `tests/e2e/queue.spec.ts` |
| 13 | Service session | `tests/e2e/service-session.spec.ts` |
| 14 | Invoice creation | `tests/e2e/invoice.spec.ts` |
| 15 | Payment recording | `tests/e2e/payment.spec.ts` |
| 16 | Payment validation | `tests/e2e/payment-validation-receipt.spec.ts` |
| 17 | Receipt | `tests/e2e/payment-validation-receipt.spec.ts` |
| 18 | Refund | `tests/e2e/refund-dispute.spec.ts` |
| 19 | Cash-up | `tests/e2e/cash-up-period-lock.spec.ts` |
| 20 | Period lock | `tests/e2e/cash-up-period-lock.spec.ts` |
| 21 | Billing plan / subscription | `tests/e2e/phase-20a-billing.spec.ts`, `tests/e2e/phase-20b.spec.ts` |
| 22 | Compensation | `tests/e2e/phase-20f.spec.ts`, `tests/e2e/phase-20g.spec.ts` |
| 23 | Payout | `tests/e2e/phase-20h.spec.ts` |
| 24 | Personnel SMS | `tests/e2e/phase-21s.spec.ts` |
| 25 | Search | `tests/e2e/phase-22-search.spec.ts` |
| 26 | Audit export | `tests/e2e/audit.spec.ts` |
| 27 | File download | `tests/e2e/finance-export.spec.ts`, `tests/e2e/audit.spec.ts` |
| 28 | Platform governance | `tests/e2e/phase-20b.spec.ts` |

### 6.1 The two workflows with no implemented surface

Tenant switching and branch switching have **no screen, no control and no route** anywhere in the
SPA, and no entry in `docs/frontend/screens/inventory.json` — the tenant and the assigned branches
are resolved server-side from the caller's membership on every request (`/api/v1/me` returns a
single `membership` plus `branch_ids`), and no screen offers a selector.

This is recorded as an observation, not a skip with an owner: the Plan §27.3 screen inventory does
not define a switcher, so building one in Phase 23 would be new feature delivery outside the
audit's scope. It is carried in `docs/proof/phase-23.md` as a residual item for the product owner
to rule on, **not** as a registered release gap, because no Plan-required screen is missing.

## 7. Result

| Check | Result |
|---|---|
| axe serious violations, 118 screens × 4 combinations | **0** |
| axe critical violations, 118 screens × 4 combinations | **0** |
| Behavioural / keyboard / focus / zoom / reduced-motion | pass (10 tests) |
| Defects fixed | **PH23-A11Y-001** |
| Audit-harness false positives corrected, no product change | 1 (§3.1) |
