# Screen specification — Billing settings (platform)

> Plan §27.1 · §47 · §50; Phase 20A. Owning phase authored spec (not the generator
> stub). Status: **implemented** · Owning phase: **Phase 20A**. Source of truth for
> this screen; the inventory entry `platform-billing-settings` points here.

## Identity

- **Screen key:** `platform-billing-settings`
- **Route name and URL:** `platform.billing-settings` → `/platform/billing-settings`
- **Layout:** `PlatformAdminLayout` (Super Administrator header shell; nested under `/platform`)
- **Component:** `resources/spa/src/pages/platform/BillingSettings.vue` with section
  components in `resources/spa/src/pages/platform/billing/`.
- **Allowed roles:** `super_administrator` only.
- **Platform scope:** platform-only. Routes use `ResolvePlatformContext`; **no merchant
  or branch context is established**. Platform-owned records carry no `merchant_id`/`branch_id`.

## Permissions (frontend visibility only — API is authoritative)

One coherent screen with accessible tabs; each tab is gated by the resolved permission
and is **absent** (not disabled) when denied:

| Tab | View permission | Mutate permission | MFA | Fresh step-up |
|---|---|---|---|---|
| General settings | `platform.settings.view` | `platform.settings.update` | yes | yes (update) |
| Billing settings | `platform.billing_settings.view` | `platform.billing_settings.update` | yes | yes (update) |
| Plans | `platform.plan.view` | `platform.plan.manage` | yes | yes (manage) |
| Prices | `platform.plan.view` | `platform.plan_price.manage` | yes | yes (manage) |
| Entitlements | `platform.plan.view` | `platform.plan.manage` | yes | yes (manage) |
| Preferred-personnel fee | `platform.preferred_personnel_fee.manage` | `platform.preferred_personnel_fee.manage` | yes | yes (mutations) |

Backend security boundary: `ResolvePlatformContext` + `EnsurePermission` + policies +
mandatory MFA + `StepUpAction::BillingConfiguration` fresh step-up on sensitive mutations.
Reads never require a fresh step-up.

## API dependencies

- `GET /api/v1/me` (bootstrap: identity, permissions).
- General/billing settings: `GET|PUT /platform/settings`, `GET|PUT /platform/billing-settings`.
- Plans: `GET /platform/plans`, `GET /platform/plans/{plan}`, `POST /platform/plans`,
  `PUT /platform/plans/{plan}`, `POST /platform/plans/{plan}/retire`.
- Prices: `GET /platform/plans/{plan}/prices`, `POST /platform/plans/{plan}/prices`,
  `POST /platform/plan-prices/{planPrice}/cancel`.
- Entitlements: `GET|PUT /platform/plans/{plan}/entitlements`.
- Preferred fee: `GET /platform/preferred-personnel-fee-rules`,
  `GET /platform/preferred-personnel-fee-rules/{id}`, `POST …`, `POST …/{id}/approve`,
  `POST …/{id}/supersede`, `POST …/{id}/cancel`.

Financial/effective-dated creates send an `Idempotency-Key` header (settings update,
billing-settings update, price create, fee-rule create, fee-rule supersede).

## Fields and actions

- **General settings:** allowlisted documented keys only (`invoice_due_days`,
  `support_email`, `statement_footer`) edited as strings — **no arbitrary JSON**. Save →
  next effective version.
- **Billing settings:** `billing_mode` (three canonical modes), `default_trial_days`,
  `grace_days`, `currency`. Save → next effective version (history preserved).
- **Plans:** list (status filter), create (`key` immutable, `name`, `description`, `tier`,
  `sort_order`), edit metadata, retire (confirm; history preserved). **No price columns.**
- **Prices:** effective-dated table (amount minor, interval of five, `effective_from/to`,
  lifecycle `current`/`historical`/`future`). Schedule price; cancel **future** only.
  Current/historical rows read-only. Amount entered in major units → integer minor units.
- **Entitlements:** per selected plan — `entitlement_key`, `enabled`, `limit_int`
  (empty = unlimited). Add/remove/toggle; save replaces the set. **No merchant-subscription
  binding.**
