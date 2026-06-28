<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\Service;
use App\Http\Resources\Concerns\HasCapabilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Service catalogue payload (Plan §39). ULID is the public id; money is exposed
 * as integer minor units + currency (never a float). The legacy
 * preferred-personnel fee is NOT exposed (internal seam).
 *
 * @mixin Service
 */
final class ServiceResource extends JsonResource
{
    use HasCapabilities;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'category_id' => $this->whenLoaded('category', fn () => $this->category?->ulid),
            'category_name' => $this->whenLoaded('category', fn () => $this->category?->name),
            'name' => $this->name,
            'description' => $this->description,
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status->value,
            'branch_id' => $this->whenLoaded('branch', fn () => $this->branch?->ulid),
            'can' => $this->capabilities($request, [
                'view' => 'view',
                'update' => 'update',
                'archive' => 'archive',
            ]),
        ];
    }
}
