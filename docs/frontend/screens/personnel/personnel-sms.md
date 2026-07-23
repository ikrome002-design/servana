# Screen specification — Client SMS composer

> Generated from `docs/frontend/screens/inventory.json` (Plan §27.1). Status: **implemented** · Owning phase: **Phase 21S**. Edit the inventory + regenerate (`node scripts/generate-screen-specs.mjs`); the owning phase writes the final detailed spec before implementing future behavior.

- **Screen key:** `personnel-sms`
- **Route name and URL:** `personnel.sms`
- **Layout:** `PersonnelLayout`
- **Allowed roles:** `merchant_personnel`
- **Required permissions:** `personnel.my_served_clients.view`, `personnel.my_sms.send` (frontend visibility only; backend EnsurePermission + policy is authoritative)
- **Merchant / branch / own scope:** per role boundary (Plan §14–§16); branch-scoped roles resolve branch from the bootstrap.
- **Required entitlement:** none for the Phase 11 foundation; entitlement gating applies in the owning feature phase.
- **Billing-state behavior:** read-only-grace and suspended-billing follow the §19.2 allowlist; foundation surfaces are read-only.
- **API dependencies:** `GET /api/v1/me` bootstrap; plus this screen’s existing endpoints.
- **Fields and displayed data:** Personnel own-scope bulk SMS to clients this staff member PERSONALLY SERVED (at least one completed service session performed by them, in the acting merchant + branch). The screen shows a paginated, name-searchable served-client list with MASKED contact only (`••• ••• 1234`), recipient selection bounded by the configured max batch, a composer whose character count, segment count, excluded-recipient reason codes and estimated KES cost all come from the server preview (the browser derives none of them), a billing notice, an explicit confirmation dialog, and the resulting campaign statuses with per-recipient outcomes. The acting staff profile is derived from the membership — there is no staff selector and the browser never sends a staff reference, a cost or a recipient count. The served-client READ (`personnel.my_served_clients.view`) survives billing read-only grace; SENDING (`personnel.my_sms.send`) additionally requires the `sms` plan entitlement and is blocked in read-only grace / suspended billing, with both refusals rendered as actionable copy rather than raw codes. NO contact export exists anywhere on this screen — no export, download, print, clipboard-copy or phone-list control, no full phone in state, storage or a URL, and no such control may ever be added (ADR-010; Plan §19.4 non-overridable).
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
