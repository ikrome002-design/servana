<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServiceCategory>
 */
class ServiceCategoryFactory extends Factory
{
    protected $model = ServiceCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            // Derive merchant_id from the branch so the composite consistency FK holds.
            'merchant_id' => fn (array $attributes) => MerchantBranch::query()
                ->whereKey($attributes['branch_id'])->value('merchant_id'),
            'name' => fake()->unique()->randomElement(['Hair', 'Nails', 'Spa', 'Massage', 'Grooming', 'Barber']).' '.fake()->randomNumber(3),
            'sort_order' => 0,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['archived_at' => now()]);
    }
}
