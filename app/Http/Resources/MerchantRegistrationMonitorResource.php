<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Merchants\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Merchant registration-monitoring row (Plan §22, §24.1; Phase 20B). A read-only view of the
 * onboarding funnel for Super-Admin oversight: operational + billing status, whether setup is still
 * pending, and the registration / setup-completion timestamps. Public ULID only; no owner user id.
 *
 * @mixin Merchant
 */
final class MerchantRegistrationMonitorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'operational_status' => $this->status->value,
            'billing_status' => $this->billing_status->value,
            'pending_setup' => $this->status->isPendingSetup(),
            'registered_at' => $this->created_at === null ? null : $this->created_at->toIso8601String(),
            'setup_completed_at' => $this->setup_completed_at === null ? null : $this->setup_completed_at->toIso8601String(),
        ];
    }
}
