<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bookable service as a Front Office picker shows it (LUAT-D01): its public id, name and duration.
 * No price, description, category administration, status or edit capability — the catalogue itself
 * stays a Branch Manager surface, and the invoice derives every amount server-side.
 *
 * @mixin Service
 */
final class FrontOfficeServiceOptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'duration_minutes' => $this->duration_minutes,
        ];
    }
}
