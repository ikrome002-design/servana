# Screen specification — Role and Branch Assignment

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> A real runtime route renders this page today: `hr.permission-preview` at `/hr/permission-preview` (routes/hr.ts), delivery **dedicated**. The runtime path uses the account's path prefix rather than the host-relative contract path `/staff/:staffUlid/access`; owner phase **UI-11** reconciles path shape (`UI01-ROUTE-003`).

## Identity

- **Account:** merchant_human_resource
- **Host:** `hr.servana.ke`
- **Page title:** Role and Branch Assignment
- **Route:** `/staff/:staffUlid/access` (host-relative contract path)
- **Route name:** `hr.staff-detail-access`
- **Navigation group:** Staff › Staff Detail
- **Navigation placement:** sidebar primary navigation
- **Contract key:** `merchant_human_resource.staff-detail-access`
- **Screen key:** `staff-detail-access`
- **Authoritative map section:** §8.4.8

## Purpose

- **Purpose:** Assign permitted operational role and current-branch access with a clear permission preview.
- **User story:** As merchant human resource, I open Role and Branch Assignment so that assign permitted operational role and current-branch access with a clear permission preview.

## Ownership and status

- **UI owner phase:** **UI-11**
- **Backend owner phase:** **Phase 8**
- **Implementation status:** `implemented`
- **Runtime route:** `hr.permission-preview`
- **Route delivery:** `dedicated`
- **External gate:** none

## Data and behaviour

- **API dependencies:** `GET /api/v1/me` bootstrap plus the endpoints already backing `hr.permission-preview` (recorded in `docs/frontend/screens/hr/hr-permission-preview.md`).
- **Data fields:** Preview default grants for a role before assignment.
- **Filters:** As delivered by the runtime screen; preserved across list → detail → back.
- **Sorts:** As delivered by the runtime screen; deterministic and server-authoritative.
- **Pagination:** Every collection paginates (Plan §9 rule 10).
- **Primary action:** As delivered by the runtime screen; one visually dominant primary action per page.
- **Secondary actions:** As delivered by the runtime screen.

## Authorization

- **Authorization:** Backend `auth:sanctum` + Form Request + Policy + `EnsurePermission` is the security boundary. Everything below is UX visibility only (ADR-017).
- **Permission-any:** — none
- **Permission-all:** `staff.role.assign`
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
- **Entitlement behaviour:** Entitlement gating is enforced server-side by the owning feature phase.

## Presentation

- **Responsive behaviour:** Mobile ≤767 / tablet 768–1024 / desktop ≥1025 via CSS media queries only; tables become labelled cards on mobile without horizontal scrolling.
- **Accessibility behaviour:** Labels, landmarks, visible focus, 44px targets, `aria-current` on the active navigation item, AA contrast in light and dark.
- **Icon:** `security` (Heroicons, resolved through the curated navigation icon registry — no emoji, no runtime arbitrary lookup).
- **Navigation visibility:** `contextual_child`
- **Non-navigation reason:** Contextual child reached from its parent screen; the authoritative map places it under that parent rather than in primary navigation.

## Evidence

- **Audit events:** Mutations emit append-only hash-chained `audit_logs` entries; coverage is asserted by `AuditMutationCoverage`.
- **Analytics events:** No third-party analytics runtime exists in Servana.
- **Tests:** Route parity `Ui07RouteParityTest`; contract `Ui07NavigationRegistryContractTest`; account guard `Ui07AccountRouteGuardCoverageTest`; runtime navigation `navigationFilter.spec.ts`; browser `tests/e2e/ui-07-navigation-screen-contracts.spec.ts`.
- **Screenshot requirements:** Owner phase **UI-11** captures this page; UI-07 captures rendered navigation states only.
