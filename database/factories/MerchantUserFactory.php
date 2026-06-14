<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MerchantUser>
 */
class MerchantUserFactory extends Factory
{
    protected $model = MerchantUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'user_id' => User::factory(),
            'role' => MerchantUserRole::MerchantAdmin,
            'status' => MerchantUserStatus::Active,
            'activated_at' => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => MerchantUserRole::MerchantAdmin,
        ]);
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MerchantUserStatus::Invited,
            'activated_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MerchantUserStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MerchantUserStatus::Deactivated,
            'deactivated_at' => now(),
        ]);
    }
}
