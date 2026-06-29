<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Personnel availability schedule payload (Plan §80 Phase 15B). Composite read
 * view assembled by StaffAvailabilityController from the AvailabilityResolver +
 * eligibility query. Exposes ONLY safe fields: the staff ULID + display/lifecycle,
 * the recurring + exception rows, the derived current state, active eligible
 * services (ulid + name), a capability map, and the branch business timezone.
 *
 * Never exposes: sequential database ids, hidden staff contact data, permission
 * internals, audit internals, the change reason, or another branch's schedule.
 *
 * @property array<string, mixed> $resource
 */
final class PersonnelAvailabilityScheduleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [
            'staff' => $data['staff'],
            'timezone' => $data['timezone'],
            'current_state' => $data['current_state'],
            'recurring' => $data['recurring'],
            'exceptions' => $data['exceptions'],
            'eligible_services' => $data['eligible_services'],
            'can' => $data['can'],
        ];
    }
}
