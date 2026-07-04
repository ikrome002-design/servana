<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Compensation\Enums\CommissionHandoffKind;
use App\Domain\Compensation\Models\CommissionHandoffEvent;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentValidationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommissionHandoffEvent>
 *
 * Defaults to a validated_allocation seam anchored on a payment_record + validation
 * event. NOT a commission ledger — carries no rate.
 */
class CommissionHandoffEventFactory extends Factory
{
    protected $model = CommissionHandoffEvent::class;

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
            'kind' => CommissionHandoffKind::ValidatedAllocation,
            'payment_validation_event_id' => PaymentValidationEvent::factory(),
            'refund_id' => null,
            'invoice_item_id' => null,
            'service_id' => null,
            'staff_profile_id' => null,
            'amount_minor' => 500000,
            'currency' => 'KES',
            'effective_at' => CarbonImmutable::now(),
            'consumed_at' => null,
            'created_at' => CarbonImmutable::now(),
        ];
    }
}
