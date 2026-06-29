<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Appointment payload (Plan §36; guardrail §6.4). Exposes the appointment ULID +
 * reference, status, branch-safe times, the service (ULID/name/duration snapshot),
 * a MASKED client summary, and preferred/assigned personnel ULIDs + display names.
 * Internal bigint ids, full phone/email, the blind index, audit internals, and DB
 * constraint names are NEVER serialized. The `can` map is state-aware: it combines
 * the AppointmentPolicy permission with the current state's legal transitions, so
 * the UI only offers real actions (it is UX only — the API re-checks every
 * mutation).
 *
 * @mixin Appointment
 */
final class AppointmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'no_show_at' => $this->no_show_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'service' => $this->whenLoaded('service', function (): array {
                /** @var Service $service */
                $service = $this->service;

                return [
                    'id' => $service->ulid,
                    'name' => $service->name,
                    'duration_minutes' => $service->duration_minutes,
                ];
            }),
            'client' => $this->whenLoaded('client', function (): array {
                /** @var Client $client */
                $client = $this->client;

                return [
                    'id' => $client->ulid,
                    'full_name' => $client->full_name,
                    'phone_masked' => $client->maskedPhone(),
                    'phone_last_four' => $client->phone_last_four,
                ];
            }),
            'preferred_personnel' => $this->whenLoaded('preferredPersonnel', function (): ?array {
                /** @var StaffProfile|null $personnel */
                $personnel = $this->preferredPersonnel;

                return $personnel === null ? null : ['id' => $personnel->ulid, 'display_name' => $personnel->display_name];
            }),
            'assigned_personnel' => $this->whenLoaded('assignedPersonnel', function (): ?array {
                /** @var StaffProfile|null $personnel */
                $personnel = $this->assignedPersonnel;

                return $personnel === null ? null : ['id' => $personnel->ulid, 'display_name' => $personnel->display_name];
            }),
            'can' => $this->capabilities($request),
        ];
    }

    /**
     * State-aware capability map (policy permission AND current legal transition).
     *
     * @return array<string, bool>
     */
    private function capabilities(Request $request): array
    {
        $user = $request->user();
        $status = $this->status;
        $assigned = $this->assigned_personnel_staff_profile_id !== null;

        $can = fn (string $ability): bool => $user !== null && $user->can($ability, $this->resource);

        return [
            'view' => $can('view'),
            'assign' => $can('assign') && $status === AppointmentStatus::Scheduled,
            'transfer' => $can('transfer') && $assigned
                && in_array($status, [AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn], true),
            'reschedule' => $can('reschedule') && $status === AppointmentStatus::Confirmed,
            'check_in' => $can('checkIn') && $status === AppointmentStatus::Confirmed,
            'cancel' => $can('cancel') && in_array($status, [
                AppointmentStatus::Scheduled,
                AppointmentStatus::Confirmed,
                AppointmentStatus::CheckedIn,
            ], true),
            'mark_no_show' => $can('markNoShow') && $status === AppointmentStatus::Confirmed,
        ];
    }
}
