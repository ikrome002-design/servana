# Screen specification — Merchant detail and governance

> Generated from `docs/frontend/screens/inventory.json` (Plan §27.1). Status: **implemented** · Owning phase: **Phase 20B**. Edit the inventory + regenerate (`node scripts/generate-screen-specs.mjs`); the owning phase writes the final detailed spec before implementing future behavior.

- **Screen key:** `platform-merchant-detail`
- **Route name and URL:** `platform.merchant-detail`
- **Layout:** `PlatformAdminLayout`
- **Allowed roles:** `super_administrator`
- **Required permissions:** `platform.merchant.view`, `platform.merchant.suspend`, `platform.merchant.reactivate`, `platform.merchant.deactivate` (frontend visibility only; backend EnsurePermission + policy is authoritative)
- **Merchant / branch / own scope:** per role boundary (Plan §14–§16); branch-scoped roles resolve branch from the bootstrap.
- **Required entitlement:** none for the Phase 11 foundation; entitlement gating applies in the owning feature phase.
- **Billing-state behavior:** read-only-grace and suspended-billing follow the §19.2 allowlist; foundation surfaces are read-only.
- **API dependencies:** `GET /api/v1/me` bootstrap; plus this screen’s existing endpoints.
- **Fields and displayed data:** Contract page §5.4.12. Governs one merchant, resolved from the route ULID on mount and on every parameter change, never from a row selected in the directory. Operational status and billing status are two separate, prominently labelled cards, and a governance action changes the operational status ONLY: a billing suspension is cleared by the billing lifecycle, never by reactivation here. Suspend, reactivate and deactivate each require a mandatory reason, an impact preview, explicit confirmation, MFA and a fresh server-enforced merchant_governance step-up. An unknown and a refused merchant render the SAME message, so a URL cannot enumerate the platform. No impersonation, setup completion, branch or staff creation, invoice, payment, receipt or queue action exists.
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
