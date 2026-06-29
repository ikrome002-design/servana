<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Scheduling\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Personnel own-scope appointment payload (Plan §36, §19.3; guardrail §6.4, §6.8).
 *
 * Read-only and minimal: only the information a personnel member needs to perform
 * their own assigned appointment — date/time, status, service, and the MASKED
 * client summary. No mutation capability map, no other personnel, no unmasked
 * contact, and NO contact export anywhere. Internal ids are never serialized.
 *
 * @mixin Appointment
 */
final class PersonnelAppointmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
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
        ];
    }
}
