<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Preferred-personnel fee-rule authority (Plan §13.10, §19.3, §47; Phase 20A).
 *
 * MANAGEMENT is Super-Admin only, platform scope, under `platform.preferred_personnel_fee.manage`
 * (MFA + fresh step-up on mutating routes). VIEWING the effective rule for a branch is a separate,
 * read-only, branch-scoped authority: `preferred_personnel_fee.view_branch_rule` (Branch Manager;
 * no MFA/step-up) — it exposes ONLY the applicable effective rule, never draft/scheduled admin
 * metadata or approval internals.
 */
final class PreferredPersonnelFeeRulePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('platform.preferred_personnel_fee.manage');
    }

    public function view(User $user, PreferredPersonnelFeeRule $rule): bool
    {
        return $this->context->can('platform.preferred_personnel_fee.manage');
    }

    public function manage(User $user): bool
    {
        return $this->context->can('platform.preferred_personnel_fee.manage');
    }

    /** Branch users' read-only view of the effective rule (Branch Manager; branch scope). */
    public function viewBranchRule(User $user): bool
    {
        return $this->context->can('preferred_personnel_fee.view_branch_rule');
    }
}
