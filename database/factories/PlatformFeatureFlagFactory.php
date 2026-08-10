<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagState;
use App\Domain\PlatformFeatureFlags\Models\PlatformFeatureFlag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformFeatureFlag>
 *
 * A factory flag is `inactive` by default, matching the real default: a flag that exists in the
 * catalogue but has never been switched on. States that can allow must be set deliberately, so no
 * test accidentally starts from "on".
 */
class PlatformFeatureFlagFactory extends Factory
{
    protected $model = PlatformFeatureFlag::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'flag_key' => fn (): string => 'test.flag.'.Str::lower(Str::random(8)),
            'environment' => 'testing',
            'state' => PlatformFeatureFlagState::Inactive,
            'rollout_basis_points' => 0,
            'version' => 1,
            'updated_by_user_id' => User::factory()->state(['is_platform_staff' => true]),
        ];
    }

    /** Fully rolled out and in force now — the only shape that can yield an allow. */
    public function active(int $rolloutBasisPoints = 10000): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => PlatformFeatureFlagState::Active,
            'rollout_basis_points' => $rolloutBasisPoints,
            'effective_from' => now()->subDay(),
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => PlatformFeatureFlagState::Scheduled,
            'rollout_basis_points' => 10000,
            'effective_from' => now()->addWeek(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => PlatformFeatureFlagState::Paused,
            'rollout_basis_points' => 10000,
            'effective_from' => now()->subDay(),
        ]);
    }

    public function forKey(string $flagKey, string $environment = 'testing'): static
    {
        return $this->state(fn (array $attributes): array => [
            'flag_key' => $flagKey,
            'environment' => $environment,
        ]);
    }
}
