<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Queue-entry payload (Plan §37; guardrail §6.4). Exposes the queue-entry ULID,
 * source ULID (walk-in/appointment), status, position, assignment mode, queue
 * timestamps, a MASKED client summary, the service, assigned/preferred personnel
 * ULIDs + display names, and the wait estimate (calculated + any override, labelled
 * "Estimate"). Internal bigint ids, full phone/email, the blind index, audit
 * internals, SQLSTATE, and constraint names are NEVER serialized. The `can` map is
 * state-aware (policy permission AND current legal transition) — UX only.
 *
 * @mixin QueueEntry
 */
final class QueueEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $lifecycle = [QueueEntryStatus::Waiting, QueueEntryStatus::Assigned, QueueEntryStatus::Called];

        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'position' => $this->position,
            'assignment_mode' => $this->assignment_mode->value,
            'source' => $this->source(),
            'queued_at' => $this->queued_at->toIso8601String(),
            'assigned_at' => $this->assigned_at === null ? null : $this->assigned_at->toIso8601String(),
            'called_at' => $this->called_at === null ? null : $this->called_at->toIso8601String(),
            'started_at' => $this->started_at === null ? null : $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at === null ? null : $this->completed_at->toIso8601String(),
            'cancelled_at' => $this->cancelled_at === null ? null : $this->cancelled_at->toIso8601String(),
            'no_show_at' => $this->no_show_at === null ? null : $this->no_show_at->toIso8601String(),
            'transferred_at' => $this->transferred_at === null ? null : $this->transferred_at->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'transfer_reason' => $this->transfer_reason,
            'preferred_personnel_override_reason' => $this->preferred_personnel_override_reason,
            'estimated_wait' => [
                'label' => 'Estimate',
                'minutes' => $this->estimated_wait_minutes,
                'override_minutes' => $this->estimated_wait_override_minutes,
                'override_reason' => $this->estimated_wait_override_reason,
                'effective_minutes' => $this->effectiveWaitMinutes(),
            ],
            'service' => $this->whenLoaded('service', function (): array {
                /** @var Service $service */
                $service = $this->service;

                return ['id' => $service->ulid, 'name' => $service->name, 'duration_minutes' => $service->duration_minutes];
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
            'assigned_personnel' => $this->whenLoaded('assignedPersonnel', function (): ?array {
                /** @var StaffProfile|null $personnel */
                $personnel = $this->assignedPersonnel;

                return $personnel === null ? null : ['id' => $personnel->ulid, 'display_name' => $personnel->display_name];
            }),
            'preferred_personnel' => $this->whenLoaded('preferredPersonnel', function (): ?array {
                /** @var StaffProfile|null $personnel */
                $personnel = $this->preferredPersonnel;

                return $personnel === null ? null : ['id' => $personnel->ulid, 'display_name' => $personnel->display_name];
            }),
            'service_session' => $this->whenLoaded('serviceSession', function (): ?array {
                if ($this->serviceSession === null) {
                    return null;
                }

                return ServiceSessionResource::make($this->serviceSession)->toArray(request());
            }),
            'can' => $this->capabilities($request, $lifecycle),
        ];
    }

    /** @return array{type: string, id: string}|null */
    private function source(): ?array
    {
        if ($this->walk_in_id !== null) {
            return ['type' => 'walk_in', 'id' => $this->whenLoaded('walkIn', fn (): string => (string) $this->walkIn?->ulid, '')];
        }
        if ($this->appointment_id !== null) {
            return ['type' => 'appointment', 'id' => $this->whenLoaded('appointment', fn (): string => (string) $this->appointment?->ulid, '')];
        }

        return null;
    }

    /**
     * State-aware capability map (policy permission AND current legal transition).
     *
     * @param  list<QueueEntryStatus>  $lifecycle
     * @return array<string, bool>
     */
    private function capabilities(Request $request, array $lifecycle): array
    {
        $user = $request->user();
        $status = $this->status;

        $can = fn (string $ability): bool => $user !== null && $user->can($ability, $this->resource);
        $operate = $can('operate');

        return [
            'view' => $can('view'),
            'assign' => $operate && $status === QueueEntryStatus::Waiting,
            'call' => $operate && $status === QueueEntryStatus::Assigned,
            'start' => $operate && $status === QueueEntryStatus::Called,
            'complete' => $operate && $status === QueueEntryStatus::InService,
            'transfer' => $can('transfer') && in_array($status, $lifecycle, true),
            'cancel' => $operate && in_array($status, $lifecycle, true),
            'no_show' => $operate && in_array($status, $lifecycle, true),
        ];
    }
}
