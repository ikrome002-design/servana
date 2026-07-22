# Screen specification — My Earnings

> Generated from `docs/frontend/screens/inventory.json` (Plan §27.1). Status: **implemented** · Owning phase: **Phase 20H**. Edit the inventory + regenerate (`node scripts/generate-screen-specs.mjs`); the owning phase writes the final detailed spec before implementing future behavior.

- **Screen key:** `personnel-my-earnings`
- **Route name and URL:** `personnel.earnings`
- **Layout:** `PersonnelLayout`
- **Allowed roles:** `merchant_personnel`
- **Required permissions:** `personnel.my_earnings.view`, `personnel.my_compensation.view`, `personnel.my_payouts.view`, `personnel.my_statements.download`, `personnel.my_earnings_query.create` (frontend visibility only; backend EnsurePermission + policy is authoritative)
- **Merchant / branch / own scope:** per role boundary (Plan §14–§16); branch-scoped roles resolve branch from the bootstrap.
- **Required entitlement:** none for the Phase 11 foundation; entitlement gating applies in the owning feature phase.
- **Billing-state behavior:** read-only-grace and suspended-billing follow the §19.2 allowlist; foundation surfaces are read-only.
- **API dependencies:** `GET /api/v1/me` bootstrap; plus this screen’s existing endpoints.
- **Fields and displayed data:** Personnel own-scope earnings: a per-currency overview (net/unpaid/paid, with salary and commission breakdowns shown only when the compensation model or historical facts apply; conflicting plans fail closed), compensation terms, payout history, on-demand earnings-statement generation/download through Servana's authorised short-lived file link (own-scope by owner; billing read-only blocks new generation, not an existing download), and raising an earnings query about one of the personnel's own facts (Finance responds; a correction is a separate adjustment). The acting staff profile is derived from the membership — there is no staff selector and the browser never sends a staff reference. No other staff data, no payout controls, no money movement, no raw storage paths, no Wallet/provider UI.
- **Primary / secondary / destructive actions:** navigation and (where live) the screen’s existing actions; destructive actions require typed confirmation (Plan §31). No future-phase actions are live.
- **Confirmation behavior:** destructive/financial confirmations show readable amounts; legal acknowledgement requires explicit, non-prefilled consent.
- **Loading / empty / error / success states:** via `SvStateBoundary`; landing/get-started show useful empty states.
- **No-permission / no-branch states:** permissioned controls hidden via `PermissionGate`; branch-scoped roles show a no-branch boundary.
- **Locked / read-only / suspended-billing states:** read-only foundation; suspended/locked handled by the owning phase.
- **Mobile / tablet / desktop transformation:** responsive at 360 / 768 / 1280; merchant roles use sidebar (desktop) + drawer (mobile); Super Administrator uses header nav collapsing to a disclosure.
- **Keyboard and screen-reader behavior:** skip link, landmarks, visible focus, 44px targets, aria-current on the active nav item, drawer focus return.
- **Dark-mode requirements:** light + dark via design tokens; AA contrast (ADR-009: no white text on Savannah-Orange CTA).
- **Audit events triggered:** none new in Phase 11.
- **Unit / component / e2e tests:** see `resources/spa/src/**/*.spec.ts` and `tests/e2e/role-*.spec.ts` (navigation parity, role entry routes, get-started persistence, landing content, layout placement, responsive/dark/axe).
