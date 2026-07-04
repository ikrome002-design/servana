<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Receipts\Models\Receipt;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Receipt authority (Plan §43; Phase 18B). `receipt.view` reads receipts and issues
 * authorized download links (Front Office + Finance, scoped). `receipt.reissue` is
 * Finance only. Every per-row check enforces same-merchant + branch access
 * (foreign-tenant ULIDs already 404 by scoped binding; same-tenant out-of-branch → 403).
 */
final class ReceiptPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('receipt.view');
    }

    public function view(User $user, Receipt $receipt): bool
    {
        return $this->context->can('receipt.view') && $this->ownsBranch($receipt);
    }

    /** Authorized signed download of the receipt PDF (view authority + Phase 10F boundary). */
    public function download(User $user, Receipt $receipt): bool
    {
        return $this->context->can('receipt.view') && $this->ownsBranch($receipt);
    }

    public function reissue(User $user, Receipt $receipt): bool
    {
        return $this->context->can('receipt.reissue') && $this->ownsBranch($receipt);
    }

    private function ownsBranch(Receipt $receipt): bool
    {
        return $receipt->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($receipt->branch_id);
    }
}
