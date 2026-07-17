<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Branches\Models\MerchantBranch;
use App\Http\Resources\Concerns\HasCapabilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe branch payload (Plan §6.2, Scope §3.3). ULID is the public id (A5).
 *
 * @mixin MerchantBranch
 */
final class BranchResource extends JsonResource
{
    use HasCapabilities;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'town' => $this->town,
            'phone' => $this->phone,
            'email' => $this->email,
            'business_category' => $this->business_category,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'archived_at' => $this->archived_at === null ? null : $this->archived_at->toIso8601String(),
            'can' => $this->capabilities($request, [
                'view' => 'view',
                'update' => 'update',
                'archive' => 'archive',
                'manage_operating_hours' => 'manageOperatingHours',
                'manage_day' => 'manageDay',
            ]),
        ];
    }
}
