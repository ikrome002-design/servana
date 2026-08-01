<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Enums;

/**
 * Why a context-handoff token was refused (Phase UI-03; ADR-018 step 10).
 *
 * Mirrors the `account_context_handoffs.invalidated_reason` DB CHECK exactly
 * (`SessionSchemaContractTest` asserts the two agree).
 *
 * NON-ENUMERATING BY CONSTRUCTION: the reason is written to the audit row and NEVER returned to
 * the caller. Every rejection produces the same uniform response, so an attacker probing a token
 * cannot learn which binding failed (UI/UX plan §5.4).
 */
enum HandoffRejectionReason: string
{
    case Expired = 'expired';

    /** The token had already been consumed — the replay case. */
    case Replayed = 'replayed';

    /** Presented on a host other than the one it was bound to (target substitution). */
    case WrongHost = 'wrong_host';

    case WrongEnvironment = 'wrong_environment';

    /** The target membership/role/branch/merchant is no longer valid at consume time. */
    case TargetUnavailable = 'target_unavailable';

    case FamilyRevoked = 'family_revoked';

    case SourceSessionRevoked = 'source_session_revoked';

    /** The user is suspended, deactivated, or otherwise no longer eligible to sign in. */
    case UserIneligible = 'user_ineligible';

    case UnsafeRedirect = 'unsafe_redirect';

    /** A newer handoff for the same user superseded this one. */
    case Superseded = 'superseded';

    /** @return list<string> the exact vocabulary, for schema-contract assertions */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
