<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Scheduling\Models\ServiceSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Personnel own-scope service-session payload (Plan §25.2, §19; Phase 16C). Exposes
 * only the MINIMUM a Personnel user needs to see their own assigned sessions: status,
 * service, a masked client summary, and the start/completion timestamps. No mutation
 * capabilities, no other personnel, no branch-wide data, NO contact export, NO full
 * phone/email, and NO earned/payable commission claim (the preview is never shown to
 * Personnel).
 *
 * @mixin ServiceSession
 */
final class PersonnelServiceSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'started_at' => $this->started_at === null ? null : $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at === null ? null : $this->completed_at->toIso8601String(),
            'cancelled_at' => $this->cancelled_at === null ? null : $this->cancelled_at->toIso8601String(),
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
