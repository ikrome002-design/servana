<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Enums;

/**
 * Why a session family or host session was revoked (Phase UI-03; ADR-018).
 *
 * Mirrors the `session_families.revoked_reason` and `host_sessions.revoked_reason` DB CHECKs
 * exactly — the enum is the application vocabulary, the CHECK is the backstop
 * (`SessionSchemaContractTest` asserts the two agree, so they cannot drift).
 *
 * This is forensic metadata, never authorization. Nothing reads a reason to decide access; a
 * revoked row is revoked regardless of why.
 */
enum SessionRevocationReason: string
{
    /** The user explicitly signed out of every host (logout-all). */
    case GlobalLogout = 'global_logout';

    /** The user was suspended at the identity level (Scope §2.3 checks 3 & 5). */
    case UserSuspended = 'user_suspended';

    case UserDeactivated = 'user_deactivated';

    case MerchantSuspended = 'merchant_suspended';

    case MerchantDeactivated = 'merchant_deactivated';

    /** The membership backing this context was suspended or removed. */
    case MembershipRevoked = 'membership_revoked';

    /** The membership's role changed, so the account context it fed is no longer valid. */
    case RoleChanged = 'role_changed';

    /** A branch assignment was withdrawn, invalidating branch-bound contexts. */
    case BranchRevoked = 'branch_revoked';

    /** The owner revoked one of their own sessions from the session list. */
    case SessionRevokedByOwner = 'session_revoked_by_owner';

    /** Ordinary sign-out on one host; other host sessions in the family are untouched. */
    case CurrentHostLogout = 'current_host_logout';

    /**
     * A platform administrator revoked another administrator's sessions from the internal
     * platform-access surface (COR-UI08-001 §11.7).
     *
     * It exists because the vocabulary had no truthful value for it: `session_revoked_by_owner`
     * means the owner revoked their OWN session and `global_logout` means the user signed out
     * everywhere. Reusing either would write a false forensic record. Suspension and deactivation
     * keep using `membership_revoked`, whose meaning already covers them exactly.
     */
    case PlatformAccessSessionsRevoked = 'platform_access_sessions_revoked';

    /** @return list<string> the exact vocabulary, for schema-contract assertions */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
