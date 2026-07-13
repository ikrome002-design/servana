<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Percentage platform-fee adjustment type (Plan §13.10; Phase 20E). Records the cause of
 * an additive correction to a ledger entry. Mirrors the PostgreSQL CHECK on
 * `platform_fee_adjustments.adjustment_type`. Parity guarded by `Phase20EEnumParityTest`.
 */
enum PlatformFeeAdjustmentType: string
{
    case Reversal = 'reversal';
    case PartialRefund = 'partial_refund';
    case Correction = 'correction';
    case DisputeResolution = 'dispute_resolution';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Reversal => 'Reversal',
            self::PartialRefund => 'Partial refund',
            self::Correction => 'Correction',
            self::DisputeResolution => 'Dispute resolution',
        };
    }
}
