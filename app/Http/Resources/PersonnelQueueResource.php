<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Scheduling\Models\QueueEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Personnel own-scope queue payload (Plan §37, §19; Phase 16B). Exposes only the
 * MINIMUM needed for a Personnel user to perform their assigned work: status,
 * position, service, a masked client summary, the labelled wait estimate, and a
 * preferred-request indicator. No mutation capabilities, no other personnel, no
 * branch-wide data, and NO contact export.
 *
 * @mixin QueueEntry
 */
final class PersonnelQueueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'position' => $this->position,
            'queued_at' => $this->queued_at->toIso8601String(),
            'estimated_wait' => [
                'label' => 'Estimate',
                'effective_minutes' => $this->effectiveWaitMinutes(),
            ],
            'is_preferred_request' => $this->preferred_personnel_staff_profile_id !== null
                && $this->preferred_personnel_staff_profile_id === $this->staff_profile_id,
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
                ];
            }),
        ];
    }
}
