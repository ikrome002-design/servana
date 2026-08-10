<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\PlatformAccess\Exceptions\PlatformAccessException;
use App\Domain\PlatformAccess\Models\PlatformAccessInvitation;
use App\Domain\PlatformAccess\Support\PlatformAccessInvitationToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resend a pending invitation (COR-UI08-001 §11.6; Phase UI-08).
 *
 * A resend ROTATES THE SECRET. A fresh 64-byte token is minted and `token_hash` replaced, so the
 * previously emailed link stops working immediately — re-sending the same credential would widen
 * the window in which a leaked link stays valid, which is the opposite of what a resend is for.
 *
 * Only a redeemable invitation may be resent; an accepted, revoked or expired one is terminal.
 */
final class ResendPlatformAccessInvitation
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array{invitation:PlatformAccessInvitation,raw_token:string} */
    public function handle(PlatformAccessInvitation $invitation, User $actor): array
    {
        return DB::transaction(function () use ($invitation, $actor): array {
            /** @var PlatformAccessInvitation $locked */
            $locked = PlatformAccessInvitation::query()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isRedeemable()) {
                throw PlatformAccessException::invitationNotRedeemable();
            }

            $token = PlatformAccessInvitationToken::generate();

            $locked->forceFill([
                'token_hash' => $token->hash,
                'expires_at' => now()->addHours(PlatformAccessInvitation::EXPIRY_HOURS),
                'resend_count' => $locked->resend_count + 1,
                'last_sent_at' => now(),
            ])->save();

            $this->audit->record(AuditEvent::PlatformInternalAccessInvitationResent, $actor, null, null, $locked, [
                'invitation_id' => $locked->ulid,
                'email' => PlatformAccessInvitationToken::maskEmail($locked->email),
                'resend_count' => $locked->resend_count,
            ]);

            return ['invitation' => $locked->refresh(), 'raw_token' => $token->raw];
        });
    }
}
