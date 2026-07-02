<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Payments\Enums\PaymentReferenceCheckResult;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentReferenceCheck>
 *
 * Anchors on a referenced payment record and inherits its merchant/branch/method.
 * Defaults to a clean `unique` reservation.
 */
class PaymentReferenceCheckFactory extends Factory
{
    protected $model = PaymentReferenceCheck::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $record = fn (array $attributes) => PaymentRecord::query()
            ->whereKey($attributes['payment_record_id'])->firstOrFail();

        return [
            'ulid' => (string) Str::ulid(),
            'payment_record_id' => PaymentRecord::factory()->referenced(),
            'merchant_id' => fn (array $attributes) => $record($attributes)->merchant_id,
            'branch_id' => fn (array $attributes) => $record($attributes)->branch_id,
            'method' => fn (array $attributes) => $record($attributes)->method,
            'reference_normalized' => fn (array $attributes) => $record($attributes)->reference_normalized ?? strtoupper(Str::random(10)),
            'result' => PaymentReferenceCheckResult::Unique,
            'matched_payment_record_id' => null,
            'checked_at' => CarbonImmutable::now(),
            'override_by' => null,
            'override_reason' => null,
        ];
    }
}
