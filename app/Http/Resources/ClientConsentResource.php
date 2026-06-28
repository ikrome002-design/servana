<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Clients\Models\ClientConsent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SMS-consent payload (Plan §35). No contact data — channel/state/source only.
 *
 * @mixin ClientConsent
 */
final class ClientConsentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'channel' => $this->channel->value,
            'state' => $this->state->value,
            'source' => $this->source,
            'changed_at' => $this->changed_at->toIso8601String(),
        ];
    }
}
