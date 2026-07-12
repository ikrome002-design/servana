<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Canonical billing-escalation event types (Plan §13.15, §54; Phase 20B). Append-only
 * events driving/recording the shared overdue escalation, idempotent per
 * `(merchant_subscription_id, event_type, period_boundary)` (Gate B4). Used
 * consistently across the PHP enum, the PostgreSQL CHECK on
 * `billing_escalation_events.event_type`, factories, and audit context.
 */
enum BillingEscalationEventType: string
{
    case Reminder = 'reminder';
    case GraceEntered = 'grace_entered';
    case Overdue = 'overdue';
    case SuspendedBilling = 'suspended_billing';
    case Recovered = 'recovered';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Reminder => 'Reminder',
            self::GraceEntered => 'Grace entered',
            self::Overdue => 'Overdue',
            self::SuspendedBilling => 'Suspended (billing)',
            self::Recovered => 'Recovered',
        };
    }
}
