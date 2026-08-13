<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Truthful HR presentation read over shipped branch-owned facts.
 *
 * This service creates no business state, computes no earnings and never leaves
 * the acting HR membership's assigned branch.
 */
final class HrWorkspaceReadModel
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        $branch = $this->branch();
        $activeStaff = StaffProfile::query()
            ->where('primary_branch_id', $branch->id)
            ->where('is_active', true);
        $activeStaffCount = (clone $activeStaff)->count();

        $eligibleStaffCount = ServicePersonnelEligibility::query()
            ->where('branch_id', $branch->id)
            ->where('active', true)
            ->whereIn('staff_profile_id', (clone $activeStaff)->select('id'))
            ->distinct('staff_profile_id')
            ->count('staff_profile_id');
        $availableStaffCount = PersonnelAvailability::query()
            ->where('branch_id', $branch->id)
            ->where('available', true)
            ->whereIn('staff_profile_id', (clone $activeStaff)->select('id'))
            ->distinct('staff_profile_id')
            ->count('staff_profile_id');
        $configuredStaffCount = PersonnelCompensationPlan::query()
            ->where('branch_id', $branch->id)
            ->whereIn('status', CompensationPlanStatus::overlapGuardedValues())
            ->whereIn('staff_profile_id', (clone $activeStaff)->select('id'))
            ->distinct('staff_profile_id')
            ->count('staff_profile_id');

        $pendingInvitations = StaffInvitation::query()
            ->where('branch_id', $branch->id)
            ->where('status', StaffInvitationStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->count();
        $draftPlans = PersonnelCompensationPlan::query()
            ->where('branch_id', $branch->id)
            ->where('status', CompensationPlanStatus::Draft->value)
            ->count();

        return [
            'branch' => [
                'id' => $branch->ulid,
                'name' => $branch->name,
                'code' => $branch->code,
                'town' => $branch->town,
            ],
            'staff' => [
                'total' => StaffProfile::query()->where('primary_branch_id', $branch->id)->count(),
                'active' => $activeStaffCount,
                'by_access_status' => $this->membershipStatusCounts($branch),
                'pending_invitations' => $pendingInvitations,
            ],
            'readiness' => [
                'eligible_staff' => $eligibleStaffCount,
                'without_eligibility' => max(0, $activeStaffCount - $eligibleStaffCount),
                'available_staff' => $availableStaffCount,
                'without_availability' => max(0, $activeStaffCount - $availableStaffCount),
                'configured_compensation' => $configuredStaffCount,
                'without_compensation' => max(0, $activeStaffCount - $configuredStaffCount),
            ],
            'compensation' => [
                'by_status' => $this->statusCounts(
                    PersonnelCompensationPlan::query()->where('branch_id', $branch->id),
                ),
                'drafts_requiring_action' => $draftPlans,
            ],
            'payouts' => [
                'by_status' => $this->statusCounts(
                    PersonnelPayoutRun::query()->where('branch_id', $branch->id),
                ),
                'awaiting_finance' => PersonnelPayoutRun::query()
                    ->where('branch_id', $branch->id)
                    ->where('status', PayoutRunStatus::Submitted->value)
                    ->count(),
            ],
            'tasks' => [
                ['key' => 'pending-invitations', 'label' => 'Pending staff invitations', 'count' => $pendingInvitations, 'route_name' => 'hr.staff-invite'],
                ['key' => 'eligibility-gaps', 'label' => 'Active staff without service eligibility', 'count' => max(0, $activeStaffCount - $eligibleStaffCount), 'route_name' => 'hr.eligibility'],
                ['key' => 'availability-gaps', 'label' => 'Active staff without availability', 'count' => max(0, $activeStaffCount - $availableStaffCount), 'route_name' => 'hr.availability'],
                ['key' => 'compensation-gaps', 'label' => 'Active staff without active or scheduled terms', 'count' => max(0, $activeStaffCount - $configuredStaffCount), 'route_name' => 'hr.compensation'],
                ['key' => 'draft-plans', 'label' => 'Draft compensation plans', 'count' => $draftPlans, 'route_name' => 'hr.compensation'],
            ],
            'get_started' => [
                'staff_invited' => $pendingInvitations > 0 || $activeStaffCount > 0,
                'eligibility_configured' => $eligibleStaffCount > 0,
                'availability_configured' => $availableStaffCount > 0,
                'compensation_configured' => $configuredStaffCount > 0,
                'missing_compensation_reviewed' => $activeStaffCount > 0 && $configuredStaffCount === $activeStaffCount,
            ],
            'reports' => [
                'available' => false,
                'reason' => 'Phase 21N reporting runtime is blocked by External Gate W',
            ],
            'notifications' => [
                'available' => false,
                'reason' => 'Phase 21N notification runtime is blocked by External Gate W',
            ],
        ];
    }

    public function branch(): MerchantBranch
    {
        $branchId = $this->context->branchIds()[0] ?? null;
        abort_if($branchId === null, 403);

        return MerchantBranch::query()
            ->where('merchant_id', $this->context->merchantId())
            ->findOrFail($branchId);
    }

    /** @return array<string, int> */
    private function membershipStatusCounts(MerchantBranch $branch): array
    {
        $counts = [];
        foreach (MerchantUserStatus::cases() as $status) {
            $counts[$status->value] = StaffProfile::query()
                ->where('primary_branch_id', $branch->id)
                ->whereHas('merchantUser', static fn (Builder $query): Builder => $query->where('status', $status->value))
                ->count();
        }

        return $counts;
    }

    /**
     * @param Builder<*> $query
     * @return array<string, int>
     */
    private function statusCounts(Builder $query): array
    {
        /** @var array<string, int> $counts */
        $counts = $query
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
        ksort($counts);

        return $counts;
    }
}
