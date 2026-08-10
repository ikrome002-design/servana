<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A feature-flag change request (COR-UI08-001 section 12.3; Phase UI-08).
 *
 * The governance fields are surfaced, not hidden behind a detail view: the impact statement,
 * rollback plan and health criterion are the point of maker/checker, and an approver who cannot see
 * them is not really checking anything.
 *
 * @mixin PlatformFeatureFlagChangeRequest
 */
final class PlatformFeatureFlagChangeRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'flag_key' => $this->flag?->flag_key,
            'status' => $this->status->value,
            'proposed_configuration' => $this->proposed_configuration,
            'proposed_configuration_hash' => $this->proposed_configuration_hash,
            'impact_statement' => $this->impact_statement,
            'rollback_plan' => $this->rollback_plan,
            'health_criterion' => $this->health_criterion,
            'reason' => $this->reason,
            'requested_by' => $this->requestedBy?->ulid,
            'approved_by' => $this->approvedBy?->ulid,
            'requested_at' => $this->requested_at->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'failure_reason' => $this->failure_reason,
        ];
    }
}
