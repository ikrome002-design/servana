<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An eligible person as a Front Office picker shows it (LUAT-D01): the staff profile's public id and
 * display name. No phone, email, role, employment, compensation or HR history. Only active
 * eligibility reaches this resource, so `active` is always true; it keeps the field the five
 * screens already read.
 *
 * @mixin ServicePersonnelEligibility
 */
final class FrontOfficePersonnelOptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'staff_profile_id' => $this->staffProfile?->ulid,
            'staff_name' => $this->staffProfile?->display_name,
            'active' => (bool) $this->active,
        ];
    }
}
