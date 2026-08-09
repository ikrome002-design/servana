# COR-UI08-001 — Super Administrator platform-administration backend enablement

## Decision

Authorize the **smallest backend enablement** required for four already-approved Super
Administrator pages to be delivered truthfully inside Phase UI-08:

| Map section | Page | Canonical route |
|---|---|---|
| 5.4.9 | SMS Billing Settings | `/billing/sms` |
| 5.4.13 | Subscription Operations | `/billing/subscriptions` |
| 5.4.19 | Internal Platform Access | `/platform-access` |
| 5.4.20 | Feature Flags | `/platform/feature-flags` |

All four remain mandatory launch pages. None is removed from the 160-page contract, none is
reclassified under External Gate W, and none may remain `planned` at UI-08 completion.

## Status

`accepted`

This decision record is created from the product-owner message that constitutes **the separate
authorization required by the Phase UI-08 kickoff stop condition**. The implementing agent stopped
in Increment 1 because the repository contained no proven backend owner, permission contract,
schema, or API contract for these four pages, and the kickoff prompt forbade inventing any of them
(§18.4 "Do not invent it", §23.1 "UI-08 is not authorized to create or alter permission keys",
`CLAUDE.md` §9 "Stop and ask the human before … altering the permission matrix"). That stop was
correct. This record supplies the missing authorization and the exact bounded rules.

## Date

2026-08-05

## Context

`COR-UI08-001` is a **bounded corrective backend authorization executed inside the UI-08 branch**.
It exists because Phase UI-08 cannot truthfully satisfy its binding 22-page contract without it.

It is **not**:

- an independent historical feature phase;
- UI-09, UI-16, UI-17, Phase 25, 20D-W, 21R-B, or 21N;
- a licence for a general backend rewrite.

It is executed on `phase-ui-08-super-administrator-experience`, with no separate branch, no separate
pull request, and no checkpoint commit. All UI-08 work lands as one atomic completion commit.

## Authoritative product requirements

The product intent was never missing. Both product authorities already require these capabilities.

`Servana Project Scope.md`, Super Administrator capabilities:

- "Configure SMS billing settings."
- "View subscription status, subscription invoices, and M-Pesa payment attempts."
- "Manage internal Citrus Labs Limited platform roles."
- "Control platform-level feature flags."

`Servana_Role_Specific_UI_UX_Subdomain_Software_Development_Plan.md`:

- §5.4.9 defines SMS Billing Settings;
- §5.4.13 defines Subscription Operations;
- §5.4.19 defines Internal Platform Access;
- §5.4.20 defines Feature Flags;
- Phase UI-08 owns all 22 Super Administrator pages;
- §15.2 requires real APIs, real read models, real permissions, real feature flags and real billing
  states — production mock data is forbidden;
- §15.3 requires that a page whose backend phase is complete but which is missing counts as a
  defect, and that future work must never be represented as complete;
- the final launch gate requires all 160 pages.

## Proven repository gap

Measured at `16d544c58747a1d69ef390c2d4511c649315fde7` and recorded in full in
`docs/frontend/audits/ui-08/page-readiness-matrix.json` and
`docs/decisions/blocking-ambiguities.md` (`UI08-AMBIG-001`):

- `docs/auth/permission-matrix.yaml` held **167** keys (132 active, 35 planned) and contained no key
  for platform SMS billing, platform-scope subscription/invoice reading, platform-user
  administration, or feature-flag administration.
- `routes/api.php` registered no endpoint for any of the four.
- No migration existed for SMS pricing configuration, platform access lifecycle, or feature flags.
  SMS unit cost was read from `config('sms.pricing.unit_cost_minor')`
  (`app/Domain/Messaging/Sms/Support/SmsCostCalculator.php:45`) — deployment configuration, not a
  versioned, effective-dated, audited platform setting.
- `Servana Software Development Plan.md` §80 assigned no phase to any of the four. Only Phase 25
  (deployment) and the gate-blocked 20D-W / 21R-B / 21N remained.

The gap was therefore **backend assignment and delivery**, not product intent.

## Pages covered

Exactly the four listed above. No other page's disposition changes under this decision. The five
Gate-W/dependency pages remain truthfully `disabled_by_gate`:

```text
Billing Reconciliation Exceptions
Integrations Health
Refer & Earn Qualification Decisions
Platform Reports
Notifications
```

### Options considered and rejected

- **Gate-W reclassification — rejected.** None of the four depends on Wallet by Citrus. Recording a
  Wallet gate on an unbuilt page would misreport *why* it is unavailable and would corrupt the
  meaning of `disabled_by_gate`, which is reserved for a canonical external gate.
- **Contract removal — rejected.** Both product authorities require the capabilities. Removal would
  reduce the Super Administrator count below 22 and the total below 160.
- **Leaving them `planned` — rejected.** UI/UX plan §15.3 makes a missing page with a delivered
  backend a defect; once this decision assigns the backend, `planned` becomes untruthful.

## Permission decisions

