<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagChangeRequestStatus;
use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagState;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlag;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagChangeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformFeatureFlagChangeRequest>
 *
 * The four governance fields are always populated because they are NOT NULL: a change request with
 * no stated impact or rollback plan is unrepresentable, and a factory that omitted them would be
 * modelling a row the database refuses.
 */
class PlatformFeatureFlagChangeRequestFactory extends Factory
{
    protected $model = PlatformFeatureFlagChangeRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $proposed = [
            'state' => PlatformFeatureFlagState::Active->value,
            'rollout_basis_points' => 10000,
            'effective_from' => now()->subDay()->toIso8601String(),
            'effective_to' => null,
            'targets' => [],
        ];

        return [
            'ulid' => (string) Str::ulid(),
            'feature_flag_id' => PlatformFeatureFlag::factory(),
            'status' => PlatformFeatureFlagChangeRequestStatus::Pending,
            'proposed_configuration' => $proposed,
            'proposed_configuration_hash' => PlatformFeatureFlagChangeRequest::hashConfiguration($proposed),
            'impact_statement' => 'Enables the gated capability for all merchants in this environment.',
            'rollback_plan' => 'Pause the flag, then request a change back to inactive.',
            'health_criterion' => 'Error rate on the affected endpoints stays below the current baseline.',
            'reason' => 'Rolling the capability out after review.',
            'requested_by_user_id' => User::factory()->state(['is_platform_staff' => true]),
            'requested_at' => now(),
        ];
    }
}
