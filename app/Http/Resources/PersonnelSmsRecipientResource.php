<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Clients\Models\Client;
use App\Domain\Messaging\Sms\Enums\SmsRecipientExclusionReason;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Support\PhoneNumberDisplayMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One recipient of a Personnel SMS campaign (Plan §64; ADR-010; Phase 21S).
 *
 * THE MOST CONTACT-SENSITIVE PAYLOAD IN THE PHASE, and the tightest:
 *   - `phone_masked` is rendered from `phone_last_four` through
 *     {@see PhoneNumberDisplayMasker}, which cannot return more than four digits;
 *   - the encrypted delivery snapshot (`phone_encrypted`) is `$hidden` on the model and is never
 *     referenced here at all — a page of this collection is not a phone list;
 *   - `provider_message_id` is deliberately NOT exposed: it is an opaque provider-side handle with
 *     no merchant value, and some providers embed the destination in it;
 *   - the eligibility snapshot is reduced to its safe `exclusion_reason` code, never the raw jsonb;
 *   - no internal ids, no merchant/branch ids, no session id.
 *
 * `delivery_status` + `exclusion_reason` are what let the UI explain "why didn't this client get
 * it" using the closed, contact-free vocabulary of
 * {@see SmsRecipientExclusionReason}.
 *
 * @mixin PersonnelSmsRecipient
 */
final class PersonnelSmsRecipientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = $this->eligibility_snapshot_json;
        $exclusionReason = $snapshot['exclusion_reason'] ?? null;

        return [
            'id' => (string) $this->client?->ulid,
            'client' => $this->whenLoaded('client', function (): array {
                /** @var Client $client */
                $client = $this->client;

                return [
                    'id' => $client->ulid,
                    'full_name' => $client->full_name,
                    'phone_masked' => PhoneNumberDisplayMasker::maskFromLastFour($client->phone_last_four),
                ];
            }),
            'phone_masked' => PhoneNumberDisplayMasker::maskFromLastFour($this->phone_last_four),
            'delivery_status' => $this->delivery_status->value,
            'delivery_status_label' => $this->delivery_status->label(),
            'consent_status' => $this->consent_status_snapshot->value,
            'exclusion_reason' => is_string($exclusionReason) ? $exclusionReason : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
