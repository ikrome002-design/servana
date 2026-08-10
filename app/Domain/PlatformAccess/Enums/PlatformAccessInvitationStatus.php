<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Enums;

/**
 * Platform-access invitation lifecycle (COR-UI08-001 §11.6; Phase UI-08).
 * Mirrors the `platform_access_invitations.status` CHECK exactly. Every non-pending state is
 * terminal: there is no un-revoke and no un-expire, so re-admitting someone is always a NEW
 * invitation with a new token and a new audit trail.
 */
enum PlatformAccessInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /** @return list<string> the exact vocabulary, for schema-contract assertions */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
