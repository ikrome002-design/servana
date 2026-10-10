<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Catalogue\Enums\ServiceStatus;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\AvailabilityType;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use Illuminate\Support\Collection;

/** Shared safe availability projection for HR/Branch reads and Personnel's own read-only page. */
final class PersonnelAvailabilityReadModel
{
    public function __construct(
        private readonly AvailabilityResolver $resolver,
        private readonly PersonnelStateProjector $stateProjector,
    ) {}

    /** @return array<string, mixed> */
    public function forStaff(StaffProfile $staff, bool $canUpdate): array
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
            'current_state' => $this->stateProjector->currentState($staff, null, $rows)->value,
            'recurring' => $this->rowsToArray(
                $rows->filter(fn (PersonnelAvailability $row) => $row->type === AvailabilityType::Recurring),
                'recurring',
            ),
            'exceptions' => $this->rowsToArray(
                $rows->filter(fn (PersonnelAvailability $row) => $row->type === AvailabilityType::Exception),
                'exception',
            ),
            'eligible_services' => $this->eligibleServices($staff),
            'can' => ['update' => $canUpdate],
        ];
    }

    /** @param Collection<int, PersonnelAvailability> $rows
     * @return list<array<string, mixed>>
     */
    private function rowsToArray(Collection $rows, string $kind): array
    {
        return array_values($rows
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
            ->all());
    }

    /** @return list<array{id: string|null, name: string|null}> */
    private function eligibleServices(StaffProfile $staff): array
    {
        return array_values(ServicePersonnelEligibility::query()
            ->where('staff_profile_id', $staff->id)
            ->where('active', true)
            ->with('service:id,ulid,name,status')
            ->get()
            ->filter(fn (ServicePersonnelEligibility $eligibility) => $eligibility->service !== null
                && $eligibility->service->status === ServiceStatus::Active)
            ->map(fn (ServicePersonnelEligibility $eligibility) => [
                'id' => $eligibility->service?->ulid,
                'name' => $eligibility->service?->name,
            ])
            ->values()
            ->all());
    }
}
