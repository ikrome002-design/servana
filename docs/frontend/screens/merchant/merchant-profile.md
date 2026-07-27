# Screen specification — Business profile

> Generated from `docs/frontend/screens/inventory.json` (Plan §27.1). Status: **implemented** · Owning phase: **Phase 23**. Edit the inventory + regenerate (`node scripts/generate-screen-specs.mjs`); the owning phase writes the final detailed spec before implementing future behavior.

- **Screen key:** `merchant-profile`
- **Route name and URL:** `merchant.profile`
- **Layout:** `MerchantLayout`
- **Allowed roles:** `merchant_administrator`
- **Required permissions:** `merchant.profile.view`, `merchant.profile.update` (frontend visibility only; backend EnsurePermission + policy is authoritative)
- **Merchant / branch / own scope:** per role boundary (Plan §14–§16); branch-scoped roles resolve branch from the bootstrap.
- **Required entitlement:** none for the Phase 11 foundation; entitlement gating applies in the owning feature phase.
- **Billing-state behavior:** read-only-grace and suspended-billing follow the §19.2 allowlist; foundation surfaces are read-only.
- **API dependencies:** `GET /api/v1/me` bootstrap; plus this screen’s existing endpoints.
- **Fields and displayed data:** Merchant Administrator views and edits the merchant BUSINESS profile — business category, contact phone/email, receipt display name, address, town and timezone — and sees the current logo. Delivered by REM-SCR-002A as a corrective remediation for a Plan §27.3 launch screen omitted by its owning phase. Business name, slug, country, service-fee tier and every billing/lifecycle column are read-only context; the logo is uploaded through the existing Phase 10F scanned pipeline, never a second path.
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
