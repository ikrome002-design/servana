<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\ScheduledPlanChangeStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\ScheduledPlanChange;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScheduledPlanChange>
 */
class ScheduledPlanChangeFactory extends Factory
{
    protected $model = ScheduledPlanChange::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subscription = MerchantSubscription::factory()->create();

        // Target plan + a price FOR THAT PLAN (composite FK on target).
        $targetPlan = SubscriptionPlan::factory()->create();
        $targetPrice = SubscriptionPlanPrice::factory()->create([
            'plan_id' => $targetPlan->id,
            'billing_interval' => BillingInterval::Monthly,
        ]);

        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => $subscription->merchant_id,
            'merchant_subscription_id' => $subscription->id,
            'target_plan_id' => $targetPlan->id,
            'target_price_id' => $targetPrice->id,
            'effective_at' => $subscription->current_period_end,
            'status' => ScheduledPlanChangeStatus::Scheduled,
            'applied_at' => null,
            'cancelled_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function forSubscription(MerchantSubscription $subscription): static
    {
        return $this->state(fn (array $attributes): array => [
            'merchant_id' => $subscription->merchant_id,
            'merchant_subscription_id' => $subscription->id,
            'effective_at' => $subscription->current_period_end,
        ]);
    }

    public function applied(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ScheduledPlanChangeStatus::Applied,
            'applied_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ScheduledPlanChangeStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
