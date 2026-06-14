<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Enums\ServiceFeeTier;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'ulid' => (string) Str::ulid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => MerchantStatus::PendingSetup,
            'service_fee_tier' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MerchantStatus::Active,
            'service_fee_tier' => ServiceFeeTier::CustomerCentric,
            'setup_completed_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MerchantStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MerchantStatus::Deactivated,
            'deactivated_at' => now(),
        ]);
    }
}
