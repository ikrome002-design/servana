<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Clients\Enums\ConsentChannel;
use App\Domain\Clients\Models\Client;
use App\Http\Resources\Concerns\HasCapabilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Client payload (Plan §35; guardrail §6.4). Contact is ALWAYS masked:
 * `phone_masked` / `phone_last_four` and `email_masked` only — the full phone,
 * full email, ciphertext, and the blind index are NEVER serialized (the model
 * also $hides them). There is no contact-export field anywhere.
 *
 * @mixin Client
 */
final class ClientResource extends JsonResource
{
    use HasCapabilities;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'full_name' => $this->full_name,
            'phone_masked' => $this->maskedPhone(),
            'phone_last_four' => $this->phone_last_four,
            'email_masked' => $this->maskedEmail(),
            'has_email' => $this->email_encrypted !== null,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'sms_consent' => $this->whenLoaded('consents', function () {
                $consent = $this->consents->firstWhere('channel', ConsentChannel::Sms);

                return $consent?->state->value;
            }),
            'can' => $this->capabilities($request, [
                'view' => 'view',
                'update' => 'update',
            ]),
        ];
    }
}
