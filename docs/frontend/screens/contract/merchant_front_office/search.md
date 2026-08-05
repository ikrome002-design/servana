# Screen specification — Operational Search

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> A real runtime route renders this page today: `search` at `/search` (routes/search.ts), delivery **cross_account_utility**.

## Identity

- **Account:** merchant_front_office
- **Host:** `office.servana.ke`
- **Page title:** Operational Search
- **Route:** `/search` (host-relative contract path)
- **Route name:** `front-office.search`
- **Navigation group:** Quick Access
- **Navigation placement:** sidebar primary navigation
- **Contract key:** `merchant_front_office.search`
- **Screen key:** `search`
- **Authoritative map section:** §10.4.3

## Purpose

- **Purpose:** Find branch-scoped operational records rapidly without exposing unauthorized or cross-branch data.
- **User story:** As merchant front office, I open Operational Search so that find branch-scoped operational records rapidly without exposing unauthorized or cross-branch data.

## Ownership and status

- **UI owner phase:** **UI-13**
- **Backend owner phase:** **Phase 22**
- **Implementation status:** `implemented`
- **Runtime route:** `search`
- **Route delivery:** `cross_account_utility`
- **External gate:** none

## Data and behaviour

- **API dependencies:** `GET /api/v1/me` bootstrap plus the endpoints already backing `search` (recorded in `docs/frontend/screens/search/global-search.md`).
- **Data fields:** Cross-domain search over the records the authenticated user can ALREADY reach (Plan §68). A single term queries a fixed catalogue of document types — clients, staff, appointments, queue entries, service sessions, invoices, receipts, and a Personnel member's own served clients — and each type is admitted only after the server proves the caller holds the authority governing that type's own list/detail route (decision D-22-01). The screen therefore lists no permission of its own: `GET /api/v1/search` grants access to nothing, and a role with no searchable authority sees the empty state rather than a 403, which would confirm which types exist. Every filter is built server-side from the authenticated membership — the browser sends only the term and, optionally, a type list, an own-branch narrowing filter, an allowlisted sort and a bounded limit; the 21 scope/permission/engine forgery fields are rejected with 422. Results are masked by omission: the response schema has NO contact field of any kind — no phone, masked phone, phone last four, email or masked email — even for the types whose own Resources return masked contact today (decision D-22-03). An authorized EXACT phone lookup exists for Front Office through the existing keyed blind index, returns the client name only, never reaches the search engine, and never echoes the number back; a partial phone fragment is not searchable anywhere. NO export, download, print or clipboard-copy control exists on this screen and none may ever be added (ADR-010; Plan §19.4 non-overridable); nothing is persisted to localStorage or sessionStorage; and held results are cleared on any membership or branch-scope change.
- **Filters:** As delivered by the runtime screen; preserved across list → detail → back.
- **Sorts:** As delivered by the runtime screen; deterministic and server-authoritative.
- **Pagination:** Every collection paginates (Plan §9 rule 10).
- **Primary action:** As delivered by the runtime screen; one visually dominant primary action per page.
- **Secondary actions:** As delivered by the runtime screen.

## Authorization

- **Authorization:** Backend `auth:sanctum` + Form Request + Policy + `EnsurePermission` is the security boundary. Everything below is UX visibility only (ADR-017).
- **Permission-any:** — none
- **Permission-all:** — none
- **Tenant scope:** Merchant-scoped via `BelongsToMerchant`; foreign ULIDs resolve to 404.
- **Branch scope:** Branch-scoped via `BelongsToBranch`; the branch is resolved from the server bootstrap, never from the URL.
- **Own-scope:** Not own-scoped.
- **MFA:** Not required for this account.
- **Step-up:** No route-level step-up requirement; individual mutations may still require it server-side.
- **Feature flag:** none
- **Forbidden for:** `super_administrator`, `merchant_administrator`, `merchant_branch`, `merchant_human_resource`, `merchant_finance`, `merchant_personnel`, `merchant_audit`

## States

- **Loading state:** Skeleton via `SvStateBoundary`.
- **Empty state:** Actionable empty state naming the next step.
- **Error state:** Retryable error state; the structured error envelope of Plan §11.5.
- **Stale-data state:** Near-real-time surfaces show the observation time and a manual refresh.
- **Offline state:** Offline notice; no silent write loss.
- **No-permission state:** Permissioned controls are hidden via `PermissionGate`; the API remains authoritative.
- **Suspended state:** Billing suspension and operational suspension follow the §19.2 allowlist.
- **Locked-period state:** Locked financial periods render read-only and explain why the action is unavailable.
- **Billing-state behaviour:** `per_account_billing_state_allowlist` — trialing, active, overdue, read_only_grace, suspended_billing, operational suspension and deactivation follow the account allowlist.
- **Entitlement behaviour:** Entitlement gating is enforced server-side by the owning feature phase.

## Presentation

- **Responsive behaviour:** Mobile ≤767 / tablet 768–1024 / desktop ≥1025 via CSS media queries only; tables become labelled cards on mobile without horizontal scrolling.
- **Accessibility behaviour:** Labels, landmarks, visible focus, 44px targets, `aria-current` on the active navigation item, AA contrast in light and dark.
- **Icon:** `search` (Heroicons, resolved through the curated navigation icon registry — no emoji, no runtime arbitrary lookup).
- **Navigation visibility:** `primary`
- **Non-navigation reason:** not applicable — this page appears in primary navigation.

## Evidence

- **Audit events:** Mutations emit append-only hash-chained `audit_logs` entries; coverage is asserted by `AuditMutationCoverage`.
- **Analytics events:** No third-party analytics runtime exists in Servana.
- **Tests:** Route parity `Ui07RouteParityTest`; contract `Ui07NavigationRegistryContractTest`; account guard `Ui07AccountRouteGuardCoverageTest`; runtime navigation `navigationFilter.spec.ts`; browser `tests/e2e/ui-07-navigation-screen-contracts.spec.ts`.
- **Screenshot requirements:** Owner phase **UI-13** captures this page; UI-07 captures rendered navigation states only.
