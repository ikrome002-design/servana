<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Enums\BranchUserAssignmentStatus;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Hr\Enums\StaffHistoryField;
use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Hr\Exceptions\InvalidStaffInvitationException;
use App\Domain\Hr\Models\StaffHistory;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Accept a staff invitation (Scope §3.4, Plan §27 Phase 7). Atomic:
 *
 *   1 claim the pending, unexpired invitation by token hash (conditional UPDATE)
 *   2 find-or-reuse the user by normalized email (no password — Magic Link only)
 *   3 activate (or create) the merchant_users membership
 *   4 create the staff_profile (is_active = true)
 *   5 create the active branch_user_assignment
 *   6 record initial staff_history rows (status + branch)
 *
 * The whole build runs inside the transaction that claimed the invite, so any
 * failure rolls the claim back. Returns the resulting User.
 *
 * @phpstan-type AcceptInput array{first_name: string, last_name: string, phone: string}
 */
final class AcceptStaffInvitation
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{first_name: string, last_name: string, phone: string}  $profile
     */
    public function handle(string $rawToken, array $profile): User
    {
        $hash = hash('sha256', $rawToken);

        return DB::transaction(function () use ($hash, $profile): User {
            // CLAIM — only one caller can flip a pending, unexpired invitation.
            $claimed = StaffInvitation::query()
                ->where('token_hash', $hash)
                ->where('status', StaffInvitationStatus::Pending->value)
                ->where('expires_at', '>', now())
                ->update([
                    'status' => StaffInvitationStatus::Accepted->value,
                    'accepted_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                throw new InvalidStaffInvitationException;
            }

            /** @var StaffInvitation $invitation */
            $invitation = StaffInvitation::query()->where('token_hash', $hash)->firstOrFail();

            $user = User::query()->firstOrCreate(
                ['email' => $invitation->email],
                [
                    'name' => trim($profile['first_name'].' '.$profile['last_name']),
                    'status' => User::STATUS_ACTIVE,
                ],
            );

            // First acceptance verifies the email (possession of the invite link).
            if ($user->email_verified_at === null) {
                $user->email_verified_at = now();
                $user->save();
            }

            $membership = MerchantUser::query()->updateOrCreate(
                ['merchant_id' => $invitation->merchant_id, 'user_id' => $user->id],
                [
                    'role' => $invitation->role,
                    'status' => MerchantUserStatus::Active,
                    'invited_by' => $invitation->invited_by,
                    'last_branch_id' => $invitation->branch_id,
                    'activated_at' => now(),
                ],
            );

            $staffProfile = StaffProfile::query()->create([
                'merchant_user_id' => $membership->id,
                'merchant_id' => $invitation->merchant_id,
                'primary_branch_id' => $invitation->branch_id,
                'first_name' => $profile['first_name'],
                'last_name' => $profile['last_name'],
                'display_name' => trim($profile['first_name'].' '.$profile['last_name']),
                'phone' => $profile['phone'],
                'role_title' => $invitation->role_title,
                'invited_by' => $invitation->invited_by,
                'is_active' => true,
            ]);

            BranchUserAssignment::query()->create([
                'merchant_user_id' => $membership->id,
                'branch_id' => $invitation->branch_id,
                'status' => BranchUserAssignmentStatus::Active,
                'assigned_by' => $invitation->invited_by,
                'assigned_at' => now(),
            ]);

            // Initial append-only history (Scope §3.4).
            StaffHistory::query()->create([
                'staff_profile_id' => $staffProfile->id,
                'field' => StaffHistoryField::Status,
                'old_value' => null,
                'new_value' => ['status' => MerchantUserStatus::Active->value],
                'changed_by' => $invitation->invited_by,
                'reason' => 'invitation_accepted',
            ]);
            StaffHistory::query()->create([
                'staff_profile_id' => $staffProfile->id,
                'field' => StaffHistoryField::Branch,
                'old_value' => null,
                'new_value' => ['branch_id' => $invitation->branch_id],
                'changed_by' => $invitation->invited_by,
                'reason' => 'invitation_accepted',
            ]);

            // Audit the acceptance + the resulting membership (Plan §70). The
            // invitee is the actor; both rows are branch-scoped to the invite.
            $this->audit->record(
                AuditEvent::InvitationAccepted,
                $user,
                $invitation->merchant_id,
                $invitation->branch_id,
                $invitation,
                ['role' => $invitation->role->value],
            );
            $this->audit->record(
                AuditEvent::MembershipCreated,
                $user,
                $invitation->merchant_id,
                $invitation->branch_id,
                $membership,
                ['target_membership' => $membership->ulid, 'target_role' => $membership->role->value, 'via' => 'invitation'],
            );

            return $user;
        });
    }
}