The corrective scope reuses existing permissions wherever the page is a specialization of an
already-governed platform surface, and adds **exactly two** new keys.

| Page | Read | Mutate | New keys |
|---|---|---|---|
| `/billing/sms` | `platform.billing_settings.view` | `platform.billing_settings.update` | none |
| `/billing/subscriptions` | `platform.merchant.view` | none (monitoring only) | none |
| `/platform-access` | `platform.internal_access.view` | `platform.internal_access.manage` | **two** |
| `/platform/feature-flags` | `platform.settings.view` | `platform.settings.update` | none |

`/billing/subscriptions` additionally reveals conditional links guarded by
`platform.billing_reconciliation.view` and `platform.audit.view`.

Explicitly **not** created: `platform.sms_billing.*`, `platform.subscription.*`,
`platform.feature_flag.*`. Merchant-tenant keys such as `merchant.subscription.view` never grant
access to a platform page.

### `platform.internal_access.view`

```text
scope             platform
default role      super_admin
mfa_required      true
step_up_required  false
audit_severity    info
billing read-only n/a
period lock       n/a
merchant access   none
```

### `platform.internal_access.manage`

```text
scope             platform
default role      super_admin
mfa_required      true
step_up_required  true
audit_severity    critical for deactivation, permission increase and session-family revocation;
                  high for invite, suspend, reactivate and invitation lifecycle
billing read-only n/a
period lock       n/a
merchant access   none
```

Neither key is granted to any merchant-side role.

### Expected counts

```text
before   167 total   132 active   35 planned
after    169 total   134 active   35 planned
```

If the live matrix does not match the 167/132/35 baseline at edit time, stop and reconcile before
changing it.

## Data-model decisions

Expand-only migrations, data-dictionary entries first, migration-manifest updates, ULIDs externally,
backed enums with database CHECK constraints. No shipped migration is edited. No table is created
where the existing canonical substrate safely carries the requirement.

| Page | Data authority |
|---|---|
| `/billing/sms` | Extend the validated `settings` map of the existing effective-dated `platform_billing_settings`. Fields: `sms_unit_cost_minor`, `sms_tax_basis_points` (nullable), `sms_usage_warning_threshold_units` (nullable), `sms_usage_anomaly_threshold_basis_points` (nullable). Currency and effective dating come from the settings version. A dedicated `platform_sms_billing_rules` table is created **only** if it is proven that SMS fields cannot be scheduled independently without rescheduling unrelated billing settings — and the root cause must be documented first. There is never more than one active SMS pricing authority. |
| `/billing/subscriptions` | **No new table.** Reads existing Phase 20B truth only. |
| `/platform-access` | Only the missing lifecycle substrate: `platform_access_memberships`, `platform_access_invitations`, `platform_access_permission_overrides` — each created only where no existing canonical equivalent safely carries it. Reuses `users`, Magic Link authentication, MFA enrolment, session families and the existing permission override model. No second identity table. |
| `/platform/feature-flags` | `platform_feature_flags`, `platform_feature_flag_targets`, `platform_feature_flag_change_requests`, `platform_feature_flag_history` (append-only). The flag catalogue itself is a code-reviewed allowlist in `config/platform-feature-flags.php`; the API never creates arbitrary keys. |

Existing SMS usage rows in `sms_billing_entries` already snapshot `quantity`, `unit_cost_minor`,
`amount_minor`, `currency`, `status` and `billing_invoice_line_id`. A pricing change never
recalculates or rewrites an existing entry: the active rule is resolved at the usage event's
effective time and snapshotted once.

## API decisions

Specification first, then route-security tests, then implementation. Every endpoint carries a named
route, correct route class, `auth:sanctum`, the account guard, platform scope, permission
middleware, a policy, a Form Request, a thin controller, a domain service/action, a Resource, the
structured error envelope, pagination, masking, rate limiting where applicable, and audit.

`docs/api/openapi.json` remains the complete endpoint authority; it and
`resources/spa/src/types/generated/api.ts` are regenerated by the existing generators and never
hand-edited. Exact operation-count changes are recorded in `docs/proof/ui-08.md`.

Read routes carry `EnsurePermission` only. Mutations are `platform_mutation`, outside
`ResolveTenantContext`, using `ResolvePlatformContext`, with MFA, fresh step-up, mandatory reason
where a lifecycle or permission changes, and idempotency where a duplicate request could create a
duplicate effect.

No mutation endpoint is added for `/billing/subscriptions`. It is monitoring only.

## Security boundaries

This decision does not supersede security architecture, tenant isolation, merchant role boundaries,
money invariants, Wallet ownership, Refer & Earn ownership, Magic Link-only authentication, MFA and
step-up, audit immutability, permission parity, or OpenAPI authority.

Forbidden throughout the corrective scope: merchant creation; first Merchant Administrator creation;
impersonation; insertion into `merchant_users`, `branch_user_assignments` or `staff_profiles`;
merchant operational mutation; manual subscription payment recording; direct Daraja/provider
integration; Wallet-owned reconciliation; R&E-owned reward logic; password, OTP or WebAuthn
authentication; any permission change beyond the two authorized keys.

