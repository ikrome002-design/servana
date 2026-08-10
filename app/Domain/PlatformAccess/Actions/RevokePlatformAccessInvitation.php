<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\PlatformAccess\Enums\PlatformAccessInvitationStatus;
use App\Domain\PlatformAccess\Exceptions\PlatformAccessException;
use App\Domain\PlatformAccess\Models\PlatformAccessInvitation;
use App\Domain\PlatformAccess\Support\PlatformAccessInvitationToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Withdraw a pending invitation (COR-UI08-001 §11.6; Phase UI-08).
 *
 * Terminal: there is no un-revoke. Re-admitting the person is a NEW invitation with a new token and
 * a new audit trail, which keeps the history honest about how many times access was offered.
 */
final class RevokePlatformAccessInvitation
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(PlatformAccessInvitation $invitation, string $reason, User $actor): PlatformAccessInvitation
    {
        return DB::transaction(function () use ($invitation, $reason, $actor): PlatformAccessInvitation {
            /** @var PlatformAccessInvitation $locked */
            $locked = PlatformAccessInvitation::query()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== PlatformAccessInvitationStatus::Pending) {
                throw PlatformAccessException::invitationNotRedeemable();
            }

            $locked->forceFill([
                'status' => PlatformAccessInvitationStatus::Revoked,
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->id,
                'revocation_reason' => $reason,
            ])->save();

            $this->audit->record(AuditEvent::PlatformInternalAccessInvitationRevoked, $actor, null, null, $locked, [
                'invitation_id' => $locked->ulid,
                'email' => PlatformAccessInvitationToken::maskEmail($locked->email),
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }
}
