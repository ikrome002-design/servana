# State machine — Platform access invitation

**Table:** `platform_access_invitations` · **Column:** `status` · **Enum:**
`App\Domain\PlatformAccess\Enums\PlatformAccessInvitationStatus` · **Guard:**
`App\Domain\PlatformAccess\Services\PlatformAccessInvitationStateMachine` · **DB backstop:**
`platform_access_invitations_status_check`, the accepted/revoked consistency CHECKs, `UNIQUE
(token_hash)` and the partial unique index `platform_access_invitations_pending_unique`

Plan §9 (Magic Link only), §3 rule 14 (hashed credentials), §18, §70; ADR-003 (replay posture),
ADR-019 (host/environment binding);
[`COR-UI08-001`](../../decisions/cor-ui08-001-super-administrator-backend-enablement.md) §11.6;
Phase UI-08.

## States

| State | Meaning |
|---|---|
| `pending` | Issued and still redeemable. At most one per email address (partial unique index). |
| `accepted` | Redeemed exactly once. **Terminal.** Carries `accepted_at` and `accepted_user_id`. |
| `revoked` | Withdrawn by an administrator before redemption. **Terminal.** |
| `expired` | Past `expires_at` without redemption. **Terminal.** |

## Transitions

```
(issue) ─► pending ─┬─► accepted (terminal)
                    ├─► revoked  (terminal)
                    └─► expired  (terminal)
```

| From | To | Driver | Controls |
|---|---|---|---|
| — | `pending` | `InvitePlatformAdministrator` | `platform.internal_access.manage`, MFA, fresh step-up, reason, idempotency |
| `pending` | `pending` | `ResendPlatformAccessInvitation` — **rotates the secret**, does not change state | same controls |
| `pending` | `accepted` | `AcceptPlatformAccessInvitation` — atomic single-use consume | possession of the raw token only |
| `pending` | `revoked` | `RevokePlatformAccessInvitation` | manage + step-up + mandatory reason |
| `pending` | `expired` | the clock, materialized lazily at read/consume time | none |

Every terminal state is final: there is no un-revoke and no un-expire. Re-admitting someone is a
**new invitation** with a new token and a new audit trail.

## Token handling

| Property | Rule |
|---|---|
| Generation | 64 cryptographically random bytes |
| At rest | **SHA-256 only** (`token_hash`, `UNIQUE`). The raw token exists solely inside the emailed link and is never persisted, logged, audited or returned |
| Lifetime | 72 hours (`expires_at > created_at` CHECK) |
| Single use | Consume takes `SELECT … FOR UPDATE`, then a **conditional** update `WHERE status = 'pending' AND expires_at > now()`. Two concurrent redemptions cannot both win |
| Resend | Mints a **new** token and replaces `token_hash`, so the previous link stops working immediately; `resend_count` increments, `last_sent_at` is stamped |
| Revocation | Sets `revoked_at`, `revoked_by_user_id` and `revocation_reason`; the hash stops matching anything redeemable |
| Binding | `purpose = 'platform_access'` and a closed `environment` vocabulary. A credential minted for one purpose or environment cannot be replayed into another |

## Enumeration safety

The invite endpoint returns the **same generic success shape** whether or not the address is already
known to the system, and never reveals whether a user exists, whether they already hold platform
access, or whether a previous invitation was revoked. Timing-sensitive branches do the same work in
both paths. `Ui08InternalPlatformAccessInvitationTest` asserts the responses are indistinguishable.

## Acceptance grants platform access and nothing else

Consuming the invitation moves the linked `platform_access_memberships` row `invited → active`
inside the same transaction and sets `users.is_platform_staff = true`. It writes **no**
`merchant_users`, `branch_user_assignments` or `staff_profiles` row, and assigns **no** merchant
role. Authentication remains Magic Link only — no password, OTP or WebAuthn credential is created
anywhere in this path.

## Audit

`platform.internal_access.invited` · `platform.internal_access.invitation_resent` ·
`platform.internal_access.invitation_revoked`. Context carries the masked email, the invitation
ULID and the actor — **never** the raw token or its hash.
