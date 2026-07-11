<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\PlanEntitlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan-entitlement payload (Plan §13.9, §20, §47; Phase 20A). Exposes the entitlement key, enabled
 * flag, and optional limit; never the internal id or plan_id.
 *
 * @mixin PlanEntitlement
 */
final class PlanEntitlementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'entitlement_key' => $this->entitlement_key,
            'enabled' => $this->enabled,
            'limit_int' => $this->limit_int,
        ];
    }
}
