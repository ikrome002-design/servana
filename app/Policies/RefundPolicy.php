<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Refunds\Models\Refund;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Refund authority (Plan §44; Phase 18B). `refund.create` (Finance maker) requests +
 * reads; `refund.approve` (a DISTINCT Finance membership) approves/rejects;
 * `refund.finalize` finalizes. The per-transaction actor guard additionally enforces
 * requester != approver != finalizer. Every per-row check enforces same-merchant +
 * branch access (foreign-tenant ULIDs 404; same-tenant out-of-branch 403).
 */
final class RefundPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('refund.create') || $this->context->can('refund.approve');
    }

    public function view(User $user, Refund $refund): bool
    {
        return $this->viewAny($user) && $this->ownsBranch($refund);
    }

    public function create(User $user): bool
    {
        return $this->context->can('refund.create');
    }

    public function approve(User $user, Refund $refund): bool
    {
        return $this->context->can('refund.approve') && $this->ownsBranch($refund);
    }

    public function reject(User $user, Refund $refund): bool
    {
        return $this->context->can('refund.approve') && $this->ownsBranch($refund);
    }

    public function finalize(User $user, Refund $refund): bool
    {
        return $this->context->can('refund.finalize') && $this->ownsBranch($refund);
    }

    private function ownsBranch(Refund $refund): bool
    {
        return $refund->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($refund->branch_id);
    }
}
