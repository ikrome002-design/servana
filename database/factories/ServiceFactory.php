<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Enums\ServiceStatus;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'merchant_id' => fn (array $attributes) => MerchantBranch::query()
                ->whereKey($attributes['branch_id'])->value('merchant_id'),
            // Category in the SAME branch + merchant (composite FK requires same tenant).
            'category_id' => fn (array $attributes) => ServiceCategory::factory()->create([
                'branch_id' => $attributes['branch_id'],
                'merchant_id' => $attributes['merchant_id'],
            ])->id,
            'name' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'price_minor' => fake()->numberBetween(50000, 500000),
            'currency' => 'KES',
            'duration_minutes' => fake()->randomElement([30, 45, 60, 90, 120]),
            'status' => ServiceStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => ServiceStatus::Archived]);
    }
}
