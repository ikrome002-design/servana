# Platform governance — Data Dictionary (COR-UI08-001; Phase UI-08)

Canonical DDL for the internal-platform-access and platform-feature-flag substrate authorized by
[`COR-UI08-001`](../../decisions/cor-ui08-001-super-administrator-backend-enablement.md) so that
navigation-map §5.4.19 (`/platform-access`) and §5.4.20 (`/platform/feature-flags`) can be delivered
truthfully inside Phase UI-08.

Controlling sources: **Plan §7.1** (identity), **§9** (Magic Link only), **§10.2/§10.3**
(authority boundaries, permission matrix), **§13.1–§13.3** (schema discipline), **§18** (MFA and
step-up), **§19.3** (policies), **§24.1** (route classes), **§70** (audit); **ADR-004**
(forward-only, expand-and-contract, dictionary-before-migration), **ADR-008** (typed audit events),
**ADR-017** (a hostname is never authorization), **ADR-018** (session families).

State machines:
[`platform-access-membership.md`](../state-machines/platform-access-membership.md) ·
[`platform-access-invitation.md`](../state-machines/platform-access-invitation.md) ·
[`platform-feature-flag.md`](../state-machines/platform-feature-flag.md) ·
[`platform-feature-flag-change-request.md`](../state-machines/platform-feature-flag-change-request.md).

All seven tables are **`PLATFORM_OWNED`**: they carry **no `merchant_id`, no `branch_id` and no
`staff_profile_id`**, are registered in `App\Domain\Tenancy\Support\TenantOwnership::EXEMPT`, and
are reached only through Super-Administrator platform routes (mandatory MFA; fresh step-up on every
mutation). ULIDs are the only public identifiers.

---

## Part 1 — Internal platform access

### Why new tables are required (proven, not assumed)

Measured at `16d544c5`:

| Candidate reuse | Why it cannot carry the requirement |
|---|---|
| `users.is_platform_staff` (`2026_06_14_000100`) | A bare boolean. It has **no status vocabulary, no invited/active/suspended/deactivated lifecycle, no actor, no reason, no activation/suspension/deactivation timestamps and no invitation link**. Its own migration says "set by platform seeders only". A boolean cannot answer "who granted this, when, why, and is it currently suspended?" |
| `staff_invitations` (`2026_06_15_000102`) | `merchant_id` and `branch_id` are **NOT NULL**, and `staff_invitations_role_check` restricts `role` to the six merchant staff roles — `super_admin` is structurally unrepresentable. Inviting a platform administrator through it would require inventing a merchant and a branch for a person who must never hold either. |
| `merchant_user_permission_overrides` | Keyed on `merchant_user_id` (NOT NULL FK to `merchant_users`). A platform administrator has **no** `merchant_users` row by design, so the table cannot address them. `PermissionResolver::forPlatformStaff()` correspondingly applies **no overrides at all** today. |
| `session_families` / `host_sessions` | Already correct and **reused unchanged** — no second session store is created. Only the closed `revoked_reason` vocabulary is expanded (below). |
| `users`, `magic_login_tokens`, `mfa_credentials`, `mfa_recovery_codes` | Already correct and **reused unchanged** — no second identity table, no second credential store, no password/OTP/WebAuthn path. |

`users.is_platform_staff` is **retained as the eligibility flag** (`LoginEligibilityService`,
`AccountContextResolver`, `TenantContextResolver`, `ResolvePlatformContext`,
`MfaRequirementResolver` all read it). It becomes a **derived mirror** of the membership: every
lifecycle action writes the membership row and the flag inside one transaction, so the existing
eligibility path keeps working byte-for-byte while the membership becomes the lifecycle authority.
`PlatformAccessMembershipMirrorTest` proves the two can never disagree.

---

### `platform_access_memberships`

