<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionPlanPrice>
 */
class SubscriptionPlanPriceFactory extends Factory
{
    protected $model = SubscriptionPlanPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'plan_id' => SubscriptionPlan::factory(),
            'amount_minor' => fake()->numberBetween(100000, 5000000),
            'currency' => 'KES',
            'billing_interval' => BillingInterval::Monthly,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'created_by' => User::factory(),
        ];
    }

    public function interval(BillingInterval $interval): static
    {
        return $this->state(fn (array $attributes): array => ['billing_interval' => $interval]);
    }
}
