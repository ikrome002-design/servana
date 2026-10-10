<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Clients\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Personally served client context. It is deliberately not an exportable contact record. */
final class PersonnelServedClientContextResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Client $client */
        $client = $this->resource;

        return [
            'id' => $client->ulid,
            'full_name' => $client->full_name,
            'phone_masked' => $client->maskedPhone(),
            'last_served_at' => $client->getAttribute('last_served_at'),
            'visit_count' => (int) $client->getAttribute('visit_count'),
            'services' => $client->getAttribute('served_services') ?? [],
            'sms_consent' => $client->getAttribute('sms_consent_state') ?? 'missing',
            'sms_eligible' => $client->getAttribute('sms_consent_state') === 'opted_in'
                && $client->status->value === 'active',
        ];
    }
}
