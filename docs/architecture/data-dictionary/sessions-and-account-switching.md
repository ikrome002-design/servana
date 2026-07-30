# Sessions and Account Switching — Data Dictionary

Canonical DDL authority for the Phase UI-03 authentication/session tables (Plan §13.2). Written
**before** the migrations, per the same rule that governs every other entry in this folder.

Governing sources: UI/UX plan §5.1–§5.4, §18.1–§18.7; ADR-018 (cross-subdomain account-context
switching); ADR-019 (Magic Link host binding); ADR-003 (idempotency and replay protection);
`Servana Software Development Plan.md` §9, §18, §70, §79 R6.

**Two invariants apply to every table below and are enforced by
`SessionSchemaContractTest`:**

1. **No permission snapshot.** No column here stores a permission key, a permission array, or any
   derived capability. Authority is re-resolved from the database on every request.
2. **No raw credential.** Only SHA-256 digests of tokens are stored. The single exception is
   `host_sessions.session_id`, which is the *same value already stored as `sessions.id`* in the
   same database — it is required to delete the session row on revocation, and it is never logged,
   audited, serialized or returned by any API.

---

## `magic_login_tokens` — Phase UI-03 host-binding columns (ADR-019)

The table is Phase 5 (`2026_06_14_000002_create_magic_login_tokens_table.php`) and its existing
guarantees are unchanged: SHA-256 at rest, 15-minute expiry, atomic single-use consume,
invalidation on suspension. UI-03 adds **binding only** — it removes nothing (ADR-019 non-goals).
No shipped migration is edited (guardrail 12); the change arrives as
`2026_07_30_000001_add_host_binding_to_magic_login_tokens.php`.

| Column (added) | Type | Null | Meaning |
|---|---|---|---|
| `user_id` | bigint | yes | FK → `users.id` `ON DELETE CASCADE`. The user the link was issued to, resolved at issue time. |
| `account_key` | varchar(64) | yes | The account experience the link was issued for — one of the eight canonical keys. `CHECK` against the closed list. |
| `intended_host` | varchar(253) | yes | The exact normalized host the link may be consumed on. Compared to the resolved request host at consume; a mismatch invalidates the link. |
| `environment` | varchar(16) | yes | `production` / `staging` / `local` / `testing`. `CHECK` against the closed list. Blocks cross-environment replay. |
| `redirect_path` | varchar(512) | yes | Safe relative post-auth route, validated by `AccountHostUrlGenerator::safeRelativePath()` at issue **and** re-validated at consume. Null ⇒ the account's default dashboard. |
| `audience` | varchar(32) | yes | Credential audience; `browser_login` today. `CHECK` against the closed list, reserved so a future non-browser credential can never consume a browser link. |

- **Nullability rationale (expand → backfill → constrain; guardrail 12):** the columns are added
  nullable because the shipped table already has rows. The same migration then sets
  `invalidated_at = now()` on every still-usable row, so no unbound credential survives the
  upgrade, and a shaped `CHECK` makes the invariant permanent:

  ```sql
  CHECK (
      invalidated_at IS NOT NULL
   OR consumed_at   IS NOT NULL
   OR (user_id IS NOT NULL AND account_key IS NOT NULL AND intended_host IS NOT NULL
       AND environment IS NOT NULL AND audience IS NOT NULL)
  )
  ```

  Any row that is still *usable* must be fully bound. Historical consumed/invalidated rows keep
  their nulls, so nothing is rewritten and no audit history is disturbed.
- **Indexes added:** `(user_id)`, `(account_key, environment)`.
- **Redaction:** none of these columns is a secret; the raw token remains absent from the database.
- **Audit events:** the existing `login_link_*` family, plus
  `auth.magic_link.host_binding_rejected` and `auth.magic_link.environment_binding_rejected`.
- **Tests:** `MagicLinkHostBindingTest`, `MagicLinkEnvironmentBindingTest`,
  `MagicLinkSafeRedirectTest`, `MagicLinkReplayConcurrencyTest`, `SessionSchemaContractTest`.

---

## `session_families` (Phase UI-03 — ADR-018)

- **Domain owner:** `app/Domain/Sessions`.
- **Purpose + scope refs:** the server-side link between one user's browser sessions across the
  eight account hosts, so global logout, suspension, role removal and branch removal act **once**
  and take effect on every host. UI/UX plan §5.2; ADR-018.
- **Tenant ownership:** **none** — identity-owned. A family may span merchants, so a `merchant_id`
  column would be wrong. Not covered by `BelongsToMerchant`; EXEMPT in `TenantOwnership`.
- **Primary key:** `id bigint identity`. **Public identifier:** `ulid char(26)` unique — a
  non-secret handle, never an authentication credential.

