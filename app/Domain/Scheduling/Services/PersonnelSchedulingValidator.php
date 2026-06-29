<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Branches\Enums\BranchUserAssignmentStatus;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Enums\ServiceStatus;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Exceptions\SchedulingValidationException;
use App\Domain\Scheduling\ValueObjects\SchedulingDecision;
use App\Domain\Scheduling\ValueObjects\TimeInterval;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * THE single reusable personnel scheduling eligibility + availability gate
 * (Plan §80 Phase 15B; Corrections 16, 17).
 *
 * Phase 15B builds and DIRECTLY tests this validator; no appointment/queue/session
 * aggregate exists yet, so no production workflow invokes it here. Binding Phase
 * 16A handoff: every appointment creation, assignment, transfer, and rescheduling
 * action MUST invoke this validator and MUST NOT duplicate eligibility/availability
 * logic; Phase 16A then adds branch-open, branch-calendar, and appointment-conflict
 * validation AROUND this gate.
 *
 * It validates ONLY: interval validity (single business date), merchant scope,
 * branch scope, staff lifecycle, active branch assignment, service status, service
 * scope, active service_personnel_eligibility, and effective availability. It does
 * NOT validate branch operating hours/calendar, appointment overlap, branch-day
 * open state, queue capacity, or session conflicts — those require later-phase
 * aggregates.
 */
final class PersonnelSchedulingValidator
{
    public const CODE_INVALID_WINDOW = 'invalid_schedule_window';

    public const CODE_PERSONNEL_INACTIVE = 'personnel_inactive';

    public const CODE_WRONG_BRANCH = 'personnel_wrong_branch';

    public const CODE_NOT_ELIGIBLE = 'personnel_not_eligible';

    public const CODE_UNAVAILABLE = 'personnel_unavailable';

    public const CODE_SERVICE_INACTIVE = 'service_inactive';

    public function __construct(private readonly AvailabilityResolver $resolver) {}

    /**
     * Validate a proposed assignment and return a typed decision.
     */
    public function validate(
        Merchant $merchant,
        MerchantBranch $branch,
        Service $service,
        StaffProfile $staff,
        CarbonInterface $proposedStart,
        CarbonInterface $proposedEnd,
    ): SchedulingDecision {
        $businessTimezone = (string) config('servana.scheduling.business_timezone', 'Africa/Nairobi');
        $start = CarbonImmutable::parse($proposedStart)->setTimezone($businessTimezone);
        $end = CarbonImmutable::parse($proposedEnd)->setTimezone($businessTimezone);

        // 1. Interval validity: a single business date, start < end, within a day.
        if (! $start->isSameDay($end)) {
            return SchedulingDecision::deny(self::CODE_INVALID_WINDOW, 'The proposed time crosses a business-date boundary.');
        }

        try {
            $interval = TimeInterval::fromStrings($start->format('H:i:s'), $end->format('H:i:s'));
        } catch (InvalidArgumentException) {
            return SchedulingDecision::deny(self::CODE_INVALID_WINDOW, 'The proposed time window is invalid.');
        }

        // 2. Merchant scope (no cross-tenant existence leak — neutral denial).
        if ($service->merchant_id !== $merchant->id || $staff->merchant_id !== $merchant->id) {
            return SchedulingDecision::deny(self::CODE_NOT_ELIGIBLE, 'Personnel is not eligible for this service.');
        }

        // 3. Branch scope.
        if ($service->branch_id !== $branch->id || $staff->primary_branch_id !== $branch->id) {
            return SchedulingDecision::deny(self::CODE_WRONG_BRANCH, 'Personnel is not assigned to this branch.');
        }

        // 4. Staff lifecycle.
        if (! $staff->is_active) {
            return SchedulingDecision::deny(self::CODE_PERSONNEL_INACTIVE, 'Personnel is not active.');
        }

        // 5. Active branch assignment to the target branch.
        $hasActiveAssignment = BranchUserAssignment::query()
            ->where('merchant_user_id', $staff->merchant_user_id)
            ->where('branch_id', $branch->id)
            ->where('status', BranchUserAssignmentStatus::Active->value)
            ->exists();
        if (! $hasActiveAssignment) {
            return SchedulingDecision::deny(self::CODE_WRONG_BRANCH, 'Personnel is not assigned to this branch.');
        }

        // 6. Service status.
        if ($service->status !== ServiceStatus::Active) {
            return SchedulingDecision::deny(self::CODE_SERVICE_INACTIVE, 'This service is not active.');
        }

        // 7. Active service-personnel eligibility.
        $eligible = ServicePersonnelEligibility::query()
            ->where('service_id', $service->id)
            ->where('staff_profile_id', $staff->id)
            ->where('active', true)
            ->exists();
        if (! $eligible) {
            return SchedulingDecision::deny(self::CODE_NOT_ELIGIBLE, 'Personnel is not eligible for this service.');
        }

        // 8. Effective availability for the whole proposed interval.
        if (! $this->resolver->isIntervalAvailable($staff, $start, $interval)) {
            return SchedulingDecision::deny(self::CODE_UNAVAILABLE, 'Personnel is not available for the requested time.');
        }

        return SchedulingDecision::pass();
    }

    /**
     * Validate or throw the canonical 422 envelope (for Phase 16A call sites).
     *
     * @throws SchedulingValidationException
     */
    public function ensure(
        Merchant $merchant,
        MerchantBranch $branch,
        Service $service,
        StaffProfile $staff,
        CarbonInterface $proposedStart,
        CarbonInterface $proposedEnd,
    ): void {
        $decision = $this->validate($merchant, $branch, $service, $staff, $proposedStart, $proposedEnd);

        if (! $decision->allowed) {
            throw SchedulingValidationException::fromDecision($decision);
        }
    }
}
