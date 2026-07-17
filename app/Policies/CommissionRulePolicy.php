<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Commission-rule authority (Plan §59, §10.2; Scope §12.7, §18.3; Phase 20F). **HR only,
 * branch-scoped.**
 *
 * The permission matrix declares NO `commission.rule.*` namespace, so a rule is governed by the
 * same `compensation.plan.*` keys as the plan that references it (F9): create → `plan.create`,
 * edit draft → `plan.update_draft`, read → `plan.view`. There is no independent submit/approve/
 * cancel authority — those transitions are consequences of the referencing plan's lifecycle and are
 * driven inside that plan's transaction, so no policy method exists for them.
 *
 * A rule is ENDED, never deleted (Scope §12.7 Step 3C) — there is no delete ability here either.
 */
final class CommissionRulePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('compensation.plan.view');
    }

    public function view(User $user, CommissionRule $rule): bool
    {
        return $this->context->can('compensation.plan.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('compensation.plan.create');
    }

    /** Only a DRAFT rule is editable in place (F7); active terms supersede, never edit. */
    public function updateDraft(User $user, CommissionRule $rule): bool
    {
        return $this->context->can('compensation.plan.update_draft');
    }
}