A feature flag is an **additional restrictive** rollout control. Evaluation fails closed in order:
code allowlist → environment → external gate → flag state → effective dates → target → rollout.
Authorization, entitlement and billing state remain separately enforced. A flag may turn an
otherwise authorized feature off; it may never turn an unauthorized feature on, and it is never
evaluated in the browser.

Self-protection rules on `/platform-access` are enforced server-side, not by UI warnings: an actor
cannot increase their own access, cannot assign merchant permissions or memberships, and cannot
suspend, deactivate or remove the last active Super Administrator.

## MFA and step-up

Every platform-group route requires MFA. Fresh step-up is required for: scheduling an SMS billing
version; every `/platform-access` mutation; and every production-sensitive feature-flag change,
including the emergency pause path. Step-up is enforced server-side; route visibility is never
step-up enforcement.

## Audit requirements

New audit events follow repository conventions and are append-only:

```text
platform.internal_access.invited
platform.internal_access.invitation_resent
platform.internal_access.invitation_revoked
platform.internal_access.permissions_changed
platform.internal_access.suspended
platform.internal_access.reactivated
platform.internal_access.deactivated
platform.internal_access.sessions_revoked
```

Feature-flag history is append-only with before/after configuration hashes, actor, action, reason
and correlation ID. No audit row is ever updated or deleted. No event carries a raw token, session
cookie, secret, or IP history beyond approved retention and masking.

## Wallet and Refer & Earn boundaries

Unchanged. Servana continues to implement only its own side of each integration contract. This
decision adds no Wallet capability, no provider credential surface, no raw callback surface, and no
R&E reward calculation or payout mutation. External Gate W remains closed and the five gate-blocked
pages remain `disabled_by_gate`.

## Frontend delivery

Each of the four pages receives a canonical host-relative route, a unique page title and `h1`, a
navigation identity, a deep link, a current screen specification, a lazy-loaded component, a bounded
store where required, real API data only, the applicable page states, responsive and dark-mode
behaviour, keyboard operation, and focused browser proof.

A page becomes `implemented` in the canonical contract **only after** its real API/read model,
permission mapping, route, lazy component, store (where required), screen specification, backend
tests, frontend tests and browser proof all exist — never at authorization time.

## Testing requirements

Focused backend groups (repository-conventional names where better ones exist):

```text
Ui08SmsBillingSettingsApiTest              Ui08FeatureFlagApiTest
Ui08SmsBillingRuleResolutionTest           Ui08FeatureFlagEvaluationTest
Ui08SmsBillingSnapshotImmutabilityTest     Ui08FeatureFlagMakerCheckerTest
Ui08SubscriptionOperationsApiTest          Ui08CorrectivePermissionParityTest
Ui08SubscriptionOperationsAuthorizationTest Ui08CorrectiveRouteSecurityTest
Ui08InternalPlatformAccessApiTest          Ui08NoForbiddenPlatformCapabilityTest
Ui08InternalPlatformAccessInvitationTest
Ui08InternalPlatformAccessSafetyTest
```

Every test exercises real selected code. A source-string-only assertion never substitutes for
behaviour. Security non-regression tests prove that merchant users and wrong accounts are denied,
that the host alone grants nothing, that no forbidden platform capability exists, that a feature flag
cannot bypass permission, entitlement, billing state or Gate W, that the SMS page exposes no contact
list, that the subscription page mutates nothing, and that internal access cannot self-escalate or
lock out the final administrator.

Frontend tests cover route, title, `h1`, navigation group, permission filtering, real API request,
loading, empty, error, no-permission, MFA/step-up, success, history, filters, pagination, responsive
cards, dark mode and keyboard operation for each page. Focused Playwright coverage is added to
`tests/e2e/ui-08-super-administrator-experience.spec.ts`. E2E uses isolated test fixtures; no
production seeder gains fake records.

## Traceability

`docs/traceability/servana-requirements.csv` gains or updates rows for `COR-UI08-001` covering: the
four pages, the two permission keys, each data object, each service/action, each controller and
endpoint, each policy, each frontend route and component, each test, manual verification, local
status and evidence path. No blank owner, no `TBD`, no free-form lifecycle status.

## Non-goals

```text
a general backend rewrite
unrelated schema correction or refactor
additional launch platform roles beyond super_admin
merchant-side capability of any kind
Wallet or Refer & Earn runtime
Phase 25 deployment work
UI-09 through UI-17 work
any permission change beyond the two authorized internal-access keys
arbitrary feature-flag creation through the API
seeding fake production flags to populate a page
```

## Supersession

This record supersedes nothing. It resolves `UI08-AMBIG-001` and is referenced by it. Should a later
authorized backend phase take ownership of any of these four capabilities, that phase's decision
record supersedes the corresponding section here and must say so explicitly.
