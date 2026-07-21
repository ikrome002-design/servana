<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Lifecycle status of a personnel_payout_items row (Plan §62; Phase 20H). Mirrors the
 * personnel_payout_items.status DB CHECK and **always equals the parent run status** — an item
 * never transitions independently; the payout-run action sets every item to the run's status inside
 * the same transaction. Enum parity guarded by Phase20HEnumParityTest; the mirror invariant is
 * guarded by PayoutItemFreezeTest.
 */
enum PayoutItemStatus: string
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

    /** The item status that mirrors a given run status (one-to-one by value). */
    public static function mirror(PayoutRunStatus $runStatus): self
    {
        return self::from($runStatus->value);
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }
}
