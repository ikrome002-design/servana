<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\Exceptions\StaffLifecycleException;
use App\Domain\Hr\Models\StaffInvitation;

/**
 * Resend a pending staff invitation (Scope §3.4). Rotates the token, extends
 * expiry, increments resend_count, and re-emails. Only pending invitations can
 * be resent — accepted/revoked/expired ones cannot.
 */
final class ResendStaffInvitation
{
    public function __construct(private readonly CreateStaffInvitation $creator) {}

    public function handle(StaffInvitation $invitation): StaffInvitation
    {
        if (! $invitation->isPending() || $invitation->isExpired()) {
            throw StaffLifecycleException::invalidTransition('Only a pending invitation can be resent.');
        }

        return $this->creator->rotateAndSend($invitation);
    }
}
