<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Subscription plan catalogue status (Plan §13.9, §47; Phase 20A). Mirrors the DB
 * CHECK on `subscription_plans.status`. Two-state lifecycle; changes run through the
 * named plan actions via the plan state machine. `retired` is terminal in Phase 20A
 * and preserves prices + entitlements (non-destructive).
 */
enum SubscriptionPlanStatus: string
{
    case Active = 'active';
    case Retired = 'retired';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Retired],
            self::Retired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
