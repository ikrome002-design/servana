<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

/**
 * Outcome of an entitlement check (Plan §20; Phase 20A). Immutable. `allowed` is the gate
 * verdict; `code` is an upgrade-relevant reason for a denial. The resolver has no side effects —
 * denying an over-limit action never deletes data (downgrade is no-data-loss at the service
 * level).
 */
final readonly class EntitlementDecision
{
    public const CODE_ALLOWED = 'allowed';

    public const CODE_ABSENT = 'entitlement_absent';

    public const CODE_DISABLED = 'entitlement_disabled';

    public const CODE_LIMIT_EXCEEDED = 'entitlement_limit_exceeded';

    public const CODE_NO_PLAN = 'no_active_plan';

    public function __construct(
        public bool $allowed,
        public string $code,
        public ?int $limit = null,
    ) {}

    public static function allow(?int $limit = null): self
    {
        return new self(true, self::CODE_ALLOWED, $limit);
    }

    public static function deny(string $code, ?int $limit = null): self
    {
        return new self(false, $code, $limit);
    }
}