**Classification:** `PLATFORM_OWNED`. Model `App\Domain\PlatformAccess\Models\PlatformAccessMembership`.
Public route key: `ulid`. **Exactly one current platform membership per user** (`UNIQUE(user_id)`).

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | internal PK; never exposed |
| `ulid` | char(26) | no | public id + route key; `UNIQUE` |
| `user_id` | bigint | no | FK `users(id)` ON DELETE RESTRICT; **`UNIQUE`** — one platform membership per identity |
| `role_key` | varchar(32) | no | CHECK ∈ (`super_admin`) — the only launch platform role (COR-UI08-001 §11.1) |
| `status` | varchar(20) | no | CHECK ∈ (`invited`,`active`,`suspended`,`deactivated`); mirrors `PlatformAccessStatus` |
| `invitation_id` | bigint | yes | FK `platform_access_invitations(id)` ON DELETE RESTRICT — the invitation that created it (null for the seeded genesis administrator) |
| `invited_by_user_id` | bigint | yes | FK `users(id)` ON DELETE RESTRICT |
| `invited_at` | timestamptz | yes | |
| `activated_at` | timestamptz | yes | set once when the invitation is accepted |
| `suspended_at` | timestamptz | yes | cleared on reactivation |
| `deactivated_at` | timestamptz | yes | terminal |
| `last_action` | varchar(32) | yes | CHECK ∈ (`invited`,`activated`,`suspended`,`reactivated`,`deactivated`,`permissions_changed`,`sessions_revoked`) |
| `last_action_reason` | varchar(500) | yes | the mandatory operator reason for the most recent lifecycle action |
| `last_action_by_user_id` | bigint | yes | FK `users(id)` ON DELETE RESTRICT |
| `last_action_at` | timestamptz | yes | |
| `created_at` / `updated_at` | timestamptz | no | |

**Structurally absent columns (and they may never be added):** `merchant_id`, `branch_id`,
`staff_profile_id`, any permission snapshot, any password/secret material.

Constraints:

```text
platform_access_memberships_role_key_check        role_key IN ('super_admin')
platform_access_memberships_status_check          status IN ('invited','active','suspended','deactivated')
platform_access_memberships_last_action_check     last_action IS NULL OR last_action IN (…7 values…)
platform_access_memberships_active_check          status <> 'active'      OR activated_at   IS NOT NULL
platform_access_memberships_suspended_check       status <> 'suspended'   OR suspended_at   IS NOT NULL
platform_access_memberships_deactivated_check     status <> 'deactivated' OR deactivated_at IS NOT NULL
platform_access_memberships_invited_check         status <> 'invited'     OR activated_at   IS NULL
UNIQUE (user_id)                                  one current platform membership per user
INDEX (status)                                    roster filtering
```

`status = 'active'` is the **only** state that yields `users.is_platform_staff = true`.

---

### `platform_access_invitations`

**Classification:** `PLATFORM_OWNED`. Model `App\Domain\PlatformAccess\Models\PlatformAccessInvitation`.
Public route key: `ulid`. Magic Link-compatible: acceptance issues an ordinary host-bound Magic
Link — **no password, no OTP, no WebAuthn** is introduced anywhere in this path.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | |
| `ulid` | char(26) | no | public id + route key; `UNIQUE` |
| `email` | varchar(255) | no | normalized lowercase; `CHECK (email = lower(email))` |
| `role_key` | varchar(32) | no | CHECK ∈ (`super_admin`) |
| `purpose` | varchar(32) | no | CHECK ∈ (`platform_access`) — **purpose binding**: this credential can grant nothing else |
| `environment` | varchar(16) | no | CHECK ∈ (`production`,`staging`,`local`,`testing`) — an invitation minted in one environment cannot be consumed in another |
| `token_hash` | char(64) | no | **SHA-256 of a 64-byte random token; `UNIQUE`. The raw token exists only inside the emailed link** — never stored, logged, audited or returned by any Resource (Plan §3 rule 14) |
| `status` | varchar(20) | no | CHECK ∈ (`pending`,`accepted`,`revoked`,`expired`) |
| `invited_by_user_id` | bigint | no | FK `users(id)` ON DELETE RESTRICT |
| `accepted_user_id` | bigint | yes | FK `users(id)` ON DELETE RESTRICT |
| `expires_at` | timestamptz | no | 72 hours, matching `staff_invitations` |
| `accepted_at` | timestamptz | yes | |
| `revoked_at` | timestamptz | yes | |
| `revoked_by_user_id` | bigint | yes | FK `users(id)` ON DELETE RESTRICT |
| `revocation_reason` | varchar(500) | yes | mandatory when revoked |
| `resend_count` | smallint | no | default 0; `CHECK (resend_count >= 0)` |
| `last_sent_at` | timestamptz | yes | |
| `created_at` / `updated_at` | timestamptz | no | |

Constraints:

