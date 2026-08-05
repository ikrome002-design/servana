# Screen specification — Merchant Detail and Governance

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> A real runtime route renders this page today: `platform.registration-monitoring` at `/platform/registration-monitoring` (routes/platform.ts), delivery **consolidated**. This runtime route also serves other contract pages — the collapse recorded as `UI01-NAV-001`. Owner phase **UI-08** splits it into a dedicated page. The runtime path uses the account's path prefix rather than the host-relative contract path `/merchants/:merchantUlid`; owner phase **UI-08** reconciles path shape (`UI01-ROUTE-003`).

## Identity

- **Account:** super_administrator
- **Host:** `citrus.servana.ke`
- **Page title:** Merchant Detail and Governance
- **Route:** `/merchants/:merchantUlid` (host-relative contract path)
- **Route name:** `platform.merchant-detail`
- **Navigation group:** Merchants
- **Navigation placement:** header primary navigation
- **Contract key:** `super_administrator.merchant-detail`
- **Screen key:** `merchant-detail`
- **Authoritative map section:** §5.4.12

## Purpose

- **Purpose:** Govern an existing merchant's platform lifecycle without impersonation or operational control.
- **User story:** As super administrator, I open Merchant Detail and Governance so that govern an existing merchant's platform lifecycle without impersonation or operational control.

## Ownership and status

- **UI owner phase:** **UI-08**
- **Backend owner phase:** **Phase 20B**
- **Implementation status:** `implemented`
- **Runtime route:** `platform.registration-monitoring`
- **Route delivery:** `consolidated`
- **External gate:** none

## Data and behaviour

- **API dependencies:** `GET /api/v1/me` bootstrap plus the endpoints already backing `platform.registration-monitoring` (recorded in `docs/frontend/screens/platform/platform-registration-monitoring.md`).
- **Data fields:** One consolidated Super-Admin surface (accessible tabs): registration monitoring + merchant directory/detail with operational vs billing status shown separately, and suspend/reactivate/deactivate governance (mandatory reason + confirmation + fresh merchant_governance step-up; mutates merchants.status only). The 'Merchant directory' nav label routes here too. NO merchant-create/first-admin/impersonation/payment/Wallet UI.
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
- **Navigation visibility:** `detail_route`
- **Non-navigation reason:** Record detail route addressed by a public ULID; it has no parameter-free primary navigation destination.

## Evidence

- **Audit events:** Mutations emit append-only hash-chained `audit_logs` entries; coverage is asserted by `AuditMutationCoverage`.
- **Analytics events:** No third-party analytics runtime exists in Servana.
- **Tests:** Route parity `Ui07RouteParityTest`; contract `Ui07NavigationRegistryContractTest`; account guard `Ui07AccountRouteGuardCoverageTest`; runtime navigation `navigationFilter.spec.ts`; browser `tests/e2e/ui-07-navigation-screen-contracts.spec.ts`.
- **Screenshot requirements:** Owner phase **UI-08** captures this page; UI-07 captures rendered navigation states only.
