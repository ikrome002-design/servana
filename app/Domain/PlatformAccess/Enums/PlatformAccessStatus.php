<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Enums;

/**
 * Internal platform-access membership lifecycle (COR-UI08-001 §11; Phase UI-08).
 * Mirrors the `platform_access_memberships.status` CHECK exactly — the enum is the application
 * vocabulary, the CHECK is the backstop, and a contract test asserts the two agree.
 */
enum PlatformAccessStatus: string
{
    /** Invited but not yet accepted. Holds NO access: `users.is_platform_staff` stays false. */
    case Invited = 'invited';

    /** The only state that grants platform access. */
    case Active = 'active';

    /** Access withdrawn, reversibly. */
    case Suspended = 'suspended';

    /** Access withdrawn permanently. Terminal — re-admission needs a NEW invitation. */
    case Deactivated = 'deactivated';

    /** Whether this state grants platform access (the derived `is_platform_staff` mirror). */
    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return $this === self::Deactivated;
    }

    /** @return list<string> the exact vocabulary, for schema-contract assertions */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
