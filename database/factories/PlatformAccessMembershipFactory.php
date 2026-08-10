<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\PlatformAccess\Enums\PlatformAccessStatus;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformAccessMembership>
 *
 * Default: an ACTIVE membership whose user already carries the derived `is_platform_staff` mirror,
 * so a factory-built administrator behaves exactly like one the lifecycle actions produced.
 */
class PlatformAccessMembershipFactory extends Factory
{
    protected $model = PlatformAccessMembership::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'user_id' => User::factory()->state(['is_platform_staff' => true]),
            'role_key' => PlatformAccessMembership::ROLE_SUPER_ADMIN,
            'status' => PlatformAccessStatus::Active,
            'activated_at' => now(),
            'last_action' => 'activated',
            'last_action_at' => now(),
        ];
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => User::factory()->state(['is_platform_staff' => false]),
            'status' => PlatformAccessStatus::Invited,
            'activated_at' => null,
            'invited_at' => now(),
            'last_action' => 'invited',
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => User::factory()->state(['is_platform_staff' => false]),
            'status' => PlatformAccessStatus::Suspended,
            'activated_at' => now()->subMonth(),
            'suspended_at' => now(),
            'last_action' => 'suspended',
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => User::factory()->state(['is_platform_staff' => false]),
            'status' => PlatformAccessStatus::Deactivated,
            'activated_at' => now()->subMonth(),
            'deactivated_at' => now(),
            'last_action' => 'deactivated',
        ]);
    }
}
