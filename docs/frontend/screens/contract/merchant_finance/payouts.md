# Screen specification — Payout Runs

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> A real runtime route renders this page today: `finance.payouts` at `/payouts` (routes/finance.ts), delivery **dedicated**.

## Identity

- **Account:** merchant_finance
- **Host:** `finance.servana.ke`
- **Page title:** Payout Runs
- **Route:** `/payouts` (host-relative contract path)
- **Route name:** `finance.payouts`
- **Navigation group:** Compensation Finance
- **Navigation placement:** sidebar primary navigation
- **Contract key:** `merchant_finance.payouts`
- **Screen key:** `payouts`
- **Authoritative map section:** §9.4.15

## Purpose

- **Purpose:** Verify, approve, and record external payment of personnel compensation payout runs.
- **User story:** As merchant finance, I open Payout Runs so that verify, approve, and record external payment of personnel compensation payout runs.

## Ownership and status

- **UI owner phase:** **UI-12**
- **Backend owner phase:** **Phase 20H**
- **Implementation status:** `implemented`
- **Runtime route:** `finance.payouts`
- **Route delivery:** `dedicated`
- **External gate:** none

## Data and behaviour

- **API dependencies:** `GET /api/v1/me` bootstrap plus the endpoints already backing `finance.payouts` (recorded in `docs/frontend/screens/finance/finance-payout-runs.md`).
- **Data fields:** Merchant-scoped Finance payout worklist: list/filter runs, verify a submitted run (Servana routes high-value runs to the Merchant Administrator), approve an ordinary run, reject (releasing the claimed liabilities via a reason), and mark an approved run PAID after an EXTERNAL settlement (external payment reference + a not-in-the-future paid date). Verify/approve/mark-paid are financial mutations — fresh step-up + Idempotency-Key are server-enforced; the mark-paid dialog states plainly that Servana records an external payment and moves no money. The raw external reference is never displayed after saving; the browser computes no authoritative total. No provider/Wallet/settlement UI.
- **Filters:** As delivered by the runtime screen; preserved across list → detail → back.
- **Sorts:** As delivered by the runtime screen; deterministic and server-authoritative.
- **Pagination:** Every collection paginates (Plan §9 rule 10).
- **Primary action:** As delivered by the runtime screen; one visually dominant primary action per page.
- **Secondary actions:** As delivered by the runtime screen.

## Authorization

- **Authorization:** Backend `auth:sanctum` + Form Request + Policy + `EnsurePermission` is the security boundary. Everything below is UX visibility only (ADR-017).
- **Permission-any:** — none
- **Permission-all:** `payout_run.verify`
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
- **Icon:** `payout` (Heroicons, resolved through the curated navigation icon registry — no emoji, no runtime arbitrary lookup).
- **Navigation visibility:** `primary`
- **Non-navigation reason:** not applicable — this page appears in primary navigation.

## Evidence

- **Audit events:** Mutations emit append-only hash-chained `audit_logs` entries; coverage is asserted by `AuditMutationCoverage`.
- **Analytics events:** No third-party analytics runtime exists in Servana.
- **Tests:** Route parity `Ui07RouteParityTest`; contract `Ui07NavigationRegistryContractTest`; account guard `Ui07AccountRouteGuardCoverageTest`; runtime navigation `navigationFilter.spec.ts`; browser `tests/e2e/ui-07-navigation-screen-contracts.spec.ts`.
- **Screenshot requirements:** Owner phase **UI-12** captures this page; UI-07 captures rendered navigation states only.
