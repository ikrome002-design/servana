# Component contract

**Authority:** `resources/spa/src/design-system/componentRegistry.ts`
**Enforced by:** `tests/Feature/DesignSystem/ComponentContractTest.php`
**Derived artifact:** `docs/frontend/audits/ui-04/component-contracts.json`

54 shared components across seven categories: primitive (11), feedback (9), overlay (8), form
(13), data (5), shell (5), content (3).

## What the registry declares per component

Name · category · source · test · purpose · supported states · keyboard contract · responsive
contract · theme contract.

`states` is **honest**: a component with no loading state declares none. A registry claiming every
component did everything would prove nothing.

## What the contract test enforces

Every required component present · every source and test file exists · every test actually
references its component · no placeholder, empty shell or file under 400 bytes · typed
`defineProps`/`defineEmits` only · no duplicate name · **no legacy duplicate**.

That last one is why UI-04 removed `SvInput`, `SvTextarea`, `SvModal` and `SvAccountSwitcher`
rather than leaving them beside `SvTextInput`, `SvTextArea`, `SvDialog` and
`SvAccountContextSwitcher`. The check is case-exact, because `file_exists()` is case-insensitive
on Windows and macOS and would report `SvTextarea.vue` as present purely because `SvTextArea.vue`
exists.

## Decisions worth knowing before you extend it

**One focus trap.** `useFocusTrap` is shared by `SvDialog`, `SvConfirmDialog` and `SvDrawer`. Two
independent traps drift: one restores focus and the other does not, and the difference only shows
up for keyboard users. It deliberately does **not** own Escape — that is per-overlay policy, and a
confirm dialog mid-submission must not vanish.

**One association owner.** `SvFormField` owns the control id, help id, message id, required marker,
composed `aria-describedby` and `aria-invalid`. Before UI-04 three inputs had three incompatible
strategies. The control receives its attributes through a scoped slot, so an input physically
cannot wire itself up differently. Note the message element id is `{id}-message`, not `{id}-error`:
one element carries error, warning and success.

**One data contract.** `SvColumn[]` drives both `SvDataTable` (tablet up) and
`SvResponsiveRecordList` (mobile), so a screen defines its data once. `priority` declares
importance once and both honour it: `detail` columns move behind a per-card disclosure and are
never dropped.

**Buttons act, links navigate.** Separate components, not an `as` prop. Merging them is how
middle-click, copy-link and screen-reader roles get lost.

**Native first.** `SvSelect` is a native `<select>`; `SvDatePicker` wraps `<input type="date">`;
`SvFaq` and `SvAuditEvent` use `<details>`; `SvRadioGroup` uses a `<fieldset>` for its accessible
name and gets roving arrow keys from the browser. `SvCombobox` exists for the genuinely different
case — filtering a long or server-loaded list — and implements the full ARIA pattern rather than
half of it.

**Money and dates never lie.** `SvMoney` takes integer minor units and renders "Not available" for
an absent amount — never zero. `SvDateTime` formats in `Africa/Nairobi` and keeps a date-only
value date-only.

**States stay distinct.** `SvEmptyState`, `SvErrorState`, `SvOfflineState`, `SvPermissionState` and
`SvLockedState` are five different facts. `SvPermissionState` in particular never names the
resource or confirms it exists — that is the non-enumeration boundary UI-03 proved server-side.

**Icons.** Heroicons only, imported individually through `design-system/icons.ts`. There is no
runtime name-to-component map: it would have to reference every icon to resolve an arbitrary
string, shipping the whole catalogue to every account host.

## Adding a component

1. Build it against the rules above.
2. Add a real behavioural spec — not one large snapshot.
3. Register it in `componentRegistry.ts` with an honest `states` list.
4. Run `node scripts/generate-ui04-artifacts.mjs` and the contract test.
