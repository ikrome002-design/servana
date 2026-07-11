<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\SubscriptionPlanStatus;
use App\Domain\Billing\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'key' => 'plan_'.Str::lower(Str::random(10)),
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'tier' => fake()->randomElement([null, 'starter', 'growth', 'scale']),
            'metadata' => [],
            'status' => SubscriptionPlanStatus::Active,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function retired(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => SubscriptionPlanStatus::Retired]);
    }
}
