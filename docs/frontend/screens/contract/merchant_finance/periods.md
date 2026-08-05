# Screen specification — Financial Periods

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> A real runtime route renders this page today: `finance.periods` at `/finance/periods` (routes/finance.ts), delivery **dedicated**. The runtime path uses the account's path prefix rather than the host-relative contract path `/periods`; owner phase **UI-12** reconciles path shape (`UI01-ROUTE-003`).

## Identity

- **Account:** merchant_finance
- **Host:** `finance.servana.ke`
- **Page title:** Financial Periods
- **Route:** `/periods` (host-relative contract path)
- **Route name:** `finance.periods`
- **Navigation group:** Controls & Close
- **Navigation placement:** sidebar primary navigation
- **Contract key:** `merchant_finance.periods`
- **Screen key:** `periods`
- **Authoritative map section:** §9.4.14

## Purpose

- **Purpose:** Create, inspect, lock, and controlled-reopen branch financial periods.
- **User story:** As merchant finance, I open Financial Periods so that create, inspect, lock, and controlled-reopen branch financial periods.

## Ownership and status

- **UI owner phase:** **UI-12**
- **Backend owner phase:** **Phase 18B**
- **Implementation status:** `implemented`
- **Runtime route:** `finance.periods`
- **Route delivery:** `dedicated`
- **External gate:** none

## Data and behaviour

- **API dependencies:** `GET /api/v1/me` bootstrap plus the endpoints already backing `finance.periods` (recorded in `docs/frontend/screens/finance/finance-periods.md`).
- **Data fields:** Finance financial-period locks: create a merchant-wide or branch lock (optionally exception-required), and run a controlled reopen (request reason → execute with a fresh step-up). An exceptional reopen waits for a distinct Merchant Administrator approval before Finance may execute. A locked period blocks applicable mutations with 423; reads/receipts/disputes/exports are never locked.
- **Filters:** As delivered by the runtime screen; preserved across list → detail → back.
- **Sorts:** As delivered by the runtime screen; deterministic and server-authoritative.
- **Pagination:** Every collection paginates (Plan §9 rule 10).
- **Primary action:** As delivered by the runtime screen; one visually dominant primary action per page.
- **Secondary actions:** As delivered by the runtime screen.

## Authorization

- **Authorization:** Backend `auth:sanctum` + Form Request + Policy + `EnsurePermission` is the security boundary. Everything below is UX visibility only (ADR-017).
- **Permission-any:** — none
- **Permission-all:** `period_lock.create`
- **Tenant scope:** Merchant-scoped via `BelongsToMerchant`; foreign ULIDs resolve to 404.
- **Branch scope:** Not branch-scoped.
- **Own-scope:** Not own-scoped.
- **MFA:** Required for this account.
- **Step-up:** No route-level step-up requirement; individual mutations may still require it server-side.
- **Feature flag:** none
- **Forbidden for:** `super_administrator`, `merchant_administrator`, `merchant_branch`, `merchant_human_resource`, `merchant_front_office`, `merchant_personnel`, `merchant_audit`

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
- **Icon:** `period` (Heroicons, resolved through the curated navigation icon registry — no emoji, no runtime arbitrary lookup).
- **Navigation visibility:** `primary`
- **Non-navigation reason:** not applicable — this page appears in primary navigation.

## Evidence

- **Audit events:** Mutations emit append-only hash-chained `audit_logs` entries; coverage is asserted by `AuditMutationCoverage`.
- **Analytics events:** No third-party analytics runtime exists in Servana.
- **Tests:** Route parity `Ui07RouteParityTest`; contract `Ui07NavigationRegistryContractTest`; account guard `Ui07AccountRouteGuardCoverageTest`; runtime navigation `navigationFilter.spec.ts`; browser `tests/e2e/ui-07-navigation-screen-contracts.spec.ts`.
- **Screenshot requirements:** Owner phase **UI-12** captures this page; UI-07 captures rendered navigation states only.
