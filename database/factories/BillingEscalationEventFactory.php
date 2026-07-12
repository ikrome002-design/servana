<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\BillingEscalationEventType;
use App\Domain\Billing\Models\BillingEscalationEvent;
use App\Domain\Billing\Models\MerchantSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BillingEscalationEvent>
 */
class BillingEscalationEventFactory extends Factory
{
    protected $model = BillingEscalationEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subscription = MerchantSubscription::factory()->create();

        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => $subscription->merchant_id,
            'subscription_invoice_id' => null,
            'merchant_subscription_id' => $subscription->id,
            'event_type' => BillingEscalationEventType::Reminder,
            'from_billing_status' => null,
            'to_billing_status' => null,
            'reason' => null,
            'period_boundary' => $subscription->current_period_end,
        ];
    }

    public function forSubscription(MerchantSubscription $subscription): static
    {
        return $this->state(fn (array $attributes): array => [
            'merchant_id' => $subscription->merchant_id,
            'merchant_subscription_id' => $subscription->id,
            'period_boundary' => $subscription->current_period_end,
        ]);
    }

    public function type(BillingEscalationEventType $type): static
    {
        return $this->state(fn (array $attributes): array => ['event_type' => $type]);
    }
}
