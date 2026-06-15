<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Day-record authority (Plan §10.3 `day.open_close`). Object-level companion to
 * MerchantBranchPolicy::manageDay; gates by branch scope + the day capability.
 */
final class BranchDayRecordPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user, BranchDayRecord $record): bool
    {
        return $this->context->canAccessBranch($record->branch_id);
    }

    public function manage(User $user, BranchDayRecord $record): bool
    {
        return $this->context->canAccessBranch($record->branch_id)
            && $this->context->can('day.open_close');
    }
}
