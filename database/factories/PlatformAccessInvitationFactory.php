<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\PlatformAccess\Enums\PlatformAccessInvitationStatus;
use App\Domain\PlatformAccess\Models\PlatformAccessInvitation;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformAccessInvitation>
 *
 * The token hash is a digest of a random value the factory then discards — a fixture never needs
 * the raw token, and keeping one around would create a test-only credential path that production
 * does not have.
 */
class PlatformAccessInvitationFactory extends Factory
{
    protected $model = PlatformAccessInvitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'email' => fn (): string => Str::lower(fake()->unique()->safeEmail()),
            'role_key' => PlatformAccessMembership::ROLE_SUPER_ADMIN,
            'purpose' => PlatformAccessInvitation::PURPOSE,
            'environment' => 'testing',
            'token_hash' => fn (): string => hash('sha256', Str::random(64)),
            'status' => PlatformAccessInvitationStatus::Pending,
            'invited_by_user_id' => User::factory()->state(['is_platform_staff' => true]),
            'expires_at' => now()->addHours(PlatformAccessInvitation::EXPIRY_HOURS),
            'resend_count' => 0,
        ];
    }

    /**
     * An expired invitation was necessarily ISSUED in the past: `expires_at > created_at` is a
     * CHECK constraint, so backdating only the expiry would be an unrepresentable row rather than a
     * realistic fixture.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'created_at' => now()->subDays(4),
            'updated_at' => now()->subDays(4),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PlatformAccessInvitationStatus::Revoked,
            'revoked_at' => now(),
            'revoked_by_user_id' => User::factory()->state(['is_platform_staff' => true]),
            'revocation_reason' => 'Revoked by a factory state.',
        ]);
    }
}
