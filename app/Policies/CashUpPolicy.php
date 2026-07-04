<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Cash-up authority (Plan §45; ADR-0007; Phase 18B). Maker = Branch Manager
 * (`branch.cash_up.submit`: draft/update/submit/resubmit); checker = Finance
 * (`cash_up.view`/`cash_up.approve`/`cash_up.reject`/`cash_up.request_correction`:
 * review/approve/reject/correct/lock). `branch.cash_up.submit ⟂ cash_up.approve`
 * (registry). Merchant Admin / Front Office / HR / Personnel / Audit / Super Admin
 * hold no cash-up mutation authority. Every per-row check enforces same-merchant +
 * branch access (foreign-tenant ULIDs 404; same-tenant out-of-branch 403).
 */
final class CashUpPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('cash_up.view') || $this->context->can('branch.cash_up.submit');
    }

    public function view(User $user, BranchCashUp $cashUp): bool
    {
        return $this->viewAny($user) && $this->ownsBranch($cashUp);
    }

    public function submit(User $user, BranchCashUp $cashUp): bool
    {
        return $this->context->can('branch.cash_up.submit') && $this->ownsBranch($cashUp);
    }

    public function resubmit(User $user, BranchCashUp $cashUp): bool
    {
        return $this->submit($user, $cashUp);
    }

    public function approve(User $user, BranchCashUp $cashUp): bool
    {
        return $this->context->can('cash_up.approve') && $this->ownsBranch($cashUp);
    }

    public function lock(User $user, BranchCashUp $cashUp): bool
    {
        return $this->context->can('cash_up.approve') && $this->ownsBranch($cashUp);
    }

    public function reject(User $user, BranchCashUp $cashUp): bool
    {
        return $this->context->can('cash_up.reject') && $this->ownsBranch($cashUp);
    }

    public function requestCorrection(User $user, BranchCashUp $cashUp): bool
    {
        return $this->context->can('cash_up.request_correction') && $this->ownsBranch($cashUp);
    }

    private function ownsBranch(BranchCashUp $cashUp): bool
    {
        return $cashUp->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($cashUp->branch_id);
    }
}
