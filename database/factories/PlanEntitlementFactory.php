<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Models\PlanEntitlement;
use App\Domain\Billing\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlanEntitlement>
 */
class PlanEntitlementFactory extends Factory
{
    protected $model = PlanEntitlement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => SubscriptionPlan::factory(),
            'entitlement_key' => 'entitlement.'.Str::lower(Str::random(8)),
            'limit_int' => fake()->randomElement([null, 1, 3, 5, 10]),
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => ['enabled' => false]);
    }

    public function limited(int $limit): static
    {
        return $this->state(fn (array $attributes): array => ['limit_int' => $limit, 'enabled' => true]);
    }

    public function unlimited(): static
    {
        return $this->state(fn (array $attributes): array => ['limit_int' => null, 'enabled' => true]);
    }
}
