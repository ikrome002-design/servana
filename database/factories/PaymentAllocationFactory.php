<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Payments\Models\PaymentRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 *
 * Anchors on a payment record and inherits its merchant/branch/invoice; the
 * allocation amount defaults to the whole component amount (invoice-level).
 */
class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $record = fn (array $attributes) => PaymentRecord::query()
            ->whereKey($attributes['payment_record_id'])->firstOrFail();

        return [
            'payment_record_id' => PaymentRecord::factory(),
            'merchant_id' => fn (array $attributes) => $record($attributes)->merchant_id,
            'branch_id' => fn (array $attributes) => $record($attributes)->branch_id,
            'invoice_id' => fn (array $attributes) => $record($attributes)->invoice_id,
            'invoice_item_id' => null,
            'amount_minor' => fn (array $attributes) => $record($attributes)->amount_minor,
        ];
    }
}
