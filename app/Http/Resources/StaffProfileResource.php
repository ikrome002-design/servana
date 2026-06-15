<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Hr\Models\StaffProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe staff-profile payload with the membership status badge (Scope §3.4
 * Staff Operational Screen). ULID is the public id.
 *
 * @mixin StaffProfile
 */
final class StaffProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $membership = $this->merchantUser;

        return [
            'id' => $this->ulid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'display_name' => $this->display_name,
            'phone' => $this->phone,
            'role' => $membership?->role->value,
            'role_title' => $this->role_title,
            // Membership status drives the UI badge: invited|active|suspended|deactivated.
            'status' => $membership?->status->value,
            'employment_type' => $this->employment_type->value,
            'employment_status' => $this->employment_status->value,
            'primary_branch_id' => $this->primaryBranch?->ulid,
            'is_active' => $this->is_active,
        ];
    }
}
