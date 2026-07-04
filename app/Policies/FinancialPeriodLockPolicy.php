<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\FinanceOps\Models\FinancialPeriodLock;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Financial period lock authority (Plan §46; ADR-0007; Phase 18B). Finance owns
 * `period_lock.create` and `period_lock.reopen` (execution); the Merchant Administrator
 * owns ONLY `merchant.period_reopen.approve_exception`. `period_lock.reopen ⟂
 * merchant.period_reopen.approve_exception` (registry). Every per-row check enforces
 * same-merchant ownership (foreign-tenant ULIDs 404).
 */
final class FinancialPeriodLockPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('period_lock.create')
            || $this->context->can('period_lock.reopen')
            || $this->context->can('merchant.period_reopen.approve_exception');
    }

    public function view(User $user, FinancialPeriodLock $lock): bool
    {
        return $this->viewAny($user) && $this->ownsMerchant($lock);
    }

    public function create(User $user): bool
    {
        return $this->context->can('period_lock.create');
    }

    public function requestReopen(User $user, FinancialPeriodLock $lock): bool
    {
        return $this->context->can('period_lock.reopen') && $this->ownsMerchant($lock);
    }

    public function execute(User $user, FinancialPeriodLock $lock): bool
    {
        return $this->context->can('period_lock.reopen') && $this->ownsMerchant($lock);
    }

    public function approveException(User $user, FinancialPeriodLock $lock): bool
    {
        return $this->context->can('merchant.period_reopen.approve_exception') && $this->ownsMerchant($lock);
    }

    private function ownsMerchant(FinancialPeriodLock $lock): bool
    {
        return $lock->merchant_id === $this->context->merchantId();
    }
}
