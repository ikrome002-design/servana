# Screen specification — Notifications

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> Blocked by **phase_21n_blocked_by_external_gate_w**. The navigation entry is rendered disabled and names the gate; it has no live destination, and no Wallet, Refer & Earn, notification or provider runtime exists behind it. Owner phase **UI-11** implements the page once the gate opens.

## Identity

- **Account:** merchant_human_resource
- **Host:** `hr.servana.ke`
- **Page title:** Notifications
- **Route:** `/notifications` (host-relative contract path)
- **Route name:** `hr.notifications`
- **Navigation group:** Utility
- **Navigation placement:** sidebar primary navigation
- **Contract key:** `merchant_human_resource.notifications`
- **Screen key:** `notifications`
- **Authoritative map section:** §8.4.18

## Purpose

- **Purpose:** Centralize HR invitations, readiness, compensation, payout, and earnings-query tasks.
- **User story:** As merchant human resource, I open Notifications so that centralize HR invitations, readiness, compensation, payout, and earnings-query tasks.

## Ownership and status

- **UI owner phase:** **UI-11**
- **Backend owner phase:** **Phase 21N after Phase 20D-W / Gate W**
- **Implementation status:** `disabled_by_gate`
- **Runtime route:** none — no runtime route is registered
- **Route delivery:** not applicable
- **External gate:** `phase_21n_blocked_by_external_gate_w`

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
- **Tenant scope:** Merchant-scoped via `BelongsToMerchant`; foreign ULIDs resolve to 404.
- **Branch scope:** Branch-scoped via `BelongsToBranch`; the branch is resolved from the server bootstrap, never from the URL.
- **Own-scope:** Not own-scoped.
- **MFA:** Not required for this account.
- **Step-up:** No route-level step-up requirement; individual mutations may still require it server-side.
- **Feature flag:** none
- **Forbidden for:** `super_administrator`, `merchant_administrator`, `merchant_branch`, `merchant_finance`, `merchant_front_office`, `merchant_personnel`, `merchant_audit`

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
- **Icon:** `notification` (Heroicons, resolved through the curated navigation icon registry — no emoji, no runtime arbitrary lookup).
- **Navigation visibility:** `primary`
- **Non-navigation reason:** not applicable — this page appears in primary navigation.

## Evidence

- **Audit events:** Not yet proven in the current repository. The owner phase must resolve this before changing `implementation_status` to `implemented`.
- **Analytics events:** No third-party analytics runtime exists in Servana.
- **Tests:** Contract-level only in UI-07: `Ui07NavigationRegistryContractTest`, `Ui07NoPlannedRouteExposureTest`. Page-level tests are owned by **UI-11**.
- **Screenshot requirements:** None in UI-07 — there is no page to capture. Owner phase **UI-11**.
