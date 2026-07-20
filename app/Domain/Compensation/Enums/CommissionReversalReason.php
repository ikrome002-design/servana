<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Why a commission entry was reversed or adjusted (Plan §61; Phase 20G). Mirrors the
 * commission_ledger.reversal_reason DB CHECK; parity guarded by Phase20GEnumParityTest.
 */
enum CommissionReversalReason: string
{
    case InvoiceVoided = 'invoice_voided';
    case PaymentReversed = 'payment_reversed';
    case RefundFinalized = 'refund_finalized';
    case ManualAdjustment = 'manual_adjustment';
    case Correction = 'correction';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
