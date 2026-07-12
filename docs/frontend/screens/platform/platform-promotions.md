# Screen specification — Promotions / free periods (platform)

> Plan §27.1 · §53; Phase 20C. Owning-phase authored spec (not the generator stub). Status:
> **implemented** · Owning phase: **Phase 20C**. Source of truth for this screen; the inventory entry
> `platform-promotions` points here.

## Identity

- **Screen key:** `platform-promotions`
- **Route name and URL:** `platform.promotions` → `/platform/promotions`
- **Layout:** `PlatformAdminLayout` (Super Administrator header shell; nested under `/platform`)
- **Component:** `resources/spa/src/pages/platform/Promotions.vue` (self-contained; two sections).
- **Allowed roles:** `super_administrator` only.
- **Platform scope:** platform-only. Routes use `ResolvePlatformContext`; **no merchant or branch
  context is established**. The offer records are platform-owned (no `merchant_id`/`branch_id`); a target
  row may reference a merchant, but the offer itself is platform configuration.

## Permissions (frontend visibility only — API is authoritative)

One coherent screen with two accessible sections (tabs); each section is **absent** (not disabled) when
its manage permission is not resolved. Every mutation additionally requires MFA + a fresh step-up, and a
mandatory reason, enforced by the server.

| Section | Manage permission | MFA | Fresh step-up | Audit severity |
|---|---|---|---|---|
| Promotional discounts | `platform.promotion.manage` | yes | yes (`billing_configuration`) | high |
| Free-period offers | `platform.free_period_offer.manage` | yes | yes (`billing_configuration`) | high |

No merchant role receives either permission. When neither is present the page renders a **"No access"**
empty state.

## Behaviour

### Promotional discounts

- **List + filter** by status (`draft`/`scheduled`/`active`/`paused`/`expired`/`cancelled`).
- **Draft create/edit form:** name; type (`percentage` shows a basis-points value, `fixed_amount` shows
  a KES minor-unit value + currency); target scope (`all_new_merchants`/`selected_merchants`/
  `selected_plans`/`billing_mode`); a scope-driven target builder (merchant/plan ULIDs, or a
  billing-mode select); effective window (`effective_from` required, `effective_to` optional). Percentage
  values are basis points (≤10000 = 100%); money is integer minor units, never float.
- **Lifecycle actions by status:** `approve`/`cancel` (draft, scheduled), `pause` (active), `resume`
  (paused). Each opens a **reason modal** (mandatory reason; MFA/step-up + `invalid_state_transition`
  errors surfaced from the server). Approved terms and targets are immutable — a change requires a new
  record.

### Free-period offers

- Same list/create/lifecycle shape with `free_period_days` (1–365) instead of type/value/currency.
- **Approval schedules the offer** (never straight to active); activation is scheduler-driven.

### States

Loading, empty (per section), validation errors, server-conflict/step-up errors (surfaced in the reason
modal), and a no-access state. Controls the user cannot perform are absent, never disabled.

## Data contracts (generated OpenAPI/TS)

- `GET/POST /api/v1/platform/promotional-discounts`, `GET/PATCH /{promotionalDiscount}`,
  `POST /{promotionalDiscount}/{approve|pause|resume|cancel}` → `PromotionalDiscountResource`.
- `GET/POST /api/v1/platform/free-period-offers`, `GET/PATCH /{freePeriodOffer}`,
  `POST /{freePeriodOffer}/{approve|pause|resume|cancel}` → `FreePeriodOfferResource`.
- Resources expose ULIDs only (targets by their own ULID + referenced merchant/plan ULID or billing-mode
  value); never internal ids or `created_by`/`approved_by`.

## Merchant read-only presentation (elsewhere)

Merchant subscription/invoice surfaces show the **applied** snapshot read-only: the subscription
dashboard exposes `trial_days_snapshot` + `free_period_offer_applied`; a subscription invoice exposes
`promotion_applied` + snapshotted type/value/currency alongside subtotal/discount/total. Merchant users
get no promotion-management control.

## Accessibility & responsive (release gates)

Labelled inputs, visible focus rings, ≥44px targets, `role="tablist"`/`role="tab"` sections, dialog focus
management + restoration (Escape closes), AA contrast in light + dark, axe serious/critical = 0, no
page-level horizontal overflow at 360/768/1280, usable at 200% zoom.

## Absolute exclusions

No Wallet/provider/payment/STK/PayBill control; no percentage-fee ledger; no merchant creation, first-
admin creation, or impersonation; no generic status route; no destructive edits to issued invoices or
existing trials.

## Unit / component / e2e tests

`resources/spa/src/stores/promotionStore.spec.ts`, `freePeriodOfferStore.spec.ts`,
`resources/spa/src/pages/platform/Promotions.spec.ts`, and `tests/e2e/phase-20c.spec.ts` (create flows,
target inputs by scope, reason modal, status rendering, role gate, responsive/zoom/keyboard/dark/axe).
