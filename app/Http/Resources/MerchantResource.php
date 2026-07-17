<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Merchants\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe merchant bootstrap payload (Plan §6.2, §8.1). ULID is the public id (A5);
 * the bigint PK never leaves. No financial or governance fields are exposed here.
 *
 * @mixin Merchant
 */
final class MerchantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'service_fee_tier' => $this->service_fee_tier === null ? null : $this->service_fee_tier->value,
            'setup_completed_at' => $this->setup_completed_at === null ? null : $this->setup_completed_at->toIso8601String(),
        ];
    }
}
