<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Flagged-event review authority (Plan §10, §13.2, §19; Phase 19). The Audit role owns
 * the review workflow; tenant + branch scoped. Reads use `audit.branch_events.view`;
 * `create`/`updateStatus`/`resolveMetadata` are the canonical Audit write keys. Audit
 * can NEVER mutate a source record — these policies gate review-metadata transitions only.
 */
final class AuditFlaggedEventPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('audit.branch_events.view');
    }

    public function view(User $user, AuditFlaggedEvent $flag): bool
    {
        return $this->context->can('audit.branch_events.view') && $this->sameScope($flag);
    }

    public function create(User $user): bool
    {
        return $this->context->can('audit.flagged_event.create');
    }

    /** Start review + reopen. */
    public function updateStatus(User $user, AuditFlaggedEvent $flag): bool
    {
        return $this->context->can('audit.flagged_event.update_status') && $this->sameScope($flag);
    }

    /** Resolve + dismiss (records a metadata outcome). */
    public function resolveMetadata(User $user, AuditFlaggedEvent $flag): bool
    {
        return $this->context->can('audit.flagged_event.resolve_metadata') && $this->sameScope($flag);
    }

    private function sameScope(AuditFlaggedEvent $flag): bool
    {
        return $flag->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($flag->branch_id);
    }
}
