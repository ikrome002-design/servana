<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Lifecycle status of a personnel_payout_runs row (Plan §62; §25.4/§25.5; Phase 20H). Mirrors the
 * personnel_payout_runs.status DB CHECK; parity guarded by Phase20HEnumParityTest.
 *
 * HR creates/edits a `draft` and `submitted`s it (freeze). Finance `finance_verified`s it, then
 * `approve_standard`s an ordinary run to `approved` OR routes a high-value run to
 * `pending_merchant_admin_approval` for Merchant-Admin approval. `approved → paid` at mark-paid
 * (terminal). Pre-paid runs may be `rejected` (Finance) or `cancelled` (HR, draft only).
 * Corrections after `paid` happen only via a new adjustment run — never a status rewind.
 */
enum PayoutRunStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case FinanceVerified = 'finance_verified';
    case PendingMerchantAdminApproval = 'pending_merchant_admin_approval';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** A draft may still be edited/re-snapshotted; every other status is frozen. */
    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Paid, self::Rejected, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Authoritative status transitions (docs/architecture/state-machines/personnel-payout-run.md).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Cancelled],
            self::Submitted => [self::FinanceVerified, self::Rejected],
            self::FinanceVerified => [self::Approved, self::PendingMerchantAdminApproval, self::Rejected],
            self::PendingMerchantAdminApproval => [self::Approved, self::Rejected],
            self::Approved => [self::Paid],
            self::Paid, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
