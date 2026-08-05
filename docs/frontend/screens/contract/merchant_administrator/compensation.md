# Screen specification — Compensation Summary

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> A real runtime route renders this page today: `merchant.compensation-summary` at `/merchant/compensation-summary` (routes/merchant.ts), delivery **dedicated**. The runtime path uses the account's path prefix rather than the host-relative contract path `/compensation`; owner phase **UI-09** reconciles path shape (`UI01-ROUTE-003`).

## Identity

- **Account:** merchant_administrator
- **Host:** `servana.ke`
- **Page title:** Compensation Summary
- **Route:** `/compensation` (host-relative contract path)
- **Route name:** `merchant.compensation`
- **Navigation group:** Compensation & Approvals
- **Navigation placement:** sidebar primary navigation
- **Contract key:** `merchant_administrator.compensation`
- **Screen key:** `compensation`
- **Authoritative map section:** §6.4.18

## Purpose

- **Purpose:** Give the owner branch-aware oversight of salary-only, commission-only, and salary-plus-commission liabilities and payouts.
- **User story:** As merchant administrator, I open Compensation Summary so that give the owner branch-aware oversight of salary-only, commission-only, and salary-plus-commission liabilities and payouts.

## Ownership and status

- **UI owner phase:** **UI-09**
- **Backend owner phase:** **Phase 20H**
- **Implementation status:** `implemented`
- **Runtime route:** `merchant.compensation-summary`
- **Route delivery:** `dedicated`
- **External gate:** none

## Data and behaviour

- **API dependencies:** `GET /api/v1/me` bootstrap plus the endpoints already backing `merchant.compensation-summary` (recorded in `docs/frontend/screens/merchant/merchant-compensation-summary.md`).
- **Data fields:** Merchant-Administrator compensation overview + high-value payout approvals: masked, merchant-wide, currency-grouped totals (outstanding liability by currency, paid by currency, payout runs by status, pending high-value approvals; never combined across currencies), and a high-value approval queue where the Merchant Administrator approves a run above the snapshotted threshold (fresh step-up + Idempotency-Key server-enforced). The Merchant Administrator never creates, verifies, or marks paid. The browser computes no authoritative money. Servana moves no money; no Wallet/provider UI.
- **Filters:** As delivered by the runtime screen; preserved across list → detail → back.
- **Sorts:** As delivered by the runtime screen; deterministic and server-authoritative.
- **Pagination:** Every collection paginates (Plan §9 rule 10).
- **Primary action:** As delivered by the runtime screen; one visually dominant primary action per page.
- **Secondary actions:** As delivered by the runtime screen.

## Authorization

- **Authorization:** Backend `auth:sanctum` + Form Request + Policy + `EnsurePermission` is the security boundary. Everything below is UX visibility only (ADR-017).
- **Permission-any:** — none
- **Permission-all:** `merchant.compensation_summary.view`
- **Tenant scope:** Merchant-scoped via `BelongsToMerchant`; foreign ULIDs resolve to 404.
- **Branch scope:** Not branch-scoped.
- **Own-scope:** Not own-scoped.
- **MFA:** Required for this account.
- **Step-up:** No route-level step-up requirement; individual mutations may still require it server-side.
- **Feature flag:** none
- **Forbidden for:** `super_administrator`, `merchant_branch`, `merchant_human_resource`, `merchant_finance`, `merchant_front_office`, `merchant_personnel`, `merchant_audit`

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
- **Screenshot requirements:** Owner phase **UI-09** captures this page; UI-07 captures rendered navigation states only.
