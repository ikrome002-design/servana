<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Hr\Exceptions\StaffLifecycleException;
use App\Domain\Hr\Models\StaffInvitation;

/**
 * Revoke a pending staff invitation (Scope §3.4). A revoked invitation can no
 * longer be accepted. Only pending invitations can be revoked.
 */
final class RevokeStaffInvitation
{
    public function handle(StaffInvitation $invitation): StaffInvitation
    {
        if (! $invitation->isPending()) {
            throw StaffLifecycleException::invalidTransition('Only a pending invitation can be revoked.');
        }

        $invitation->status = StaffInvitationStatus::Revoked;
        $invitation->revoked_at = now();
        $invitation->save();

        return $invitation->refresh();
    }
}
