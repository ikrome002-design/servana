# Screen specification — Get Started

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> A real runtime route renders this page today: `audit.get-started` at `/audit/get-started` (routes/audit.ts), delivery **dedicated**. The runtime path uses the account's path prefix rather than the host-relative contract path `/get-started`; owner phase **UI-15** reconciles path shape (`UI01-ROUTE-003`).

## Identity

- **Account:** merchant_audit
- **Host:** `audit.servana.ke`
- **Page title:** Get Started
- **Route:** `/get-started` (host-relative contract path)
- **Route name:** `audit.get-started`
- **Navigation group:** Home
- **Navigation placement:** sidebar primary navigation
- **Contract key:** `merchant_audit.get-started`
- **Screen key:** `get-started`
- **Authoritative map section:** §12.4.2

## Purpose

- **Purpose:** Teach the Audit user branch filters, masking, flagged-event review, and export controls.
- **User story:** As merchant audit, I open Get Started so that teach the Audit user branch filters, masking, flagged-event review, and export controls.

## Ownership and status

- **UI owner phase:** **UI-15**
- **Backend owner phase:** **Phase 11**
- **Implementation status:** `implemented`
- **Runtime route:** `audit.get-started`
- **Route delivery:** `dedicated`
- **External gate:** none

## Data and behaviour

- **API dependencies:** `GET /api/v1/me` bootstrap plus the endpoints already backing `audit.get-started` (recorded in `docs/frontend/screens/audit/audit-get-started.md`).
- **Data fields:** Guided get-started checklist for Audit.
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
- **Forbidden for:** `super_administrator`, `merchant_administrator`, `merchant_branch`, `merchant_human_resource`, `merchant_finance`, `merchant_front_office`, `merchant_personnel`

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
- **Icon:** `get-started` (Heroicons, resolved through the curated navigation icon registry — no emoji, no runtime arbitrary lookup).
- **Navigation visibility:** `primary`
- **Non-navigation reason:** not applicable — this page appears in primary navigation.

## Evidence

- **Audit events:** Mutations emit append-only hash-chained `audit_logs` entries; coverage is asserted by `AuditMutationCoverage`.
- **Analytics events:** No third-party analytics runtime exists in Servana.
- **Tests:** Route parity `Ui07RouteParityTest`; contract `Ui07NavigationRegistryContractTest`; account guard `Ui07AccountRouteGuardCoverageTest`; runtime navigation `navigationFilter.spec.ts`; browser `tests/e2e/ui-07-navigation-screen-contracts.spec.ts`.
- **Screenshot requirements:** Owner phase **UI-15** captures this page; UI-07 captures rendered navigation states only.
