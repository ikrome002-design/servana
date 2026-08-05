# Screen specification — My Earnings — Overview

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> A real runtime route renders this page today: `personnel.earnings` at `/personnel/earnings` (routes/personnel.ts), delivery **dedicated**. The runtime path uses the account's path prefix rather than the host-relative contract path `/earnings`; owner phase **UI-14** reconciles path shape (`UI01-ROUTE-003`).

## Identity

- **Account:** merchant_personnel
- **Host:** `staff.servana.ke`
- **Page title:** My Earnings — Overview
- **Route:** `/earnings` (host-relative contract path)
- **Route name:** `personnel.earnings`
- **Navigation group:** My Earnings
- **Navigation placement:** sidebar primary navigation
- **Contract key:** `merchant_personnel.earnings`
- **Screen key:** `earnings`
- **Authoritative map section:** §11.4.11

## Purpose

- **Purpose:** Answer how the logged-in Personnel member is paid, what is pending, what is earned/accrued, and what has been paid.
- **User story:** As merchant personnel, I open My Earnings — Overview so that answer how the logged-in Personnel member is paid, what is pending, what is earned/accrued, and what has been paid.

## Ownership and status

- **UI owner phase:** **UI-14**
- **Backend owner phase:** **Phase 20H**
- **Implementation status:** `implemented`
- **Runtime route:** `personnel.earnings`
- **Route delivery:** `dedicated`
- **External gate:** none

## Data and behaviour

- **API dependencies:** `GET /api/v1/me` bootstrap plus the endpoints already backing `personnel.earnings` (recorded in `docs/frontend/screens/personnel/personnel-my-earnings.md`).
- **Data fields:** Personnel own-scope earnings: a per-currency overview (net/unpaid/paid, with salary and commission breakdowns shown only when the compensation model or historical facts apply; conflicting plans fail closed), compensation terms, payout history, on-demand earnings-statement generation/download through Servana's authorised short-lived file link (own-scope by owner; billing read-only blocks new generation, not an existing download), and raising an earnings query about one of the personnel's own facts (Finance responds; a correction is a separate adjustment). The acting staff profile is derived from the membership — there is no staff selector and the browser never sends a staff reference. No other staff data, no payout controls, no money movement, no raw storage paths, no Wallet/provider UI.
- **Filters:** As delivered by the runtime screen; preserved across list → detail → back.
- **Sorts:** As delivered by the runtime screen; deterministic and server-authoritative.
- **Pagination:** Every collection paginates (Plan §9 rule 10).
- **Primary action:** As delivered by the runtime screen; one visually dominant primary action per page.
- **Secondary actions:** As delivered by the runtime screen.

## Authorization

- **Authorization:** Backend `auth:sanctum` + Form Request + Policy + `EnsurePermission` is the security boundary. Everything below is UX visibility only (ADR-017).
- **Permission-any:** — none
- **Permission-all:** `personnel.my_earnings.view`
- **Tenant scope:** Merchant-scoped via `BelongsToMerchant`; foreign ULIDs resolve to 404.
- **Branch scope:** Not branch-scoped.
- **Own-scope:** Strictly own-scope; served-client data is masked and **contact export does not exist in any format** (Plan §10.2).
- **MFA:** Not required for this account.
- **Step-up:** No route-level step-up requirement; individual mutations may still require it server-side.
- **Feature flag:** none
- **Forbidden for:** `super_administrator`, `merchant_administrator`, `merchant_branch`, `merchant_human_resource`, `merchant_finance`, `merchant_front_office`, `merchant_audit`

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
- **Icon:** `earnings` (Heroicons, resolved through the curated navigation icon registry — no emoji, no runtime arbitrary lookup).
- **Navigation visibility:** `primary`
- **Non-navigation reason:** not applicable — this page appears in primary navigation.

## Evidence

- **Audit events:** Mutations emit append-only hash-chained `audit_logs` entries; coverage is asserted by `AuditMutationCoverage`.
- **Analytics events:** No third-party analytics runtime exists in Servana.
- **Tests:** Route parity `Ui07RouteParityTest`; contract `Ui07NavigationRegistryContractTest`; account guard `Ui07AccountRouteGuardCoverageTest`; runtime navigation `navigationFilter.spec.ts`; browser `tests/e2e/ui-07-navigation-screen-contracts.spec.ts`.
- **Screenshot requirements:** Owner phase **UI-14** captures this page; UI-07 captures rendered navigation states only.
