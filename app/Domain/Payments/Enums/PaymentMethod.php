<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

/**
 * Merchant-client payment methods (Plan §13.8, §41; Phase 18A). Mirrors the
 * payment_records.method DB CHECK.
 *
 * Gate B: `split_payment` exists in the enum for canonical schema fidelity but is
 * NEVER written as a component method — a split is represented by the group with
 * multiple concrete components. {@see isConcreteComponentMethod()} rejects it.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case MpesaOffline = 'mpesa_offline';
    case BankTransfer = 'bank_transfer';
    case CardTerminal = 'card_terminal';
    case Voucher = 'voucher';
    case SplitPayment = 'split_payment';
    case Other = 'other';

    /** Concrete methods a component may legitimately use in Phase 18A (Gate B: not split_payment). */
    public function isConcreteComponentMethod(): bool
    {
        return $this !== self::SplitPayment;
    }

    /** Whether this method requires an evidence reference (§41). Cash is optional. */
    public function requiresReference(): bool
    {
        return match ($this) {
            self::MpesaOffline, self::BankTransfer, self::CardTerminal, self::Voucher, self::Other => true,
            self::Cash, self::SplitPayment => false,
        };
    }

    /** Whether a durable duplicate-reference check runs for this method (§41). Cash does not. */
    public function runsDuplicateCheck(): bool
    {
        return $this->requiresReference();
    }

    /**
     * Concrete component methods, for factories/tests/validation allowlists.
     *
     * @return list<self>
     */
    public static function concreteMethods(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $m): bool => $m->isConcreteComponentMethod(),
        ));
    }
}
