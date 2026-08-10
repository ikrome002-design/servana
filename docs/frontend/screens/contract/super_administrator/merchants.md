# Screen specification — Merchant Directory

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> A real runtime route renders this page today: `platform.merchants` at `/merchants` (routes/platform.ts), delivery **dedicated**.

## Identity

- **Account:** super_administrator
- **Host:** `citrus.servana.ke`
- **Page title:** Merchant Directory
- **Route:** `/merchants` (host-relative contract path)
- **Route name:** `platform.merchants`
- **Navigation group:** Merchants
- **Navigation placement:** header primary navigation
- **Contract key:** `super_administrator.merchants`
- **Screen key:** `merchants`
- **Authoritative map section:** §5.4.11

## Purpose

- **Purpose:** Provide a searchable platform-wide directory of self-registered merchants for governance and billing oversight.
- **User story:** As super administrator, I open Merchant Directory so that provide a searchable platform-wide directory of self-registered merchants for governance and billing oversight.

## Ownership and status

- **UI owner phase:** **UI-08**
- **Backend owner phase:** **Phase 20B**
- **Implementation status:** `implemented`
- **Runtime route:** `platform.merchants`
- **Route delivery:** `dedicated`
- **External gate:** none

## Data and behaviour

- **API dependencies:** `GET /api/v1/me` bootstrap plus the endpoints already backing `platform.merchants` (recorded in `docs/frontend/screens/platform/platform-merchants.md`).
- **Data fields:** Contract page §5.4.11. Platform-wide directory of self-registered merchants. A row is a LINK to the merchant detail route, so a merchant record can be opened, bookmarked and shared; there is no embedded detail pane and no governance control here. No create-merchant, first-administrator, impersonation, membership, branch-creation or staff-creation control exists. Search, the plan, billing-mode, trial-cohort, overdue and risk filters, saved filters and a masked export have no backing operation and are named as unavailable rather than offered.
- **Filters:** As delivered by the runtime screen; preserved across list → detail → back.
- **Sorts:** As delivered by the runtime screen; deterministic and server-authoritative.
- **Pagination:** Every collection paginates (Plan §9 rule 10).
- **Primary action:** As delivered by the runtime screen; one visually dominant primary action per page.
- **Secondary actions:** As delivered by the runtime screen.

## Authorization

- **Authorization:** Backend `auth:sanctum` + Form Request + Policy + `EnsurePermission` is the security boundary. Everything below is UX visibility only (ADR-017).
- **Permission-any:** — none
- **Permission-all:** `platform.merchant.view`
- **Tenant scope:** Platform-only; merchant users are refused without record enumeration.
- **Branch scope:** Not branch-scoped.
- **Own-scope:** Not own-scoped.
- **MFA:** Required for this account.
- **Step-up:** No route-level step-up requirement; individual mutations may still require it server-side.
- **Feature flag:** none
- **Forbidden for:** `merchant_administrator`, `merchant_branch`, `merchant_human_resource`, `merchant_finance`, `merchant_front_office`, `merchant_personnel`, `merchant_audit`

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
- **Icon:** `merchant` (Heroicons, resolved through the curated navigation icon registry — no emoji, no runtime arbitrary lookup).
- **Navigation visibility:** `primary`
- **Non-navigation reason:** not applicable — this page appears in primary navigation.

## Evidence

- **Audit events:** Mutations emit append-only hash-chained `audit_logs` entries; coverage is asserted by `AuditMutationCoverage`.
- **Analytics events:** No third-party analytics runtime exists in Servana.
- **Tests:** Route parity `Ui07RouteParityTest`; contract `Ui07NavigationRegistryContractTest`; account guard `Ui07AccountRouteGuardCoverageTest`; runtime navigation `navigationFilter.spec.ts`; browser `tests/e2e/ui-07-navigation-screen-contracts.spec.ts`.
- **Screenshot requirements:** Owner phase **UI-08** captures this page; UI-07 captures rendered navigation states only.
