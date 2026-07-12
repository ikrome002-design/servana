<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Canonical subscription-invoice line-item types (Plan §13.9, §49; Phase 20B). Used across the
 * PHP enum, the PostgreSQL CHECK on `subscription_invoice_items.type`, factories, and audit
 * context. Phase 20B fixed mode issues only `plan_fee`; `platform_fee_rollup` (20E),
 * `sms_rollup` (21S), and `adjustment` exist in the vocabulary for forward compatibility but
 * are not created in 20B.
 */
enum SubscriptionInvoiceItemType: string
{
    case PlanFee = 'plan_fee';
    case PlatformFeeRollup = 'platform_fee_rollup';
    case SmsRollup = 'sms_rollup';
    case Adjustment = 'adjustment';

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
            self::PlanFee => 'Plan fee',
            self::PlatformFeeRollup => 'Platform fee',
            self::SmsRollup => 'SMS',
            self::Adjustment => 'Adjustment',
        };
    }
}
