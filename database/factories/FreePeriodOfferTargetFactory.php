<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\PromotionTargetType;
use App\Domain\Billing\Models\FreePeriodOffer;
use App\Domain\Billing\Models\FreePeriodOfferTarget;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FreePeriodOfferTarget>
 */
class FreePeriodOfferTargetFactory extends Factory
{
    protected $model = FreePeriodOfferTarget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'free_period_offer_id' => FreePeriodOffer::factory(),
            'target_type' => PromotionTargetType::Merchant,
            'merchant_id' => Merchant::factory(),
            'subscription_plan_id' => null,
            'billing_mode' => null,
        ];
    }

    public function forMerchant(Merchant $merchant): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_type' => PromotionTargetType::Merchant,
            'merchant_id' => $merchant->id,
            'subscription_plan_id' => null,
            'billing_mode' => null,
        ]);
    }

    public function forPlan(SubscriptionPlan $plan): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_type' => PromotionTargetType::Plan,
            'merchant_id' => null,
            'subscription_plan_id' => $plan->id,
            'billing_mode' => null,
        ]);
    }

    public function forBillingMode(BillingMode $mode = BillingMode::FixedAmount): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_type' => PromotionTargetType::BillingMode,
            'merchant_id' => null,
            'subscription_plan_id' => null,
            'billing_mode' => $mode,
        ]);
    }
}
