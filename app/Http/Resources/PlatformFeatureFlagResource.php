<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlag;
use App\Domain\PlatformFeatureFlags\Support\PlatformFeatureFlagDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A feature flag as the platform page sees it (COR-UI08-001 section 12; Phase UI-08).
 *
 * A flag is a CATALOGUE DEFINITION joined to per-environment STATE, and the payload keeps the two
 * visibly separate: the definition is code, the state is data, and only the state can be changed
 * through the API.
 *
 * `health_metric_available: false` is rendered when the definition names no metric. The page says
 * "no health metric available" rather than showing a zero — a fabricated health number on a rollout
 * screen is worse than none, because it invites an operator to trust it.
 */
final class PlatformFeatureFlagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(?PlatformFeatureFlagDefinition $definition, ?PlatformFeatureFlag $state): array
    {
        return [
            'definition' => $definition?->toArray(),
            'state' => $state === null ? null : [
                'id' => $state->ulid,
                'environment' => $state->environment,
                'state' => $state->state->value,
                'rollout_basis_points' => $state->rollout_basis_points,
                'effective_from' => $state->effective_from?->toIso8601String(),
                'effective_to' => $state->effective_to?->toIso8601String(),
                'version' => $state->version,
                'approved_configuration_hash' => $state->approved_configuration_hash,
                'targets' => $state->targets
                    ->map(static fn ($target): array => [
                        'type' => $target->target_type,
                        'value' => $target->target_value,
                    ])
                    ->all(),
            ],
            // No row for this environment is the DEFAULT state, and the default is off.
            'effective_state' => $state?->state->value ?? 'inactive',
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return self::payload(null, $this->resource instanceof PlatformFeatureFlag ? $this->resource : null);
    }
}