| Column | Type | Null | Meaning |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | public, non-secret family handle (unique) |
| `user_id` | bigint | no | FK → `users.id` `ON DELETE CASCADE` |
| `environment` | varchar(16) | no | `CHECK` against the closed environment list |
| `lifecycle_version` | integer | no | monotonic counter, default `1`, bumped on every revocation so a concurrent writer cannot resurrect a revoked family |
| `last_activity_at` | timestamptz | no | most recent authenticated request in any linked host session |
| `revoked_at` | timestamptz | yes | null ⇒ active |
| `revoked_reason` | varchar(64) | yes | closed vocabulary (below) |
| `revoked_by_user_id` | bigint | yes | FK → `users.id` `ON DELETE SET NULL`; the acting principal when revocation was administrative |
| `created_at` / `updated_at` | timestamptz | no | row timestamps |

- **`revoked_reason` vocabulary (CHECK):** `global_logout`, `user_suspended`, `user_deactivated`,
  `merchant_suspended`, `merchant_deactivated`, `membership_revoked`, `role_changed`,
  `branch_revoked`, `session_revoked_by_owner`, `current_host_logout`.
- **Consistency CHECK:** `(revoked_at IS NULL) = (revoked_reason IS NULL)`.
- **Indexes:** unique `(ulid)`, `(user_id, revoked_at)`, `(revoked_at)`.
- **Immutability:** `user_id`, `environment` and `created_at` are never rewritten; revocation is
  one-way — a revoked family is never un-revoked, a new login creates a new family.
- **Audit events:** `auth.session_family.created`, `auth.session_family.revoked`.
- **Migration order:** after `users`; forward-only; new table, no backfill.
- **Tests:** `SessionFamilyLifecycleTest`, `CrossHostRevocationTest`, `SessionSchemaContractTest`.

---

## `host_sessions` (Phase UI-03 — ADR-018)

- **Domain owner:** `app/Domain/Sessions`.
- **Purpose + scope refs:** binds ONE Laravel browser session to the account context it was created
  for, so revocation can be per-session, per-account, per-merchant, per-branch or family-wide, and
  so a user can inspect and revoke their own sessions. UI/UX plan §5.2; ADR-018.
- **Tenant ownership:** identity-owned with an **optional** merchant/branch reference. Rows are
  queried by user or family, never through a tenant-scoped read surface; EXEMPT in
  `TenantOwnership` for the same reason as `session_families`.

| Column | Type | Null | Meaning |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | public handle used by the own-session list/revoke API (unique) |
| `session_family_id` | bigint | no | FK → `session_families.id` `ON DELETE CASCADE` |
| `user_id` | bigint | no | FK → `users.id` `ON DELETE CASCADE` |
| `session_id` | varchar(255) | no | the Laravel `sessions.id` this row binds (unique). See invariant 2 above. |
| `account_key` | varchar(64) | no | the account experience this session is for; `CHECK` against the closed list |
| `host` | varchar(253) | no | the exact host the session was created on |
| `environment` | varchar(16) | no | `CHECK` against the closed list |
| `merchant_id` | bigint | yes | FK → `merchants.id` `ON DELETE CASCADE`; null only for the platform context |
| `merchant_user_id` | bigint | yes | FK → `merchant_users.id` `ON DELETE CASCADE`; the membership this context was built from |
| `branch_id` | bigint | yes | FK → `merchant_branches.id` `ON DELETE CASCADE`; set only for branch-bound contexts |
| `mfa_required_at_creation` | boolean | no | what `MfaRequirementResolver` answered when the session was created. **Evidence, not authority** — the live assertion stays `mfa_verified_at` inside the Laravel session and is never copied across hosts. |
| `last_activity_at` | timestamptz | no | most recent authenticated request on this session |
| `revoked_at` | timestamptz | yes | null ⇒ active |
| `revoked_reason` | varchar(64) | yes | same closed vocabulary as `session_families` |
| `created_at` / `updated_at` | timestamptz | no | row timestamps |

- **Consistency CHECKs:**
  - `(revoked_at IS NULL) = (revoked_reason IS NULL)`;
  - `(account_key = 'super_administrator') = (merchant_id IS NULL)` — the platform context is the
    only one without a merchant, and every merchant-side context must name its merchant;
  - `(branch_id IS NULL OR merchant_id IS NOT NULL)`.
- **Indexes:** unique `(ulid)`, unique `(session_id)`, `(user_id, revoked_at)`,
  `(session_family_id, revoked_at)`, `(merchant_id, revoked_at)`, `(branch_id, revoked_at)`,
  `(merchant_user_id, revoked_at)`, `(account_key)`.
- **Audit events:** `auth.host_session.created`, `auth.host_session.revoked`.
- **Migration order:** after `session_families`, `merchants`, `merchant_users`,
  `merchant_branches`; forward-only; new table, no backfill.
