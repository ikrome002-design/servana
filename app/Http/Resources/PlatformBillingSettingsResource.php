<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\PlatformBillingSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Platform billing-settings payload (Plan §13.9, §47; Phase 20A). Exposes the effective version's
 * ULID + billing config + general settings; never the internal id or the `updated_by` user id.
 *
 * @mixin PlatformBillingSettings
 */
final class PlatformBillingSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'billing_mode' => $this->billing_mode->value,
            'default_trial_days' => $this->default_trial_days,
            'grace_days' => $this->grace_days,
            'currency' => $this->currency,
            'settings' => $this->settings,
            'effective_from' => $this->effective_from->toIso8601String(),
        ];
    }
}
