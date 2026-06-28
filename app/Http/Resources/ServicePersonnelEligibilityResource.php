<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Personnel-service eligibility payload (Plan §39). Exposes the service + staff
 * ULIDs and active flag — no contact data (staff display name only).
 *
 * @mixin ServicePersonnelEligibility
 */
final class ServicePersonnelEligibilityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'service_id' => $this->whenLoaded('service', fn () => $this->service?->ulid),
            'service_name' => $this->whenLoaded('service', fn () => $this->service?->name),
            'staff_profile_id' => $this->whenLoaded('staffProfile', fn () => $this->staffProfile?->ulid),
            'staff_name' => $this->whenLoaded('staffProfile', fn () => $this->staffProfile?->display_name),
            'active' => $this->active,
        ];
    }
}
