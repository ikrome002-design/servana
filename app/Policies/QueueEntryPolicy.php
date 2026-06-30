<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Queue-entry authority (Plan §10.2/§19, §37; Phase 16B). Front Office owns all
 * operational queue work within its resolved merchant + assigned branch
 * (`queue.view/create/assign/transfer/reorder`). The call/start/complete/cancel/
 * no-show lifecycle actions are authorised through `queue.assign` (the canonical
 * catalogue defines no separate keys). Branch Manager has branch-scoped READ-ONLY
 * visibility via `branch.dashboard.view` and NEVER any operational queue mutation.
 * Personnel own-scope reads are enforced in the dedicated personnel controller.
 */
final class QueueEntryPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('queue.view')
            || $this->context->can('branch.dashboard.view');
    }

    public function view(User $user, QueueEntry $entry): bool
    {
        return ($this->context->can('queue.view') || $this->context->can('branch.dashboard.view'))
            && $this->ownsBranch($entry);
    }

    public function create(User $user): bool
    {
        return $this->context->can('queue.create');
    }

    /** Assign + the call/start/complete/cancel/no-show lifecycle (queue.assign). */
    public function operate(User $user, QueueEntry $entry): bool
    {
        return $this->context->can('queue.assign') && $this->ownsBranch($entry);
    }

    public function transfer(User $user, QueueEntry $entry): bool
    {
        return $this->context->can('queue.transfer') && $this->ownsBranch($entry);
    }

    public function reorder(User $user): bool
    {
        return $this->context->can('queue.reorder');
    }

    /** Recording a preferred-personnel request (Front Office). */
    public function selectPreferred(User $user): bool
    {
        return $this->context->can('preferred_personnel.select');
    }

    private function ownsBranch(QueueEntry $entry): bool
    {
        return $entry->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($entry->branch_id);
    }
}
