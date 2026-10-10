<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scheduling;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Actions\EmergencyUnavailable;
use App\Domain\Scheduling\Actions\ReplaceAvailability;
use App\Domain\Scheduling\Services\PersonnelAvailabilityReadModel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\EmergencyUnavailableRequest;
use App\Http\Requests\Scheduling\UpdateAvailabilityRequest;
use App\Http\Resources\PersonnelAvailabilityScheduleResource;
use App\Models\User;
use App\Policies\PersonnelAvailabilityPolicy;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Personnel availability, nested under a staff profile (Plan §80 Phase 15B).
 *
 * HR owns mutation (`personnel.availability.manage`, route-gated + policy); the
 * Branch Manager has read-only visibility (`branch.dashboard.view`, policy). The
 * `{staff}` binding resolves StaffProfile within tenant + branch scope (foreign
 * tenant → 404; same-tenant out-of-branch → 404 via BranchScope). Schedule
 * replacement is atomic. The legacy `availability.manage` key is reconciled to the
 * canonical `personnel.availability.manage` in this phase.
 */
final class StaffAvailabilityController extends Controller
{
    public function __construct(
        private readonly PersonnelAvailabilityReadModel $readModel,
    ) {}

    public function show(StaffProfile $staff): PersonnelAvailabilityScheduleResource
    {
        $this->authorizeView($staff);

        return PersonnelAvailabilityScheduleResource::make($this->schedule($staff));
    }

    public function update(UpdateAvailabilityRequest $request, StaffProfile $staff, ReplaceAvailability $action): PersonnelAvailabilityScheduleResource
    {
        $this->authorizeManage($staff);

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        /** @var User $actor */
        $actor = $request->user();

        $action->handle(
            $staff,
            $this->arrayOf($validated, 'recurring'),
            $this->arrayOf($validated, 'exceptions'),
            (string) $validated['change_reason'],
            $actor,
        );

        return PersonnelAvailabilityScheduleResource::make($this->schedule($staff->refresh()));
    }

    public function emergencyUnavailable(EmergencyUnavailableRequest $request, StaffProfile $staff, EmergencyUnavailable $action): PersonnelAvailabilityScheduleResource
    {
        $this->authorizeManage($staff);

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        /** @var User $actor */
        $actor = $request->user();

        $action->handle(
            $staff,
            (string) $validated['date'],
            (string) $validated['start_time'],
            (string) $validated['end_time'],
            (string) $validated['change_reason'],
            $actor,
        );

        return PersonnelAvailabilityScheduleResource::make($this->schedule($staff->refresh()));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    private function arrayOf(array $validated, string $key): array
    {
        /** @var array<int, array<string, mixed>> $value */
        $value = $validated[$key] ?? [];

        return $value;
    }

    /** @return array<string, mixed> */
    private function schedule(StaffProfile $staff): array
    {
        return $this->readModel->forStaff(
            $staff,
            app(PersonnelAvailabilityPolicy::class)->manage($this->actor(), $staff),
        );
    }

    private function authorizeView(StaffProfile $staff): void
    {
        if (! app(PersonnelAvailabilityPolicy::class)->view($this->actor(), $staff)) {
            throw new AuthorizationException;
        }
    }

    private function authorizeManage(StaffProfile $staff): void
    {
        if (! app(PersonnelAvailabilityPolicy::class)->manage($this->actor(), $staff)) {
            throw new AuthorizationException;
        }
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
