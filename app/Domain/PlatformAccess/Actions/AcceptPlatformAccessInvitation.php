<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Actions;

use App\Domain\PlatformAccess\Enums\PlatformAccessInvitationStatus;
use App\Domain\PlatformAccess\Enums\PlatformAccessStatus;
use App\Domain\PlatformAccess\Exceptions\PlatformAccessException;
use App\Domain\PlatformAccess\Models\PlatformAccessInvitation;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Domain\PlatformAccess\Support\PlatformAccessInvitationToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Consume a platform-access invitation (COR-UI08-001 §11.6; Phase UI-08).
 *
 * SINGLE-USE AND ATOMIC. The row is taken with `SELECT … FOR UPDATE` and the status advanced with a
 * CONDITIONAL update, so two concurrent redemptions cannot both win — the loser sees a
 * non-redeemable invitation, not a second grant.
 *
 * ACCEPTANCE GRANTS PLATFORM ACCESS AND NOTHING ELSE. It writes the membership and the derived
 * `users.is_platform_staff` mirror. It writes NO `merchant_users`, `branch_user_assignments` or
 * `staff_profiles` row and assigns no merchant role. Authentication remains Magic Link only: this
 * action creates no password, OTP or WebAuthn credential.
 *
 * Environment binding is re-checked here, not merely at issue time, so a credential minted for one
 * environment cannot be replayed into another.
 */
final class AcceptPlatformAccessInvitation
{
    public function handle(string $rawToken, User $user, string $environment): PlatformAccessMembership
    {
        return DB::transaction(function () use ($rawToken, $user, $environment): PlatformAccessMembership {
            $invitation = PlatformAccessInvitation::query()
                ->where('token_hash', PlatformAccessInvitationToken::hash($rawToken))
                ->lockForUpdate()
                ->first();

            if ($invitation === null
                || ! $invitation->isRedeemable()
                || $invitation->environment !== $environment
                || $invitation->purpose !== PlatformAccessInvitation::PURPOSE) {
                throw PlatformAccessException::invitationNotRedeemable();
            }

            // Conditional single-use consume: only a still-pending, still-unexpired row moves.
            $consumed = PlatformAccessInvitation::query()
                ->whereKey($invitation->getKey())
                ->where('status', PlatformAccessInvitationStatus::Pending->value)
                ->where('expires_at', '>', now())
                ->update([
                    'status' => PlatformAccessInvitationStatus::Accepted->value,
                    'accepted_at' => now(),
                    'accepted_user_id' => $user->id,
                    'updated_at' => now(),
                ]);

            if ($consumed !== 1) {
                throw PlatformAccessException::invitationNotRedeemable();
            }

            $membership = PlatformAccessMembership::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'role_key' => PlatformAccessMembership::ROLE_SUPER_ADMIN,
                    'status' => PlatformAccessStatus::Active,
                    'invitation_id' => $invitation->id,
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                    'invited_at' => $invitation->created_at,
                    'activated_at' => now(),
                    'suspended_at' => null,
                    'last_action' => 'activated',
                    'last_action_reason' => 'Invitation accepted.',
                    'last_action_at' => now(),
                ],
            );

            // The derived mirror, in the same transaction.
            $user->forceFill(['is_platform_staff' => true])->save();

            return $membership->refresh();
        });
    }
}
