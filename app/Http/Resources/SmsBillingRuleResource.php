<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Enums\PlatformSmsBillingRuleState;
use App\Domain\Billing\Models\PlatformSmsBillingRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An SMS pricing rule (COR-UI08-001 §9; Phase UI-08). Exposes the ULID, the integer minor-unit
 * price, the disclosure/threshold fields, the effective date and the DERIVED state — never the
 * internal id and never an actor's user id.
 *
 * The derived state and the resolved currency are supplied by the caller, which knows the series
 * and the effective settings version; computing them per row here would issue a query per item.
 *
 * @mixin PlatformSmsBillingRule
 */
final class SmsBillingRuleResource extends JsonResource
{
    public function __construct(
        PlatformSmsBillingRule $resource,
        private readonly PlatformSmsBillingRuleState $state,
        private readonly string $currency,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'state' => $this->state->value,
            'unit_cost_minor' => $this->unit_cost_minor,
            'currency' => $this->currency,
            'tax_basis_points' => $this->tax_basis_points,
            'usage_warning_threshold_units' => $this->usage_warning_threshold_units,
            'usage_anomaly_threshold_basis_points' => $this->usage_anomaly_threshold_basis_points,
            'effective_from' => $this->effective_from->toIso8601String(),
            'reason' => $this->reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
        ];
    }
}
