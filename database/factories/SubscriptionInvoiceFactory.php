<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Enums\WalletRegistrationStatus;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionInvoice>
 */
class SubscriptionInvoiceFactory extends Factory
{
    protected $model = SubscriptionInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plan = SubscriptionPlan::factory()->create();
        $price = SubscriptionPlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'billing_interval' => BillingInterval::Monthly,
            'amount_minor' => 500000,
            'currency' => 'KES',
        ]);

        $subtotal = (int) $price->amount_minor;

        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'plan_id' => $plan->id,
            'price_id' => $price->id,
            'invoice_number' => null,
            'period_start' => today(),
            'period_end' => today()->addMonth(),
            'subtotal_minor' => $subtotal,
            'discount_minor' => 0,
            'total_minor' => $subtotal,
            'currency' => 'KES',
            'balance_minor' => $subtotal,
            'status' => SubscriptionInvoiceStatus::Draft,
            // Phase 20B Wallet defaults (ADR-014) — no Wallet runtime writes these.
            'account_reference' => null,
            'wallet_payment_id' => null,
            'wallet_registration_status' => WalletRegistrationStatus::Unregistered,
            'wallet_registered_at' => null,
            'issued_at' => null,
            'due_at' => null,
        ];
    }

    public function issued(?string $invoiceNumber = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SubscriptionInvoiceStatus::Issued,
            'invoice_number' => $invoiceNumber ?? 'SUB-'.fake()->unique()->numberBetween(1, 999999),
            'issued_at' => now(),
            'due_at' => now()->addDays(7),
        ]);
    }

    public function overdue(): static
    {
        return $this->issued()->state(fn (array $attributes): array => [
            'status' => SubscriptionInvoiceStatus::Overdue,
        ]);
    }

    public function void(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SubscriptionInvoiceStatus::Void,
        ]);
    }

    public function forMerchant(Merchant $merchant): static
    {
        return $this->state(fn (array $attributes): array => ['merchant_id' => $merchant->id]);
    }
}
