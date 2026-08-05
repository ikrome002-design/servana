# Screen specification — Subscription Operations

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> No runtime page implementation is active. UI-07 registers the contract identity only: **no Vue Router record and no navigation link is exposed**. Owner phase **UI-08** implements it.

## Identity

- **Account:** super_administrator
- **Host:** `citrus.servana.ke`
- **Page title:** Subscription Operations
- **Route:** `/billing/subscriptions` (host-relative contract path)
- **Route name:** `platform.billing-subscriptions`
- **Navigation group:** Billing Operations
- **Navigation placement:** header primary navigation
- **Contract key:** `super_administrator.billing-subscriptions`
- **Screen key:** `billing-subscriptions`
- **Authoritative map section:** §5.4.13

## Purpose

- **Purpose:** Monitor platform-wide merchant subscription lifecycles, issued invoices, overdue escalation, credits, and recovery outcomes.
- **User story:** As super administrator, I open Subscription Operations so that monitor platform-wide merchant subscription lifecycles, issued invoices, overdue escalation, credits, and recovery outcomes.

## Ownership and status

- **UI owner phase:** **UI-08**
- **Backend owner phase:** Not yet proven in the current repository. The owner phase must resolve this before changing `implementation_status` to `implemented`.
- **Implementation status:** `planned`
- **Runtime route:** none — no runtime route is registered
- **Route delivery:** not applicable
- **External gate:** none

## Data and behaviour

- **API dependencies:** Not yet proven in the current repository. The owner phase must resolve this before changing `implementation_status` to `implemented`.
- **Data fields:** Not yet proven in the current repository. The owner phase must resolve this before changing `implementation_status` to `implemented`.
- **Filters:** Not yet proven in the current repository. The owner phase must resolve this before changing `implementation_status` to `implemented`.
- **Sorts:** Not yet proven in the current repository. The owner phase must resolve this before changing `implementation_status` to `implemented`.
- **Pagination:** Not yet proven in the current repository. The owner phase must resolve this before changing `implementation_status` to `implemented`.
- **Primary action:** Not yet proven in the current repository. The owner phase must resolve this before changing `implementation_status` to `implemented`.
- **Secondary actions:** Not yet proven in the current repository. The owner phase must resolve this before changing `implementation_status` to `implemented`.

## Authorization

- **Authorization:** Backend `auth:sanctum` + Form Request + Policy + `EnsurePermission` is the security boundary. Everything below is UX visibility only (ADR-017).
- **Permission-any:** — none
- **Permission-all:** — none
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
- **Entitlement behaviour:** Not yet proven in the current repository. The owner phase must resolve this before changing `implementation_status` to `implemented`.

## Presentation

- **Responsive behaviour:** Mobile ≤767 / tablet 768–1024 / desktop ≥1025 via CSS media queries only; tables become labelled cards on mobile without horizontal scrolling.
- **Accessibility behaviour:** Labels, landmarks, visible focus, 44px targets, `aria-current` on the active navigation item, AA contrast in light and dark.
- **Icon:** `subscription` (Heroicons, resolved through the curated navigation icon registry — no emoji, no runtime arbitrary lookup).
- **Navigation visibility:** `primary`
- **Non-navigation reason:** not applicable — this page appears in primary navigation.

## Evidence

- **Audit events:** Not yet proven in the current repository. The owner phase must resolve this before changing `implementation_status` to `implemented`.
- **Analytics events:** No third-party analytics runtime exists in Servana.
- **Tests:** Contract-level only in UI-07: `Ui07NavigationRegistryContractTest`, `Ui07NoPlannedRouteExposureTest`. Page-level tests are owned by **UI-08**.
- **Screenshot requirements:** None in UI-07 — there is no page to capture. Owner phase **UI-08**.
