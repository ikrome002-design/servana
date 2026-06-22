<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Domain\Branches\Enums\BranchUserAssignmentStatus;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Enums\StaffEmploymentStatus;
use App\Domain\Hr\Enums\StaffHistoryField;
use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Hr\Exceptions\StaffLifecycleException;
use App\Domain\Hr\Models\StaffHistory;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staff lifecycle transitions (Scope §3.4 Suspension/Deactivation, Plan §27
 * Phase 7). Every transition is transactional and records staff_history.
 *
 * Suspend/deactivate enforce the Scope §3.4 revocation rule: existing sessions
 * are invalidated immediately, unused Magic Links are invalidated, login is
 * blocked (membership no longer active → eligibility checks 2/4 deny), and
 * pending invitations for that email in the merchant are revoked. Historical
 * records are preserved (no deletes).
 */
final class StaffLifecycleService
{
    public function __construct(
        private readonly MagicLinkTokenService $tokens,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Activate a staff membership. A branch-scoped role must already hold an
     * active branch assignment (Scope §3.4 / Plan §8.2).
     */
    public function activate(MerchantUser $membership, ?User $actor = null): MerchantUser
    {
        if ($membership->isBranchScoped() && ! $membership->hasActiveBranchAssignment()) {
            throw StaffLifecycleException::branchAssignmentRequired();
        }

        return DB::transaction(function () use ($membership, $actor): MerchantUser {
            $from = $membership->status;
            $membership->status = MerchantUserStatus::Active;
            $membership->activated_at ??= now();
            $membership->save();

            $this->syncProfileActive($membership, true);
            $this->recordStatusHistory($membership, $from, MerchantUserStatus::Active, $actor);
            $this->auditLifecycle(AuditEvent::MembershipActivated, $membership, $from, MerchantUserStatus::Active, $actor);

            return $membership->refresh();
        });
    }

    public function suspend(MerchantUser $membership, ?User $actor = null, ?string $reason = null): MerchantUser
    {
        $this->guardNotSoleAdmin($membership);

        return DB::transaction(function () use ($membership, $actor, $reason): MerchantUser {
            $from = $membership->status;
            $membership->status = MerchantUserStatus::Suspended;
            $membership->suspended_at = now();
            $membership->save();

            $this->syncProfileActive($membership, false);
            $this->revokeAccess($membership);
            $this->recordStatusHistory($membership, $from, MerchantUserStatus::Suspended, $actor, $reason);
            $this->auditLifecycle(AuditEvent::MembershipSuspended, $membership, $from, MerchantUserStatus::Suspended, $actor, $reason);

            return $membership->refresh();
        });
    }

    public function deactivate(MerchantUser $membership, ?User $actor = null, ?string $reason = null): MerchantUser
    {
        $this->guardNotSoleAdmin($membership);

        return DB::transaction(function () use ($membership, $actor, $reason): MerchantUser {
            $from = $membership->status;
            $membership->status = MerchantUserStatus::Deactivated;
            $membership->deactivated_at = now();
            $membership->save();

            // Revoke all branch assignments.
            $membership->branchAssignments()->active()->update([
                'status' => BranchUserAssignmentStatus::Revoked->value,
                'revoked_at' => now(),
            ]);

            $profile = $this->syncProfileActive($membership, false);
            if ($profile !== null) {
                $profile->employment_status = StaffEmploymentStatus::Terminated;
                $profile->save();
            }

            $this->revokeAccess($membership);
            $this->recordStatusHistory($membership, $from, MerchantUserStatus::Deactivated, $actor, $reason);
            $this->auditLifecycle(AuditEvent::MembershipDeactivated, $membership, $from, MerchantUserStatus::Deactivated, $actor, $reason);

            return $membership->refresh();
        });
    }

    /** Assign a membership to a branch (idempotent on the active row), with history. */
    public function assignBranch(MerchantUser $membership, MerchantBranch $branch, ?User $actor = null): BranchUserAssignment
    {
        return DB::transaction(function () use ($membership, $branch, $actor): BranchUserAssignment {
            $assignment = BranchUserAssignment::query()
                ->where('merchant_user_id', $membership->id)
                ->where('branch_id', $branch->id)
                ->where('status', BranchUserAssignmentStatus::Active->value)
                ->first();

            if ($assignment !== null) {
                return $assignment;
            }

            $assignment = BranchUserAssignment::query()->create([
                'merchant_user_id' => $membership->id,
                'branch_id' => $branch->id,
                'status' => BranchUserAssignmentStatus::Active,
                'assigned_by' => $actor?->id,
                'assigned_at' => now(),
            ]);

            $this->recordBranchHistory($membership, null, $branch->id, $actor);

            $this->audit->record(
                AuditEvent::BranchAssignmentGranted,
                $actor,
                $membership->merchant_id,
                $branch->id,
                $assignment,
                ['target_membership' => $membership->ulid, 'target_role' => $membership->role->value],
            );

            return $assignment;
        });
    }

    public function revokeBranchAssignment(BranchUserAssignment $assignment, ?User $actor = null): void
    {
        DB::transaction(function () use ($assignment, $actor): void {
            $assignment->status = BranchUserAssignmentStatus::Revoked;
            $assignment->revoked_at = now();
            $assignment->save();

            $membership = $assignment->merchantUser;
            if ($membership !== null) {
                $this->recordBranchHistory($membership, $assignment->branch_id, null, $actor);

                $this->audit->record(
                    AuditEvent::BranchAssignmentRevoked,
                    $actor,
                    $membership->merchant_id,
                    $assignment->branch_id,
                    $assignment,
                    ['target_membership' => $membership->ulid, 'target_role' => $membership->role->value],
                );
            }
        });
    }

    /**
     * Block suspend/deactivate when it would leave the merchant with no active
     * Merchant Administrator (Plan §27 Phase 7 acceptance).
     */
    private function guardNotSoleAdmin(MerchantUser $membership): void
    {
        if ($membership->role !== MerchantUserRole::MerchantAdmin) {
            return;
        }

        $otherActiveAdmins = MerchantUser::query()
            ->where('merchant_id', $membership->merchant_id)
            ->where('role', MerchantUserRole::MerchantAdmin->value)
            ->where('status', MerchantUserStatus::Active->value)
            ->where('id', '!=', $membership->id)
            ->count();

        if ($otherActiveAdmins === 0) {
            throw StaffLifecycleException::cannotOrphanMerchant();
        }
    }

    /**
     * Scope §3.4 revocation rule: kill sessions + unused Magic Links + pending
     * invitations so the next request fails and no stale link works.
     */
    private function revokeAccess(MerchantUser $membership): void
    {
        $user = $membership->user;

        if ($user !== null) {
            // Delete DB-backed sessions for this user (immediate logout).
            DB::table('sessions')->where('user_id', $user->id)->delete();
            // Invalidate any unconsumed Magic Links.
            $this->tokens->invalidateUnconsumedForEmail($user->email);
        }

        // Revoke any still-pending invitations for this email in the merchant.
        StaffInvitation::query()
            ->where('merchant_id', $membership->merchant_id)
            ->where('email', $user?->email)
            ->pending()
            ->update([
                'status' => StaffInvitationStatus::Revoked->value,
                'revoked_at' => now(),
            ]);
    }

    private function syncProfileActive(MerchantUser $membership, bool $isActive): ?StaffProfile
    {
        $profile = $membership->staffProfile;

        if ($profile !== null) {
            $profile->is_active = $isActive;
            $profile->save();
        }

        return $profile;
    }

    private function recordStatusHistory(
        MerchantUser $membership,
        MerchantUserStatus $from,
        MerchantUserStatus $to,
        ?User $actor,
        ?string $reason = null,
    ): void {
        $profile = $membership->staffProfile;

        if ($profile === null) {
            return;
        }

        StaffHistory::query()->create([
            'staff_profile_id' => $profile->id,
            'field' => StaffHistoryField::Status,
            'old_value' => ['status' => $from->value],
            'new_value' => ['status' => $to->value],
            'changed_by' => $actor?->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Audit a membership/staff lifecycle transition (Plan §70). branch_id is the
     * staff member's primary branch when known, so a branch-scoped Audit user can
     * see lifecycle events for their own branch's staff.
     */
    private function auditLifecycle(
        AuditEvent $event,
        MerchantUser $membership,
        MerchantUserStatus $from,
        MerchantUserStatus $to,
        ?User $actor,
        ?string $reason = null,
    ): void {
        $this->audit->record(
            $event,
            $actor,
            $membership->merchant_id,
            $membership->staffProfile?->primary_branch_id,
            $membership,
            array_filter([
                'target_membership' => $membership->ulid,
                'target_role' => $membership->role->value,
                'old_values' => ['status' => $from->value],
                'new_values' => ['status' => $to->value],
                'reason' => $reason,
            ], static fn ($v): bool => $v !== null),
        );
    }

    private function recordBranchHistory(MerchantUser $membership, ?int $oldBranchId, ?int $newBranchId, ?User $actor): void
    {
        $profile = $membership->staffProfile;

        if ($profile === null) {
            return;
        }

        StaffHistory::query()->create([
            'staff_profile_id' => $profile->id,
            'field' => StaffHistoryField::Branch,
            'old_value' => ['branch_id' => $oldBranchId],
            'new_value' => ['branch_id' => $newBranchId],
            'changed_by' => $actor?->id,
        ]);
    }
}
