# Screen specification — Commission and Salary Liabilities

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> A real runtime route renders this page today: `finance.compensation-liabilities` at `/compensation/liabilities` (routes/finance.ts), delivery **dedicated**.

## Identity

- **Account:** merchant_finance
- **Host:** `finance.servana.ke`
- **Page title:** Commission and Salary Liabilities
- **Route:** `/compensation/liabilities` (host-relative contract path)
- **Route name:** `finance.compensation-liabilities`
- **Navigation group:** Compensation Finance
- **Navigation placement:** sidebar primary navigation
- **Contract key:** `merchant_finance.compensation-liabilities`
- **Screen key:** `compensation-liabilities`
- **Authoritative map section:** §9.4.16

## Purpose

- **Purpose:** Review earned-unpaid commission, salary accrual/due amounts, approved liabilities, reversals, and adjustments.
- **User story:** As merchant finance, I open Commission and Salary Liabilities so that review earned-unpaid commission, salary accrual/due amounts, approved liabilities, reversals, and adjustments.

## Ownership and status

- **UI owner phase:** **UI-12**
- **Backend owner phase:** **Phase 20G**
- **Implementation status:** `implemented`
- **Runtime route:** `finance.compensation-liabilities`
- **Route delivery:** `dedicated`
- **External gate:** none

## Data and behaviour

- **API dependencies:** `GET /api/v1/me` bootstrap plus the endpoints already backing `finance.compensation-liabilities` (recorded in `docs/frontend/screens/finance/finance-compensation-liabilities.md`).
- **Data fields:** Finance masked, merchant-scoped compensation-liability surface: a per-currency summary (net salary, net commission, adjustments, combined net, plus gross accrual/earned and reversal totals; never combined across currencies), a filtered/paginated liability-entry list (salary accrual/reversal, earned commission/reversal) with safe source detail, a compensation-adjustment list/detail, and a capability-gated Record-adjustment dialog that posts a standalone additive positive/negative adjustment (server-derived branch/type/actors; fresh step-up + Idempotency-Key enforced by the API). The browser never computes authoritative liability money and never sends a server-owned field. A liability is not a payout, settlement, disbursement, earnings statement or paid event; no Wallet/provider UI.
- **Filters:** As delivered by the runtime screen; preserved across list → detail → back.
- **Sorts:** As delivered by the runtime screen; deterministic and server-authoritative.
- **Pagination:** Every collection paginates (Plan §9 rule 10).
- **Primary action:** As delivered by the runtime screen; one visually dominant primary action per page.
- **Secondary actions:** As delivered by the runtime screen.

## Authorization

- **Authorization:** Backend `auth:sanctum` + Form Request + Policy + `EnsurePermission` is the security boundary. Everything below is UX visibility only (ADR-017).
- **Permission-any:** — none
- **Permission-all:** `compensation.liability.view`
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
- **Icon:** `compensation` (Heroicons, resolved through the curated navigation icon registry — no emoji, no runtime arbitrary lookup).
- **Navigation visibility:** `primary`
- **Non-navigation reason:** not applicable — this page appears in primary navigation.

## Evidence

- **Audit events:** Mutations emit append-only hash-chained `audit_logs` entries; coverage is asserted by `AuditMutationCoverage`.
- **Analytics events:** No third-party analytics runtime exists in Servana.
- **Tests:** Route parity `Ui07RouteParityTest`; contract `Ui07NavigationRegistryContractTest`; account guard `Ui07AccountRouteGuardCoverageTest`; runtime navigation `navigationFilter.spec.ts`; browser `tests/e2e/ui-07-navigation-screen-contracts.spec.ts`.
- **Screenshot requirements:** Owner phase **UI-12** captures this page; UI-07 captures rendered navigation states only.
