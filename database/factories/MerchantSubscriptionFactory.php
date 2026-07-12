<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MerchantSubscription>
 */
class MerchantSubscriptionFactory extends Factory
{
    protected $model = MerchantSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Create a plan and a price FOR THAT PLAN so the composite FK
        // (price_id, plan_id) → subscription_plan_prices(id, plan_id) holds.
        $plan = SubscriptionPlan::factory()->create();
        $price = SubscriptionPlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'billing_interval' => BillingInterval::Monthly,
        ]);

        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'plan_id' => $plan->id,
            'price_id' => $price->id,
            'status' => MerchantSubscriptionStatus::Trialing,
            'billing_interval' => $price->billing_interval,
            'trial_days_snapshot' => 14,
            'trial_started_at' => now(),
            'trial_ends_at' => now()->addDays(14),
            'current_period_start' => today(),
            'current_period_end' => today()->addMonth(),
            'high_value_payout_threshold_minor' => null,
            'cancelled_at' => null,
            'expired_at' => null,
        ];
    }

    public function status(MerchantSubscriptionStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    public function active(): static
    {
        return $this->status(MerchantSubscriptionStatus::Active);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MerchantSubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MerchantSubscriptionStatus::Expired,
            'expired_at' => now(),
        ]);
    }

    public function forMerchant(Merchant $merchant): static
    {
        return $this->state(fn (array $attributes): array => ['merchant_id' => $merchant->id]);
    }
}