```text
platform_access_invitations_email_lower_check    email = lower(email)
platform_access_invitations_role_key_check       role_key IN ('super_admin')
platform_access_invitations_purpose_check        purpose  IN ('platform_access')
platform_access_invitations_environment_check    environment IN ('production','staging','local','testing')
platform_access_invitations_status_check         status IN ('pending','accepted','revoked','expired')
platform_access_invitations_expiry_check         expires_at > created_at
platform_access_invitations_accepted_check       status <> 'accepted' OR (accepted_at IS NOT NULL AND accepted_user_id IS NOT NULL)
platform_access_invitations_revoked_check        status <> 'revoked'  OR (revoked_at IS NOT NULL AND revoked_by_user_id IS NOT NULL AND revocation_reason IS NOT NULL)
platform_access_invitations_resend_count_check   resend_count >= 0
UNIQUE (token_hash)
UNIQUE INDEX platform_access_invitations_pending_unique ON (email) WHERE status = 'pending'
INDEX (status)
```

**Single-use consumption** is atomic: the consume path takes `SELECT … FOR UPDATE` on the row and
applies a **conditional** update (`WHERE status = 'pending' AND expires_at > now()`), so two
concurrent redemptions cannot both win. **Resend rotates the secret**: a fresh 64-byte token is
minted, `token_hash` is replaced (invalidating the previous link), `resend_count` increments and
`last_sent_at` is stamped. Every API response on the invitation surface is
**enumeration-safe/generic** — it never discloses whether an address is already known.

---

### `platform_access_permission_overrides`

**Classification:** `PLATFORM_OWNED`. Model
`App\Domain\PlatformAccess\Models\PlatformAccessPermissionOverride`.
**Deny-only at launch** — an override can subtract from the `super_admin` default grants and can
never add to them, which is what makes self-escalation structurally impossible rather than merely
policed.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | |
| `ulid` | char(26) | no | `UNIQUE` |
| `platform_access_membership_id` | bigint | no | FK `platform_access_memberships(id)` ON DELETE CASCADE |
| `permission_id` | bigint | no | FK `permissions(id)` ON DELETE RESTRICT |
| `effect` | varchar(8) | no | CHECK ∈ (`deny`) — **`grant` is unrepresentable at launch** |
| `reason` | varchar(500) | no | mandatory |
| `created_by_user_id` | bigint | no | FK `users(id)` ON DELETE RESTRICT |
| `created_at` / `updated_at` | timestamptz | no | |

Constraints:

```text
platform_access_permission_overrides_effect_check   effect IN ('deny')
UNIQUE (platform_access_membership_id, permission_id)
TRIGGER platform_access_permission_overrides_scope_guard
    BEFORE INSERT OR UPDATE — rejects any permission whose permissions.category <> 'platform'
```

The trigger is the structural half of "no merchant permission may be referenced here": even a
compromised service layer cannot write a merchant key into this table. `PermissionResolver`
subtracts these denies inside `forPlatformStaff()`; deny always wins, exactly as it does for
memberships.

---

### `session_families` / `host_sessions` — one expanded vocabulary value

Administrator-initiated session revocation needs a **truthful** reason. The existing closed
vocabulary has none: `session_revoked_by_owner` means the owner revoked their own session, and
`global_logout` means the user signed out everywhere. Reusing either would write a false forensic
record.

One `expand` migration replaces the two `revoked_reason` CHECK constraints (the shipped Phase UI-03
migrations are **not** edited — guardrail 12) to add exactly:

```text
platform_access_sessions_revoked
```

`SessionRevocationReason` gains the matching case; `SessionSchemaContractTest` already asserts the
enum and both CHECKs agree, so the three cannot drift.

Suspension and deactivation continue to use the existing `membership_revoked` reason, whose own
docblock is "the membership backing this context was suspended or removed" — which is precisely
what a suspended or deactivated platform membership is. **No new session table is created.**

---

## Part 2 — Platform feature flags

A feature flag here is an **additional restrictive rollout control**, never an authorization
mechanism. It can turn an otherwise-authorized capability **off**; it can never turn an
unauthorized capability **on**, and it is never evaluated in the browser.

The **flag catalogue is code**, not data: `config/platform-feature-flags.php` is a reviewed
allowlist, and the API can only act on keys that already exist there. An empty production catalogue
is a truthful state and is never padded with fabricated flags.

### `platform_feature_flags`

