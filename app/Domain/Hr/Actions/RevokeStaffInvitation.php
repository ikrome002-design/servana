<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Support\AuditValueMasker;
use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Hr\Exceptions\StaffLifecycleException;
use App\Domain\Hr\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoke a pending staff invitation (Scope §3.4). A revoked invitation can no
 * longer be accepted. Only pending invitations can be revoked. The revocation is
 * audited (Plan §70).
 */
final class RevokeStaffInvitation
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(StaffInvitation $invitation, ?User $actor = null): StaffInvitation
    {
        if (! $invitation->isPending()) {
            throw StaffLifecycleException::invalidTransition('Only a pending invitation can be revoked.');
        }

        return DB::transaction(function () use ($invitation, $actor): StaffInvitation {
            $invitation->status = StaffInvitationStatus::Revoked;
            $invitation->revoked_at = now();
            $invitation->save();

            $this->audit->record(
                AuditEvent::InvitationRevoked,
                $actor,
                $invitation->merchant_id,
                $invitation->branch_id,
                $invitation,
                ['email' => AuditValueMasker::maskEmail($invitation->email), 'role' => $invitation->role->value],
            );

            return $invitation->refresh();
        });
    }
}
