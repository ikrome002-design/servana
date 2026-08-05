# Screen specification — Compensation List

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> A real runtime route renders this page today: `hr.compensation` at `/hr/compensation` (routes/hr.ts), delivery **dedicated**. The runtime path uses the account's path prefix rather than the host-relative contract path `/compensation`; owner phase **UI-11** reconciles path shape (`UI01-ROUTE-003`).

## Identity

- **Account:** merchant_human_resource
- **Host:** `hr.servana.ke`
- **Page title:** Compensation List
- **Route:** `/compensation` (host-relative contract path)
- **Route name:** `hr.compensation`
- **Navigation group:** Compensation
- **Navigation placement:** sidebar primary navigation
- **Contract key:** `merchant_human_resource.compensation`
- **Screen key:** `compensation`
- **Authoritative map section:** §8.4.11

## Purpose

- **Purpose:** Show all branch personnel compensation models, configuration status, liabilities, and action requirements.
- **User story:** As merchant human resource, I open Compensation List so that show all branch personnel compensation models, configuration status, liabilities, and action requirements.

## Ownership and status

- **UI owner phase:** **UI-11**
- **Backend owner phase:** **Phase 20F**
- **Implementation status:** `implemented`
- **Runtime route:** `hr.compensation`
- **Route delivery:** `dedicated`
- **External gate:** none

## Data and behaviour

- **API dependencies:** `GET /api/v1/me` bootstrap plus the endpoints already backing `hr.compensation` (recorded in `docs/frontend/screens/hr/hr-compensation.md`).
- **Data fields:** Branch-scoped, HR-only compensation-plan and commission-rule configuration: plan list with status/backdated/pending-approval indicators, plan detail with append-only history, commission-rule and plan draft forms (F1 model shape, F4 value shape, preferred-personnel-fee basis inclusion), and the named submit/approve/reject/cancel transitions (approval requires a fresh step-up and a different approver). Phase 20G §9.1: a selected_services commission-rule draft shows a branch-scoped service multi-select whose options load from the narrow compensation-scoped GET /commission-rule-service-options endpoint (authorized by compensation.plan.view, never service.view which HR cannot hold; returns {ulid,name} for the acting branch's active services only) — at least one service required, add/remove only while draft, server-returned selections hydrate, stale selection cleared when applies_to changes, non-draft memberships read-only; selected_service_ulids are submitted and the server persists immutable draft memberships. Configuration only — no earned commission, salary ledger, commission ledger, payout, earnings statement, liability or Wallet/provider surface exists here.
- **Filters:** As delivered by the runtime screen; preserved across list → detail → back.
- **Sorts:** As delivered by the runtime screen; deterministic and server-authoritative.
- **Pagination:** Every collection paginates (Plan §9 rule 10).
- **Primary action:** As delivered by the runtime screen; one visually dominant primary action per page.
- **Secondary actions:** As delivered by the runtime screen.

## Authorization

- **Authorization:** Backend `auth:sanctum` + Form Request + Policy + `EnsurePermission` is the security boundary. Everything below is UX visibility only (ADR-017).
- **Permission-any:** — none
- **Permission-all:** `compensation.plan.view`
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
- **Icon:** `compensation` (Heroicons, resolved through the curated navigation icon registry — no emoji, no runtime arbitrary lookup).
- **Navigation visibility:** `primary`
- **Non-navigation reason:** not applicable — this page appears in primary navigation.

## Evidence

- **Audit events:** Mutations emit append-only hash-chained `audit_logs` entries; coverage is asserted by `AuditMutationCoverage`.
- **Analytics events:** No third-party analytics runtime exists in Servana.
- **Tests:** Route parity `Ui07RouteParityTest`; contract `Ui07NavigationRegistryContractTest`; account guard `Ui07AccountRouteGuardCoverageTest`; runtime navigation `navigationFilter.spec.ts`; browser `tests/e2e/ui-07-navigation-screen-contracts.spec.ts`.
- **Screenshot requirements:** Owner phase **UI-11** captures this page; UI-07 captures rendered navigation states only.
