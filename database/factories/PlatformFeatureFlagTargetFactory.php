<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagTargetType;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlag;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlagTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformFeatureFlagTarget>
 */
class PlatformFeatureFlagTargetFactory extends Factory
{
    protected $model = PlatformFeatureFlagTarget::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'feature_flag_id' => PlatformFeatureFlag::factory(),
            'target_type' => PlatformFeatureFlagTargetType::Merchant->value,
            'target_value' => fn (): string => (string) Str::ulid(),
            'created_by_user_id' => User::factory()->state(['is_platform_staff' => true]),
        ];
    }

    public function forSubject(string $subjectUlid): static
    {
        return $this->state(fn (array $attributes): array => ['target_value' => $subjectUlid]);
    }
}