- **Tests:** `HostScopedSessionTest`, `OwnSessionManagementTest`, `CrossHostRevocationTest`,
  `SessionSchemaContractTest`.

---

## `account_context_handoffs` (Phase UI-03 — ADR-018)

- **Domain owner:** `app/Domain/Sessions`.
- **Purpose + scope refs:** the single-use, short-lived, hashed credential that carries a user from
  a source account host to a target account host without re-authenticating and **without** carrying
  the source permission set. UI/UX plan §5.3; ADR-018; replay posture per ADR-003.
- **Tenant ownership:** identity-owned; `target_merchant_id` is a *destination reference*, not a
  tenancy scope. EXEMPT in `TenantOwnership`.

| Column | Type | Null | Meaning |
|---|---|---|---|
| `id` | bigint identity | no | internal PK |
| `ulid` | char(26) | no | public handle for audit correlation (unique). **Not** the credential. |
| `token_hash` | char(64) | no | SHA-256 of the 64-byte random raw token (unique). The raw token is never stored. |
| `user_id` | bigint | no | FK → `users.id` `ON DELETE CASCADE` |
| `source_session_family_id` | bigint | no | FK → `session_families.id` `ON DELETE CASCADE` |
| `source_host_session_id` | bigint | yes | FK → `host_sessions.id` `ON DELETE SET NULL` |
| `source_account_key` | varchar(64) | no | `CHECK` against the closed account list |
| `target_account_key` | varchar(64) | no | `CHECK` against the closed account list |
| `target_host` | varchar(253) | no | exact host taken from the registry, never from a request header |
| `environment` | varchar(16) | no | `CHECK` against the closed list |
| `target_merchant_id` | bigint | yes | FK → `merchants.id` `ON DELETE CASCADE` |
| `target_merchant_user_id` | bigint | yes | FK → `merchant_users.id` `ON DELETE CASCADE` |
| `target_branch_id` | bigint | yes | FK → `merchant_branches.id` `ON DELETE CASCADE` |
| `redirect_path` | varchar(512) | yes | safe relative deep link within the target account, re-validated at consume |
| `expires_at` | timestamptz | no | `created_at + 120s` |
| `consumed_at` | timestamptz | yes | set atomically inside the locked consume transaction |
| `invalidated_at` | timestamptz | yes | set on any rejection so a probed token cannot be retried |
| `invalidated_reason` | varchar(64) | yes | closed rejection vocabulary; audit only, never returned |
| `ip_hash` | char(64) | yes | SHA-256 of the issuing IP — correlation without retaining the address |
| `user_agent_hash` | char(64) | yes | SHA-256 of the issuing user agent |
| `created_at` / `updated_at` | timestamptz | no | row timestamps |

- **`invalidated_reason` vocabulary (CHECK):** `expired`, `replayed`, `wrong_host`,
  `wrong_environment`, `target_unavailable`, `family_revoked`, `source_session_revoked`,
  `user_ineligible`, `unsafe_redirect`, `superseded`.
- **CHECKs:** `expires_at > created_at`;
  `NOT (consumed_at IS NOT NULL AND invalidated_at IS NOT NULL)`;
  `(invalidated_at IS NULL) = (invalidated_reason IS NULL)`;
  `(target_account_key = 'super_administrator') = (target_merchant_id IS NULL)`;
  `(target_branch_id IS NULL OR target_merchant_id IS NOT NULL)`.
- **Unique constraints:** `(token_hash)` — the primary anti-duplication guarantee.
- **Indexes:** unique `(ulid)`, unique `(token_hash)`, `(user_id, consumed_at)`, `(expires_at)`,
  `(source_session_family_id)`.
- **Concurrency:** consume runs `SELECT … FOR UPDATE` on the row inside a transaction, then a
  conditional update that must affect exactly one row. Two simultaneous consumers cannot both win
  (`ContextHandoffConcurrencyTest` proves this against real PostgreSQL, on two connections).
- **Retention:** `sessions:prune-handoffs` deletes rows past `expires_at + 24h`; consumed and
  invalidated rows are kept for that window as forensic evidence, then removed.
- **Audit events:** `auth.context_handoff.issued`, `auth.context_handoff.consumed`,
  `auth.context_handoff.rejected`, `auth.context_handoff.replay_rejected`.
- **Migration order:** after `session_families` and `host_sessions`; forward-only; new table, no
  backfill.
- **Tests:** `ContextHandoffIssueTest`, `ContextHandoffConsumeTest`, `ContextHandoffReplayTest`,
  `ContextHandoffConcurrencyTest`, `ContextHandoffAuthorizationFreshnessTest`,
  `SessionSchemaContractTest`.
