# Screen specification — Global search

> Generated from `docs/frontend/screens/inventory.json` (Plan §27.1). Status: **implemented** · Owning phase: **Phase 22**. Edit the inventory + regenerate (`node scripts/generate-screen-specs.mjs`); the owning phase writes the final detailed spec before implementing future behavior.

- **Screen key:** `global-search`
- **Route name and URL:** `search`
- **Layout:** `Standalone`
- **Allowed roles:** `merchant_administrator`, `merchant_branch`, `merchant_human_resource`, `merchant_finance`, `merchant_front_office`, `merchant_personnel`, `merchant_audit`
- **Required permissions:** — (frontend visibility only; backend EnsurePermission + policy is authoritative)
- **Merchant / branch / own scope:** per role boundary (Plan §14–§16); branch-scoped roles resolve branch from the bootstrap.
- **Required entitlement:** none for the Phase 11 foundation; entitlement gating applies in the owning feature phase.
- **Billing-state behavior:** read-only-grace and suspended-billing follow the §19.2 allowlist; foundation surfaces are read-only.
- **API dependencies:** `GET /api/v1/me` bootstrap; plus this screen’s existing endpoints.
- **Fields and displayed data:** Cross-domain search over the records the authenticated user can ALREADY reach (Plan §68). A single term queries a fixed catalogue of document types — clients, staff, appointments, queue entries, service sessions, invoices, receipts, and a Personnel member's own served clients — and each type is admitted only after the server proves the caller holds the authority governing that type's own list/detail route (decision D-22-01). The screen therefore lists no permission of its own: `GET /api/v1/search` grants access to nothing, and a role with no searchable authority sees the empty state rather than a 403, which would confirm which types exist. Every filter is built server-side from the authenticated membership — the browser sends only the term and, optionally, a type list, an own-branch narrowing filter, an allowlisted sort and a bounded limit; the 21 scope/permission/engine forgery fields are rejected with 422. Results are masked by omission: the response schema has NO contact field of any kind — no phone, masked phone, phone last four, email or masked email — even for the types whose own Resources return masked contact today (decision D-22-03). An authorized EXACT phone lookup exists for Front Office through the existing keyed blind index, returns the client name only, never reaches the search engine, and never echoes the number back; a partial phone fragment is not searchable anywhere. NO export, download, print or clipboard-copy control exists on this screen and none may ever be added (ADR-010; Plan §19.4 non-overridable); nothing is persisted to localStorage or sessionStorage; and held results are cleared on any membership or branch-scope change.
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