- **Preferred-personnel fee:** list (scope/status filters); create draft (fixed XOR
  percentage; basis points 0–10000; platform_default forbids service, service scope requires
  a service ULID; `calculation_basis`; `effective_from/to`; `change_reason`); approve; **supersede**
  active (new version — active terms are read-only); cancel draft/scheduled only. Terminal
  states (superseded/expired/cancelled) expose no controls.

## Canonical enums

- Billing modes: `fixed_amount`, `percentage_on_merchant_client_invoice`,
  `fixed_amount_plus_percentage_on_merchant_client_invoice`.
- Billing intervals: `weekly`, `bi_weekly`, `monthly`, `quarterly`, `annual`.

## States

- **Loading / empty / error / success:** every list/form via `SvStateBoundary`.
- **No permission:** the whole screen shows an access note when no tab is viewable;
  individual tabs and mutation controls are absent when denied.
- **MFA required / fresh step-up required / stale step-up:** the server rejects the mutation;
  the section surfaces the "a fresh step-up is required" guidance and the user completes the
  existing MFA/step-up challenge (`platform.mfa-challenge` flow).
- **Validation failure:** field errors from the structured envelope (`error.fields`) render
  under the corresponding inputs; `role="alert"` summary for the action error.
- **Overlap conflict:** `409` (`invalid_state_transition`/`duplicate_reference`) on price or
  fee-rule create surfaces an explicit "overlaps an existing effective range/active rule"
  message.
- **Idempotency conflict:** duplicate key surfaces the server message; no double-submit.
- **Read-only historical / current price / active fee terms:** rendered read-only with an
  explicit label; no edit control.
- **Scheduled / active / retired / superseded / cancelled:** status badges; controls derive
  from status + capability.

## Responsive / theme / accessibility

- **360 / 768 / 1280 px:** container `max-w-4xl`; tab bar wraps; price table scrolls inside an
  `overflow-x-auto` container (no page-level horizontal overflow); form grids collapse to one
  column on mobile.
- **Keyboard:** ARIA `tablist`/`tab`/`tabpanel` with roving `tabindex`; Arrow/Home/End move
  focus; Enter/Space/click select; dialogs (`SvModal`) trap focus and restore on close; 44px
  targets.
- **Screen reader:** each section is a labelled `<section>`; tables have `<caption>` (sr-only)
  and scoped headers; action errors use `role="alert"`.
- **Dark mode:** light + dark via design tokens; AA contrast (ADR-009 — no white text on the
  Savannah-Orange CTA).

## Audit events (server-emitted; UI never asserts the chain)

`platform.settings.changed`, `platform.billing_settings.changed`, `plan.created`,
`plan.metadata_changed`, `plan.retired`, `plan_price.created`, `plan_price.cancelled`,
`plan.entitlements_changed`, `preferred_personnel_fee_rule.created`,
`preferred_personnel_fee_rule.approved`, `preferred_personnel_fee_rule.superseded`,
`preferred_personnel_fee_rule.cancelled`. Context is redacted and uses public ULIDs.

## Branch Manager read-only integration (no separate screen)

The branch-scoped **effective** preferred-personnel fee is surfaced read-only inside the
existing Branch service catalogue (`branch.services`) via `BranchPreferredFeeCard.vue`
(store `branchPreferredFeeStore`, `GET /branch/preferred-personnel-fee-rule`, permission
`preferred_personnel_fee.view_branch_rule`). No mutation controls, no draft/scheduled data,
no platform navigation, no MFA/step-up UX. Per Plan §27.1 / task §5.9 no new top-level screen
is invented.

## Tests

- Component: `resources/spa/src/pages/platform/BillingSettings.spec.ts` and per-section specs.
- Store: `resources/spa/src/stores/*.spec.ts` for the five platform stores + branch store.
- Navigation/inventory parity: `roleNavigation.spec.ts`, `screenInventory.spec.ts`.
- E2E: `tests/e2e/phase-20a-billing.spec.ts` (nav visibility, denial, MFA/step-up, flows,
  overlap, read-only, Branch Manager read-only, responsive/dark/keyboard/axe).
