<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Platform merchant governance payload (Plan §22, §24.1; Phase 20B). Super-Admin read of a merchant
 * governance record: the OPERATIONAL status and the BILLING status are exposed as SEPARATE,
 * independent fields (governance never conflates them). Includes a server-derived `can` map whose
 * flags reflect both the platform grant AND the validity of the operational-status transition.
 * Public ULID only — never the internal id or the owner user id.
 *
 * @mixin Merchant
 */
final class PlatformMerchantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $context = app(TenantContext::class);
        $status = $this->status;

        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'operational_status' => $status->value,
            'billing_status' => $this->billing_status->value,
            'billing_status_reason' => $this->billing_status_reason,
            'suspension_reason' => $this->suspension_reason,
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'setup_completed_at' => $this->setup_completed_at?->toIso8601String(),
            'registered_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'suspend' => $context->can('platform.merchant.suspend') && $status->canTransitionTo(MerchantStatus::Suspended),
                'reactivate' => $context->can('platform.merchant.reactivate') && $status->canTransitionTo(MerchantStatus::Active),
                'deactivate' => $context->can('platform.merchant.deactivate') && $status->canTransitionTo(MerchantStatus::Deactivated),
            ],
        ];
    }
}
