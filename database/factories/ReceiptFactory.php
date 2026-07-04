<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Receipts\Models\Receipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Receipt>
 *
 * Anchors on a validated payment_validation_event and mirrors its branch/merchant/
 * invoice/amount. An original receipt (no reissue_of_receipt_id).
 */
class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'payment_validation_event_id' => PaymentValidationEvent::factory(),
            'merchant_id' => fn (array $attributes) => PaymentValidationEvent::query()
                ->whereKey($attributes['payment_validation_event_id'])->value('merchant_id'),
            'branch_id' => fn (array $attributes) => PaymentValidationEvent::query()
                ->whereKey($attributes['payment_validation_event_id'])->value('branch_id'),
            'invoice_id' => fn (array $attributes) => PaymentValidationEvent::query()
                ->whereKey($attributes['payment_validation_event_id'])->value('invoice_id'),
            'receipt_number' => fn () => $this->faker->unique()->numberBetween(1, 1_000_000),
            'amount_minor' => 500000,
            'currency' => 'KES',
            'components' => [['method' => 'cash', 'amount_minor' => 500000]],
            'reissue_of_receipt_id' => null,
            'reason' => null,
            'file_id' => null,
            'file_generation_status' => 'pending',
            'issued_by' => null,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes): array => [
            'file_generation_status' => 'ready',
        ]);
    }
}
