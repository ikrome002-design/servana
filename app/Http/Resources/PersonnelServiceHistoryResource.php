<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Scheduling\Models\ServiceSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A completed/cancelled own service record with masked client context and safe earnings linkage. */
final class PersonnelServiceHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ServiceSession $session */
        $session = $this->resource;
        /** @var Service|null $service */
        $service = $session->service;
        /** @var Client|null $client */
        $client = $session->client;

        $duration = $session->started_at !== null && $session->completed_at !== null
            ? (int) $session->started_at->diffInMinutes($session->completed_at)
            : null;

        return [
            'id' => $session->ulid,
            'status' => $session->status->value,
            'started_at' => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'cancelled_at' => $session->cancelled_at?->toIso8601String(),
            'duration_minutes' => $duration,
            'preferred_personnel_honored' => $session->preferred_personnel_honored,
            'commission' => [
                'id' => $session->getAttribute('commission_ulid'),
                'status' => $session->getAttribute('commission_status'),
                'explanation' => 'Completion alone does not earn commission. Commission becomes earned only after Finance validates payment.',
            ],
            'service' => $service === null ? null : ['id' => $service->ulid, 'name' => $service->name],
            'client' => $client === null ? null : [
                'id' => $client->ulid,
                'full_name' => $client->full_name,
                'phone_masked' => $client->maskedPhone(),
            ],
        ];
    }
}
