<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Models\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Refund>
 *
 * Anchors on a payment_record (the refunded component) and mirrors its
 * branch/merchant/invoice/currency. Defaults to a `requested` refund.
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'payment_record_id' => PaymentRecord::factory(),
            'merchant_id' => fn (array $attributes) => PaymentRecord::query()
                ->whereKey($attributes['payment_record_id'])->value('merchant_id'),
            'branch_id' => fn (array $attributes) => PaymentRecord::query()
                ->whereKey($attributes['payment_record_id'])->value('branch_id'),
            'invoice_id' => fn (array $attributes) => PaymentRecord::query()
                ->whereKey($attributes['payment_record_id'])->value('invoice_id'),
            'refund_group_ulid' => (string) Str::ulid(),
            'amount_minor' => 100000,
            'currency' => 'KES',
            'method' => PaymentMethod::Cash,
            'external_reference_encrypted' => null,
            'reason' => 'Client returned the service.',
            'status' => RefundStatus::Requested,
            'requested_by' => User::factory(),
            'approved_by' => null,
            'finalized_by' => null,
            'rejected_by' => null,
            'approved_at' => null,
            'finalized_at' => null,
            'rejected_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RefundStatus::Approved,
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ]);
    }
}
