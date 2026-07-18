<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 20G §9.1 — the MINIMAL branch-scoped service option HR needs to configure a commission rule's
 * selected-services set (product-owner decision: a compensation-scoped read, NOT the branch-manager
 * service catalogue). Exposes only the public ULID and display name — never an internal id, price, cost,
 * commission setting, eligibility internal, category, status, actor, audit, provider or Wallet field. HR
 * reaches this through `compensation.plan.view`; `service.view` is never widened.
 *
 * @mixin Service
 */
final class CompensationSelectableServiceResource extends JsonResource
{
    /** @return array<string, string> */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
        ];
    }
}
