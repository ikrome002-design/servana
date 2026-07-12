<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Canonical structured reasons written to `merchants.billing_status_reason` by the billing-status
 * projection service (Plan §21, §22; Gate B2; Phase 20B). Stored as a text column (Plan §13; no
 * rigid DB CHECK, since later phases add further billing reasons), but represented type-safely here
 * so the cause is never an unstructured ad-hoc string. Gate B2 requires the two terminal reasons to
 * remain distinguishable: `subscription_cancelled` vs `subscription_expired`.
 */
enum MerchantBillingStatusReason: string
{
    case TrialStarted = 'trial_started';
    case Activated = 'subscription_activated';
    case RecoveredPayment = 'billing_recovered';
    case GraceEntered = 'grace_entered';
    case Overdue = 'payment_overdue';
    case SuspendedOverdue = 'suspended_overdue';
    case SubscriptionCancelled = 'subscription_cancelled';
    case SubscriptionExpired = 'subscription_expired';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
