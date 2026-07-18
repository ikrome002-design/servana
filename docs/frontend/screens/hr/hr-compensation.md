# Screen specification — Compensation

> Generated from `docs/frontend/screens/inventory.json` (Plan §27.1). Status: **implemented** · Owning phase: **Phase 20F**. Edit the inventory + regenerate (`node scripts/generate-screen-specs.mjs`); the owning phase writes the final detailed spec before implementing future behavior.

- **Screen key:** `hr-compensation`
- **Route name and URL:** `hr.compensation`
- **Layout:** `BranchLayout`
- **Allowed roles:** `merchant_human_resource`
- **Required permissions:** `compensation.plan.view`, `compensation.plan.create`, `compensation.plan.update_draft`, `compensation.plan.submit`, `compensation.plan.approve`, `compensation.plan.reject`, `compensation.plan.cancel`, `compensation.history.view` (frontend visibility only; backend EnsurePermission + policy is authoritative)
- **Merchant / branch / own scope:** per role boundary (Plan §14–§16); branch-scoped roles resolve branch from the bootstrap.
- **Required entitlement:** none for the Phase 11 foundation; entitlement gating applies in the owning feature phase.
- **Billing-state behavior:** read-only-grace and suspended-billing follow the §19.2 allowlist; foundation surfaces are read-only.
- **API dependencies:** `GET /api/v1/me` bootstrap; plus this screen’s existing endpoints.
- **Fields and displayed data:** Branch-scoped, HR-only compensation-plan and commission-rule configuration: plan list with status/backdated/pending-approval indicators, plan detail with append-only history, commission-rule and plan draft forms (F1 model shape, F4 value shape, preferred-personnel-fee basis inclusion), and the named submit/approve/reject/cancel transitions (approval requires a fresh step-up and a different approver). Phase 20G §9.1: a selected_services commission-rule draft shows a branch-scoped service multi-select whose options load from the narrow compensation-scoped GET /commission-rule-service-options endpoint (authorized by compensation.plan.view, never service.view which HR cannot hold; returns {ulid,name} for the acting branch's active services only) — at least one service required, add/remove only while draft, server-returned selections hydrate, stale selection cleared when applies_to changes, non-draft memberships read-only; selected_service_ulids are submitted and the server persists immutable draft memberships. Configuration only — no earned commission, salary ledger, commission ledger, payout, earnings statement, liability or Wallet/provider surface exists here.
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
