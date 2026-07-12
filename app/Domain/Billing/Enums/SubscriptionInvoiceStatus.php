<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Canonical subscription-invoice statuses (Plan §13.9, §25.4, §49; Phase 20B). Used
 * consistently across the PHP enum, the PostgreSQL CHECK on
 * `subscription_invoices.status`, factories, request validation/OpenAPI/TS, frontend
 * options, and audit context. Cancellation terminology is `void` ONLY (never
 * `cancelled`; §25.4).
 *
 * Phase 20B implements the system/action-driven transitions `draft → issued`,
 * `issued/partially_paid → overdue`, and `draft/issued → void`. The payment-driven
 * transitions (`pending_payment`/`partially_paid`/`paid`/`payment_failed`/
 * `reconciliation_required`) are driven EXCLUSIVELY by verified Wallet events in
 * Phase 20D-W and are not implemented in 20B.
 */
enum SubscriptionInvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PendingPayment = 'pending_payment';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case PaymentFailed = 'payment_failed';
    case ReconciliationRequired = 'reconciliation_required';
    case Void = 'void';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** Financial fields are immutable once the invoice leaves `draft`. */
    public function isImmutableFinancial(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Full transition inventory (Plan §25.4; Phase 20B). Phase 20B invokes only the
     * system/action-driven subset (`draft → issued`, `issued/partially_paid → overdue`,
     * `draft/issued → void`); the payment-driven transitions are wired by Phase 20D-W
     * (verified Wallet events only). `void` is terminal.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Issued, self::Void],
            self::Issued => [self::PendingPayment, self::PartiallyPaid, self::Paid, self::Overdue, self::Void, self::ReconciliationRequired],
            self::PendingPayment => [self::PartiallyPaid, self::Paid, self::PaymentFailed, self::Issued, self::ReconciliationRequired],
            self::PartiallyPaid => [self::Paid, self::Overdue, self::ReconciliationRequired],
            self::Overdue => [self::PartiallyPaid, self::Paid, self::ReconciliationRequired],
            self::PaymentFailed => [self::Issued, self::PendingPayment, self::ReconciliationRequired],
            self::ReconciliationRequired => [self::Issued, self::PartiallyPaid, self::Paid],
            self::Paid => [self::ReconciliationRequired],
            self::Void => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::PendingPayment => 'Pending payment',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::PaymentFailed => 'Payment failed',
            self::ReconciliationRequired => 'Reconciliation required',
            self::Void => 'Void',
        };
    }
}