**Classification:** `PLATFORM_OWNED`. Model
`App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlag`. Route key: `flag_key` scoped by
environment (`ulid` remains the public id in payloads).

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | |
| `ulid` | char(26) | no | `UNIQUE` |
| `flag_key` | varchar(64) | no | must exist in the code allowlist; an unknown key fails closed at the service boundary |
| `environment` | varchar(16) | no | CHECK ∈ (`production`,`staging`,`local`,`testing`) |
| `state` | varchar(16) | no | CHECK ∈ (`inactive`,`scheduled`,`active`,`paused`,`retired`) |
| `rollout_basis_points` | int | no | default 0; `CHECK (0 … 10000)` — deterministic bucketing, never a float percentage |
| `effective_from` | timestamptz | yes | required for `scheduled` and `active` |
| `effective_to` | timestamptz | yes | `CHECK (effective_to IS NULL OR (effective_from IS NOT NULL AND effective_to > effective_from))` |
| `version` | int | no | default 1; `CHECK (version >= 1)`; incremented on every applied change |
| `approved_configuration_hash` | char(64) | yes | SHA-256 of the exact approved configuration that produced the live state |
| `applied_change_request_id` | bigint | yes | FK `platform_feature_flag_change_requests(id)` ON DELETE RESTRICT |
| `updated_by_user_id` | bigint | yes | FK `users(id)` ON DELETE RESTRICT |
| `created_at` / `updated_at` | timestamptz | no | |

```text
UNIQUE (flag_key, environment)      one row per flag per environment — environments never share state
platform_feature_flags_state_check          state IN (…5 values…)
platform_feature_flags_rollout_check        rollout_basis_points BETWEEN 0 AND 10000
platform_feature_flags_dating_check         effective_to IS NULL OR (effective_from IS NOT NULL AND effective_to > effective_from)
platform_feature_flags_scheduled_check      state NOT IN ('scheduled','active') OR effective_from IS NOT NULL
platform_feature_flags_version_check        version >= 1
INDEX (environment, state)
```

### `platform_feature_flag_targets`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | |
| `ulid` | char(26) | no | `UNIQUE` |
| `feature_flag_id` | bigint | no | FK `platform_feature_flags(id)` ON DELETE CASCADE |
| `target_type` | varchar(16) | no | CHECK ∈ (`merchant`,`plan`,`cohort`) |
| `target_value` | varchar(64) | no | a merchant ULID, a plan key or an allowlisted cohort key — **a scalar identifier, never an expression** |
| `created_by_user_id` | bigint | no | FK `users(id)` ON DELETE RESTRICT |
| `created_at` / `updated_at` | timestamptz | no | |

```text
platform_feature_flag_targets_type_check   target_type IN ('merchant','plan','cohort')
UNIQUE (feature_flag_id, target_type, target_value)
```

**No arbitrary targeting logic is storable.** The closed `target_type` vocabulary plus a scalar
`target_value` means there is nowhere to persist an executable predicate, so no stored value can
ever be evaluated as code.

### `platform_feature_flag_change_requests`

Maker/checker is **structural**, not procedural.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | |
| `ulid` | char(26) | no | `UNIQUE` |
| `feature_flag_id` | bigint | no | FK `platform_feature_flags(id)` ON DELETE RESTRICT |
| `status` | varchar(12) | no | CHECK ∈ (`pending`,`approved`,`rejected`,`cancelled`,`applied`,`failed`) |
| `proposed_configuration` | jsonb | no | `CHECK (jsonb_typeof = 'object')` — state, rollout, dating and targets |
| `proposed_configuration_hash` | char(64) | no | SHA-256 of the canonical serialization |
| `impact_statement` | text | no | **mandatory** |
| `rollback_plan` | text | no | **mandatory** |
| `health_criterion` | text | no | **mandatory** — the observable condition that triggers rollback |
| `reason` | varchar(500) | no | **mandatory** |
| `requested_by_user_id` | bigint | no | FK `users(id)` ON DELETE RESTRICT |
| `approved_by_user_id` | bigint | yes | FK `users(id)` ON DELETE RESTRICT |
| `requested_at` | timestamptz | no | |
| `decided_at` | timestamptz | yes | |
| `applied_at` | timestamptz | yes | |
| `decision_note` | varchar(500) | yes | mandatory on reject |
| `failure_reason` | varchar(500) | yes | |
| `created_at` / `updated_at` | timestamptz | no | |

