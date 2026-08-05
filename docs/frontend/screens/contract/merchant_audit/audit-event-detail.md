# Screen specification — Audit Event Detail

> GENERATED FILE — do not edit.
> Source: `docs/frontend/navigation/servana-user-account-navigation-map.yaml` · Regenerate: `node scripts/generate-ui07-navigation-contract.mjs`
>
> A real runtime route renders this page today: `audit.event-detail` at `/audit/events/:id` (routes/audit.ts), delivery **dedicated**. The runtime path uses the account's path prefix rather than the host-relative contract path `/audit/:eventUlid`; owner phase **UI-15** reconciles path shape (`UI01-ROUTE-003`).

## Identity

- **Account:** merchant_audit
- **Host:** `audit.servana.ke`
- **Page title:** Audit Event Detail
- **Route:** `/audit/:eventUlid` (host-relative contract path)
- **Route name:** `audit.audit-event-detail`
- **Navigation group:** Audit › Branch Audit Log
- **Navigation placement:** sidebar primary navigation
- **Contract key:** `merchant_audit.audit-event-detail`
- **Screen key:** `audit-event-detail`
- **Authoritative map section:** §12.4.4

## Purpose

- **Purpose:** Explain one event, its before/after state, actors, context, integrity, and related review status.
- **User story:** As merchant audit, I open Audit Event Detail so that explain one event, its before/after state, actors, context, integrity, and related review status.

## Ownership and status

- **UI owner phase:** **UI-15**
- **Backend owner phase:** **Phase 19**
- **Implementation status:** `implemented`
- **Runtime route:** `audit.event-detail`
- **Route delivery:** `dedicated`
- **External gate:** none

## Data and behaviour

- **API dependencies:** `GET /api/v1/me` bootstrap plus the endpoints already backing `audit.event-detail` (recorded in `docs/frontend/screens/audit/audit-event-detail.md`).
- **Data fields:** Immutable, read-only audit record with safe actor/subject summaries and masked context; no source-mutation controls, no ids/hashes/paths/signed URLs/tokens/unmasked PII.
- **Filters:** As delivered by the runtime screen; preserved across list → detail → back.
- **Sorts:** As delivered by the runtime screen; deterministic and server-authoritative.
- **Pagination:** Every collection paginates (Plan §9 rule 10).
- **Primary action:** As delivered by the runtime screen; one visually dominant primary action per page.
- **Secondary actions:** As delivered by the runtime screen.

## Authorization

- **Authorization:** Backend `auth:sanctum` + Form Request + Policy + `EnsurePermission` is the security boundary. Everything below is UX visibility only (ADR-017).
- **Permission-any:** — none
- **Permission-all:** `audit.branch_events.view`
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
- **Icon:** `audit` (Heroicons, resolved through the curated navigation icon registry — no emoji, no runtime arbitrary lookup).
- **Navigation visibility:** `contextual_child`
- **Non-navigation reason:** Contextual child reached from its parent screen; the authoritative map places it under that parent rather than in primary navigation.

## Evidence

- **Audit events:** Mutations emit append-only hash-chained `audit_logs` entries; coverage is asserted by `AuditMutationCoverage`.
- **Analytics events:** No third-party analytics runtime exists in Servana.
- **Tests:** Route parity `Ui07RouteParityTest`; contract `Ui07NavigationRegistryContractTest`; account guard `Ui07AccountRouteGuardCoverageTest`; runtime navigation `navigationFilter.spec.ts`; browser `tests/e2e/ui-07-navigation-screen-contracts.spec.ts`.
- **Screenshot requirements:** Owner phase **UI-15** captures this page; UI-07 captures rendered navigation states only.
