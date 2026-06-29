<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Appointment authority (Plan §10.2/§19.3, §36; Phase 16A). Front Office owns all
 * appointment operations within its resolved merchant + assigned branch
 * (`appointment.*`). Branch Manager has branch-scoped READ-ONLY visibility via the
 * existing `branch.dashboard.view` and never any `appointment.*` mutation. No-show
 * is authorised through `appointment.cancel` (no separate key). Cross-merchant rows
 * are 404'd upstream; same-merchant + branch scope is re-checked here. Personnel
 * own-scope reads are enforced in the dedicated personnel controller, not here.
 */
final class AppointmentPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    /** Front Office (appointment.view) or Branch Manager (branch.dashboard.view) may list. */
    public function viewAny(User $user): bool
    {
        return $this->context->can('appointment.view')
            || $this->context->can('branch.dashboard.view');
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return ($this->context->can('appointment.view') || $this->context->can('branch.dashboard.view'))
            && $this->ownsBranch($appointment);
    }

    public function create(User $user): bool
    {
        return $this->context->can('appointment.create');
    }

    public function assign(User $user, Appointment $appointment): bool
    {
        return $this->context->can('appointment.assign') && $this->ownsBranch($appointment);
    }

    public function transfer(User $user, Appointment $appointment): bool
    {
        return $this->context->can('appointment.transfer') && $this->ownsBranch($appointment);
    }

    public function reschedule(User $user, Appointment $appointment): bool
    {
        return $this->context->can('appointment.reschedule') && $this->ownsBranch($appointment);
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $this->context->can('appointment.cancel') && $this->ownsBranch($appointment);
    }

    public function checkIn(User $user, Appointment $appointment): bool
    {
        return $this->context->can('appointment.check_in') && $this->ownsBranch($appointment);
    }

    /** No-show is authorised through the Front Office cancel authority (no separate key). */
    public function markNoShow(User $user, Appointment $appointment): bool
    {
        return $this->context->can('appointment.cancel') && $this->ownsBranch($appointment);
    }

    private function ownsBranch(Appointment $appointment): bool
    {
        return $appointment->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($appointment->branch_id);
    }
}