```text
platform_feature_flag_change_requests_status_check      status IN (…6 values…)
platform_feature_flag_change_requests_maker_checker_check
        approved_by_user_id IS NULL OR approved_by_user_id <> requested_by_user_id
platform_feature_flag_change_requests_approved_check    status NOT IN ('approved','applied') OR (approved_by_user_id IS NOT NULL AND decided_at IS NOT NULL)
platform_feature_flag_change_requests_rejected_check     status <> 'rejected' OR (decided_at IS NOT NULL AND decision_note IS NOT NULL)
platform_feature_flag_change_requests_applied_check      status <> 'applied'  OR applied_at IS NOT NULL
platform_feature_flag_change_requests_failed_check       status <> 'failed'   OR failure_reason IS NOT NULL
platform_feature_flag_change_requests_config_object_check jsonb_typeof(proposed_configuration) = 'object'
UNIQUE INDEX …_pending_unique ON (feature_flag_id) WHERE status = 'pending'
```

The maker/checker CHECK means a self-approved production change **cannot exist as a row**, even if
every layer above the database were bypassed.

### `platform_feature_flag_history` (append-only)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint identity | no | |
| `ulid` | char(26) | no | `UNIQUE` |
| `feature_flag_id` | bigint | no | FK `platform_feature_flags(id)` ON DELETE RESTRICT |
| `change_request_id` | bigint | yes | FK `platform_feature_flag_change_requests(id)` ON DELETE RESTRICT |
| `action` | varchar(32) | no | CHECK ∈ (`created`,`change_requested`,`approved`,`rejected`,`cancelled`,`applied`,`paused`,`retired`,`failed`) |
| `before_configuration` | jsonb | yes | object CHECK when present |
| `after_configuration` | jsonb | yes | object CHECK when present |
| `before_hash` | char(64) | yes | |
| `after_hash` | char(64) | yes | |
| `actor_user_id` | bigint | yes | FK `users(id)` ON DELETE RESTRICT |
| `reason` | varchar(500) | yes | |
| `correlation_id` | char(26) | yes | ULID correlating the request → decision → application chain |
| `created_at` | timestamptz | no | no `updated_at` — a history row is never updated |

```text
platform_feature_flag_history_action_check      action IN (…9 values…)
platform_feature_flag_history_before_object_check  before_configuration IS NULL OR jsonb_typeof(before_configuration) = 'object'
platform_feature_flag_history_after_object_check   after_configuration  IS NULL OR jsonb_typeof(after_configuration)  = 'object'
TRIGGER platform_feature_flag_history_append_only   BEFORE UPDATE OR DELETE — always raises
INDEX (feature_flag_id, created_at)
```

The trigger gives history the same append-only guarantee `audit_logs` has (guardrail 5): no UPDATE,
no DELETE, ever.

---

## Retention, masking and logging

| Value | Rule |
|---|---|
| Raw invitation token | Never persisted. Exists only inside the emailed link. |
| `token_hash` | `$hidden` on the model; never serialized by any Resource; never audited. |
| Session ids / cookies | Never touched by this surface. Revocation acts on families, not on tokens. |
| MFA secrets and recovery codes | Never read or written here. |
| Email | Stored normalized; masked in audit context by the existing masker; the roster shows the full address only to a holder of `platform.internal_access.view`. |
| IP / device history | **Not stored by these tables at all.** The roster's "last login" and "active sessions" come from the existing session-family surface, within its approved fields. |
| Feature-flag configuration | Contains flag state only — never a credential, never merchant PII. |

## Audit events (ADR-008; typed cases in `App\Domain\Audit\Enums\AuditEvent`)

```text
platform.internal_access.invited              platform.internal_access.reactivated
platform.internal_access.invitation_resent    platform.internal_access.deactivated
platform.internal_access.invitation_revoked   platform.internal_access.sessions_revoked
platform.internal_access.permissions_changed  platform.feature_flag.change_requested
platform.internal_access.suspended            platform.feature_flag.change_approved
                                              platform.feature_flag.change_rejected
                                              platform.feature_flag.change_cancelled
                                              platform.feature_flag.applied
                                              platform.feature_flag.paused
```

Severity: `crit` for deactivation, permission change and session-family revocation, and for any
applied production feature-flag change; `high` for invite, suspend, reactivate, invitation
lifecycle and every other feature-flag transition.

## Migration manifest

Every table above is registered in `docs/architecture/migrations/manifest.yaml` with
`owner_phase: "UI-08 (COR-UI08-001)"`, `change_type: create` (or `expand` for the session
`revoked_reason` vocabulary), this file as `data_dictionary`, and `docs/proof/ui-08.md` as
`verification`. No shipped migration is edited (guardrail 12, ADR-004).
