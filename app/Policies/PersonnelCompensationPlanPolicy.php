<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Compensation-plan authority (Plan §59, §10.2, §19.3; Phase 20F). **HR only, branch-scoped.**
 *
 * Plan §10.2 is explicit that the Merchant Administrator never configures services, pricing,
 * COMMISSIONS, or personnel assignment, so Merchant Admin, Branch Manager, Finance, Front Office,
 * Personnel and Audit hold NO compensation-configuration key and are denied here by construction —
 * `TenantContext::can()` is false for every role but HR. Audit reads the domain through the masked
 * `audit.compensation.view`; the Merchant Administrator's summary is Phase 20H. The Super
 * Administrator is platform-governance only and holds no merchant operational key.
 *
 * Tenant/branch isolation is enforced by BelongsToMerchant + BranchScope and tenant-safe route
 * binding (a foreign ULID 404s before reaching a policy). Defence-in-depth alongside the route's
 * `EnsurePermission`; maker/checker and fresh step-up are enforced by the route middleware + action.
 */
final class PersonnelCompensationPlanPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('compensation.plan.view');
    }

    public function view(User $user, PersonnelCompensationPlan $plan): bool
    {
        return $this->context->can('compensation.plan.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('compensation.plan.create');
    }

    /** Only a DRAFT is editable in place (F7); the state machine + DB trigger are authoritative. */
    public function updateDraft(User $user, PersonnelCompensationPlan $plan): bool
    {
        return $this->context->can('compensation.plan.update_draft');
    }

    public function submit(User $user, PersonnelCompensationPlan $plan): bool
    {
        return $this->context->can('compensation.plan.submit');
    }

    /** Maker/checker (approver ≠ submitter) is enforced by the action + a DB CHECK, not here. */
    public function approve(User $user, PersonnelCompensationPlan $plan): bool
    {
        return $this->context->can('compensation.plan.approve');
    }

    public function reject(User $user, PersonnelCompensationPlan $plan): bool
    {
        return $this->context->can('compensation.plan.reject');
    }

    public function cancel(User $user, PersonnelCompensationPlan $plan): bool
    {
        return $this->context->can('compensation.plan.cancel');
    }

    /** Compensation history is read with its own canonical key (successor of `commissions.view`). */
    public function viewHistory(User $user, PersonnelCompensationPlan $plan): bool
    {
        return $this->context->can('compensation.history.view');
    }
}
