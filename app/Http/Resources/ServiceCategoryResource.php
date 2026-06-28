<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\ServiceCategory;
use App\Http\Resources\Concerns\HasCapabilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Service-category payload (Plan §39). ULID is the public id.
 *
 * @mixin ServiceCategory
 */
final class ServiceCategoryResource extends JsonResource
{
    use HasCapabilities;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'archived' => $this->archived_at !== null,
            'services_count' => $this->whenCounted('services'),
            'can' => $this->capabilities($request, [
                'view' => 'view',
                'update' => 'update',
            ]),
        ];
    }
}
