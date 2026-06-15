<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StaffInvitation>
 *
 * Stores only a hash; tests that need the raw token should issue via
 * CreateStaffInvitation instead. The default hash is of a throwaway token.
 */
class StaffInvitationFactory extends Factory
{
    protected $model = StaffInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'branch_id' => MerchantBranch::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => MerchantUserRole::FrontOffice,
            'role_title' => 'Front Office',
            'token_hash' => hash('sha256', (string) Str::random(64)),
            'status' => StaffInvitationStatus::Pending,
            'expires_at' => now()->addHours(72),
            'resend_count' => 0,
            'last_sent_at' => now(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subHour(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => StaffInvitationStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => StaffInvitationStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }
}
