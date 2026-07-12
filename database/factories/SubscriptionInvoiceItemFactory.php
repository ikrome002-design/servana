<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\SubscriptionInvoiceItemType;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionInvoiceItem>
 */
class SubscriptionInvoiceItemFactory extends Factory
{
    protected $model = SubscriptionInvoiceItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Create the parent invoice and inherit its merchant so the composite FK
        // (subscription_invoice_id, merchant_id) → subscription_invoices(id, merchant_id) holds.
        $invoice = SubscriptionInvoice::factory()->create();

        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => $invoice->merchant_id,
            'subscription_invoice_id' => $invoice->id,
            'description' => 'Subscription plan fee',
            'amount_minor' => 500000,
            'type' => SubscriptionInvoiceItemType::PlanFee,
        ];
    }

    public function forInvoice(SubscriptionInvoice $invoice): static
    {
        return $this->state(fn (array $attributes): array => [
            'merchant_id' => $invoice->merchant_id,
            'subscription_invoice_id' => $invoice->id,
        ]);
    }

    public function type(SubscriptionInvoiceItemType $type): static
    {
        return $this->state(fn (array $attributes): array => ['type' => $type]);
    }
}
