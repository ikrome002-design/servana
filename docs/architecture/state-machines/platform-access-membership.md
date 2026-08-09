# State machine — Platform access membership

**Table:** `platform_access_memberships` · **Column:** `status` · **Enum:**
`App\Domain\PlatformAccess\Enums\PlatformAccessStatus` · **Guard:**
`App\Domain\PlatformAccess\Services\PlatformAccessStateMachine` · **DB backstop:**
`platform_access_memberships_status_check` + the four timestamp-consistency CHECKs +
`UNIQUE (user_id)`

Plan §7.1, §9, §10.2, §18, §25.1, §70; ADR-008, ADR-017, ADR-018;
[`COR-UI08-001`](../../decisions/cor-ui08-001-super-administrator-backend-enablement.md) §11;
Phase UI-08.

## States

| State | Meaning |
|---|---|
| `invited` | An invitation exists and has not been accepted. **Holds no access**: `users.is_platform_staff` stays `false`, so the Magic Link eligibility check and `ResolvePlatformContext` both deny. |
| `active` | The invitation was accepted. This is the only state that sets `users.is_platform_staff = true`. |
| `suspended` | Access withdrawn, reversibly. Sessions are revoked; the flag returns to `false`. |
| `deactivated` | Access withdrawn permanently. **Terminal.** |

## Transitions

```
(invite) ─► invited ─┬─► active ─┬─► suspended ─┬─► active        (reactivate)
                     │           │              └─► deactivated   (terminal)
                     │           └─► deactivated (terminal)
                     ├─► deactivated  (invitation revoked / expired)
                     └─► (row remains invited until accepted, revoked or expired)
```

| From | To | Action | Controls |
|---|---|---|---|
| — | `invited` | `InvitePlatformAdministrator` | `platform.internal_access.manage`, MFA, fresh step-up, reason, idempotency |
| `invited` | `active` | `AcceptPlatformAccessInvitation` | Magic Link consumption only — never an administrator action |
| `invited` | `deactivated` | `RevokePlatformAccessInvitation` | manage + step-up + reason |
| `active` | `suspended` | `SuspendPlatformAdministrator` | manage + step-up + reason; revokes session families |
| `suspended` | `active` | `ReactivatePlatformAdministrator` | manage + step-up + reason |
| `active` / `suspended` | `deactivated` | `DeactivatePlatformAdministrator` | manage + step-up + reason; revokes session families |

`deactivated` is terminal: re-admitting a person requires a **new invitation**, which produces a new
audit trail rather than resurrecting an old grant.

## The lockout invariant (server-side, not a UI warning)

Every transition that would reduce the number of `active` memberships runs inside the mutating
transaction with `SELECT … FOR UPDATE` over the active set and **refuses** when it would leave zero
active Super Administrators. It also refuses when the actor is the target and the action reduces
their own access. Both refusals return `422 invalid_state_transition` with a specific code:

```text
platform_access.last_active_administrator
platform_access.self_action_forbidden
```

`Ui08InternalPlatformAccessSafetyTest` proves both against real rows, including the concurrent case.

## Self-escalation is structurally impossible

Permission overrides are **deny-only** (`platform_access_permission_overrides_effect_check` permits
only `'deny'`), and a trigger rejects any permission whose `permissions.category <> 'platform'`. So
"increase my own access" and "grant myself a merchant capability" are not merely denied by policy —
neither can be represented as a row.

## What this machine never does

It never writes `merchant_users`, `branch_user_assignments` or `staff_profiles`; it never creates a
merchant, a branch or a first Merchant Administrator; it never impersonates; and it never assigns
any of the seven merchant roles. `Ui08NoForbiddenPlatformCapabilityTest` asserts the absence of
every such route and of any write to those tables.

## Mirror invariant

`users.is_platform_staff` is a derived mirror of `status = 'active'`, written in the same
transaction as every transition. `PlatformAccessMembershipMirrorTest` proves the two can never
disagree, which is what lets the shipped eligibility, context and MFA paths keep reading the flag
unchanged.

## Audit

```text
platform.internal_access.invited              platform.internal_access.reactivated
platform.internal_access.invitation_resent    platform.internal_access.deactivated
platform.internal_access.invitation_revoked   platform.internal_access.sessions_revoked
platform.internal_access.permissions_changed  platform.internal_access.suspended
```

Severity `crit` for deactivation, permission change and session-family revocation; `high` for the
rest. No event ever carries a raw token, a session id, an MFA secret or unmasked device history.
