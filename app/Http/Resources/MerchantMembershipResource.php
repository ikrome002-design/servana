<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Merchants\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe membership bootstrap payload (Plan §6.2, §10). Exposes only the role and
 * status; the resolved permission set arrives with the Phase 8 registry.
 *
 * @mixin MerchantUser
 */
final class MerchantMembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'role' => $this->role->value,
            'status' => $this->status->value,
        ];
    }
}
