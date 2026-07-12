<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Canonical merchant billing-access statuses (Plan §13, §21, §22, §25.2; Phase 20B).
 * `merchants.billing_status` is the SOLE request-authorization billing-access
 * authority — the billing-status gate reads only this field, never
 * `merchant_subscriptions.status`. It is projected transactionally from the active
 * subscription (§22, {@see MerchantSubscriptionStatus}).
 *
 * There are exactly FIVE values. This enum deliberately has NO `cancelled`/`expired`
 * value: terminal subscription records project to {@see self::SuspendedBilling}
 * (Gate B2), with the distinct cause recorded in `merchants.billing_status_reason`.
 * Distinct from `merchants.status` (operational/governance); a billing transition
 * never clears a fraud/security/legal/compliance/manual/deactivation suspension.
 */
enum MerchantBillingStatus: string
{
    case Trialing = 'trialing';
    case ReadOnlyGrace = 'read_only_grace';
    case Active = 'active';
    case Overdue = 'overdue';
    case SuspendedBilling = 'suspended_billing';

    /**
     * All backing values, in canonical order — the authoritative list for the DB
     * CHECK and every parity assertion.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /**
     * Billing states in which merchant mutations and NEW export/report/PDF
     * generation are blocked while authorized reads and existing-file downloads
     * continue (Plan §22, §9.4 step 9).
     */
    public function blocksMutations(): bool
    {
        return $this === self::ReadOnlyGrace || $this === self::SuspendedBilling;
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Trialing',
            self::ReadOnlyGrace => 'Read-only grace',
            self::Active => 'Active',
            self::Overdue => 'Overdue',
            self::SuspendedBilling => 'Suspended (billing)',
        };
    }
}
