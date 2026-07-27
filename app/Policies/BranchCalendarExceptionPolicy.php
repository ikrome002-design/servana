<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Branches\Models\BranchCalendarException;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Branch calendar-exception authority (REM-SCR-002B; Plan §19.3:1465
 * `branch.calendar.manage  B|-|R|n/a|-|-|warn|-`).
 *
 * Plan §19.3 defines ONE key for the calendar, so `manage` governs both the read and the write —
 * no `branch.calendar.view` key exists and Phase 23 does not invent one. The read route is
 * additionally inside `EnsureBranchScope`, mirroring the sibling `branches.operating-hours.show`.
 *
 * Branch scope is checked on the ROW's `branch_id`, so a same-tenant caller can never reach
 * another branch's exception even if a binding were reused.
 */
final class BranchCalendarExceptionPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user, BranchCalendarException $exception): bool
    {
        return $this->context->canAccessBranch($exception->branch_id)
            && $this->context->can('branch.calendar.manage');
    }

    public function manage(User $user, BranchCalendarException $exception): bool
    {
        return $this->context->canAccessBranch($exception->branch_id)
            && $this->context->can('branch.calendar.manage');
    }
}
