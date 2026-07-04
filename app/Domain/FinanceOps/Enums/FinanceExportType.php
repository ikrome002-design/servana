<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Enums;

/**
 * Finance export domain type (Plan §65; Gate I; Phase 18B). Mirrors the
 * finance_exports.export_type DB CHECK — all nine future types are enumerated for
 * forward compatibility, but only {@see currentlySupported()} may be requested in
 * Phase 18B; compensation/payouts/billing are rejected with 422
 * unsupported_export_type until their owning phases (20E–20H / 20A–20B) exist.
 */
enum FinanceExportType: string
{
    case Invoices = 'invoices';
    case Payments = 'payments';
    case Receipts = 'receipts';
    case CashUp = 'cash_up';
    case Refunds = 'refunds';
    case Disputes = 'disputes';
    case Compensation = 'compensation';
    case Payouts = 'payouts';
    case Billing = 'billing';

    /**
     * Types requestable in Phase 18B (Gate I).
     *
     * @return list<self>
     */
    public static function currentlySupported(): array
    {
        return [self::Invoices, self::Payments, self::Receipts, self::CashUp, self::Refunds, self::Disputes];
    }

    public function isCurrentlySupported(): bool
    {
        return in_array($this, self::currentlySupported(), true);
    }

    /** @return list<string> */
    public static function currentlySupportedValues(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::currentlySupported());
    }
}
