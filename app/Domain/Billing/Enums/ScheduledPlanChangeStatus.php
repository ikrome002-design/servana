<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Canonical scheduled-plan-change statuses (Plan §13.9, §48; Phase 20B). No-proration
 * next-cycle plan changes. Used consistently across the PHP enum, the PostgreSQL CHECK
 * on `scheduled_plan_changes.status`, factories, request validation/OpenAPI/TS, and
 * audit context. `applied`/`cancelled` are terminal (immutable history).
 */
enum ScheduledPlanChangeStatus: string
{
    case Scheduled = 'scheduled';
    case Applied = 'applied';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return $this === self::Applied || $this === self::Cancelled;
    }

    /**
     * Allowed transitions (Plan §48; Phase 20B). `scheduled` may be applied or cancelled;
     * `applied`/`cancelled` are terminal (immutable history).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Applied, self::Cancelled],
            self::Applied, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Applied => 'Applied',
            self::Cancelled => 'Cancelled',
        };
    }
}
