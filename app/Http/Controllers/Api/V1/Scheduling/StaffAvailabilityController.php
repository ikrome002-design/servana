<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scheduling;

use App\Domain\Catalogue\Enums\ServiceStatus;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Actions\EmergencyUnavailable;
use App\Domain\Scheduling\Actions\ReplaceAvailability;
use App\Domain\Scheduling\Enums\AvailabilityType;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Scheduling\Services\AvailabilityResolver;
use App\Domain\Scheduling\Services\PersonnelStateProjector;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\EmergencyUnavailableRequest;
use App\Http\Requests\Scheduling\UpdateAvailabilityRequest;
use App\Http\Resources\PersonnelAvailabilityScheduleResource;
use App\Models\User;
use App\Policies\PersonnelAvailabilityPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

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
        private readonly AvailabilityResolver $resolver,
        private readonly PersonnelStateProjector $stateProjector,
    ) {}

    public function show(StaffProfile $staff): PersonnelAvailabilityScheduleResource
    {
        $this->authorizeView($staff);

        return PersonnelAvailabilityScheduleResource::make($this->assemble($staff));
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

        return PersonnelAvailabilityScheduleResource::make($this->assemble($staff->refresh()));
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

        return PersonnelAvailabilityScheduleResource::make($this->assemble($staff->refresh()));
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

    /**
     * Assemble the safe composite schedule payload (one rows query → no N+1).
     *
     * @return array<string, mixed>
     */
    private function assemble(StaffProfile $staff): array
    {
        $rows = $this->resolver->rowsFor($staff);

        return [
            'staff' => [
                'id' => $staff->ulid,
                'display_name' => $staff->display_name,
                'employment_status' => $staff->employment_status->value,
                'is_active' => $staff->is_active,
            ],
            'timezone' => (string) config('servana.scheduling.business_timezone', 'Africa/Nairobi'),
            // Live state overlays `busy` (an in-progress service session) onto the
            // schedule-derived state (Phase 16C; derived, never stored).
            'current_state' => $this->stateProjector->currentState($staff, null, $rows)->value,
            'recurring' => $this->rowsToArray($rows->filter(fn (PersonnelAvailability $r) => $r->type === AvailabilityType::Recurring), 'recurring'),
            'exceptions' => $this->rowsToArray($rows->filter(fn (PersonnelAvailability $r) => $r->type === AvailabilityType::Exception), 'exception'),
            'eligible_services' => $this->eligibleServices($staff),
            'can' => [
                'update' => app(PersonnelAvailabilityPolicy::class)->manage($this->actor(), $staff),
            ],
        ];
    }

    /**
     * @param  Collection<int, PersonnelAvailability>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rowsToArray(Collection $rows, string $kind): array
    {
        return $rows
            ->sortBy([['weekday', 'asc'], ['date', 'asc'], ['start_time', 'asc']])
            ->map(function (PersonnelAvailability $row) use ($kind): array {
                $base = [
                    'start_time' => substr((string) $row->start_time, 0, 5),
                    'end_time' => substr((string) $row->end_time, 0, 5),
                    'available' => $row->available,
                ];

                return $kind === 'recurring'
                    ? ['weekday' => (int) $row->weekday] + $base
                    : ['date' => $row->date?->format('Y-m-d')] + $base;
            })
            ->values()
            ->all();
    }

    /**
     * Active eligible services (ulid + name) — read-only summary; no contact data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function eligibleServices(StaffProfile $staff): array
    {
        return ServicePersonnelEligibility::query()
            ->where('staff_profile_id', $staff->id)
            ->where('active', true)
            ->with('service:id,ulid,name,status')
            ->get()
            ->filter(fn (ServicePersonnelEligibility $e) => $e->service !== null && $e->service->status === ServiceStatus::Active)
            ->map(fn (ServicePersonnelEligibility $e) => [
                'id' => $e->service?->ulid,
                'name' => $e->service?->name,
            ])
            ->values()
            ->all();
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
