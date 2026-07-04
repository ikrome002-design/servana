<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\FinanceOps\Models\FinanceDispute;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Finance dispute authority (Plan §44; Phase 18B). Finance-only via
 * `finance_dispute.manage`; tenant + branch scoped. Every operation (view, create,
 * start-review, resolve, reject) requires the same key — the dispute lifecycle is a
 * single Finance responsibility.
 */
final class FinanceDisputePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('finance_dispute.manage');
    }

    public function view(User $user, FinanceDispute $dispute): bool
    {
        return $this->manage($dispute);
    }

    public function create(User $user): bool
    {
        return $this->context->can('finance_dispute.manage');
    }

    public function transition(User $user, FinanceDispute $dispute): bool
    {
        return $this->manage($dispute);
    }

    private function manage(FinanceDispute $dispute): bool
    {
        return $this->context->can('finance_dispute.manage')
            && $dispute->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($dispute->branch_id);
    }
}
