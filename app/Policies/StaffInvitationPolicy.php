<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Invitation authority (Plan §10.2/§10.3, Scope §3.2/§3.4).
 *
 * `create` authorizes the actor to invite AT ALL (Merchant Admin via
 * `branches.manage_users_lifecycle`, HR via `staff.invite`); WHICH target roles
 * and branches each actor may invite is the §3.2/§3.4 boundary enforced in the
 * controller (admin → branch_manager/hr only; HR → operational, same branch).
 * `manage` (resend/revoke) mirrors the staff-lifecycle authority.
 */
final class StaffInvitationPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function create(User $user): bool
    {
        return $this->context->can('staff.invite')
            || $this->context->can('branches.manage_users_lifecycle');
    }

    public function manage(User $user, StaffInvitation $invitation): bool
    {
        if ($invitation->merchant_id !== $this->context->merchantId()) {
            return false;
        }

        if ($this->context->can('branches.manage_users_lifecycle')) {
            return true;
        }

        return $this->context->can('staff.invite')
            && $this->context->canAccessBranch($invitation->branch_id);
    }
}
