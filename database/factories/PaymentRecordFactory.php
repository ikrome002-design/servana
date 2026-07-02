<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentRecord>
 *
 * Anchors on a recording group and inherits its merchant/branch/invoice/maker so
 * every consistency FK holds. Defaults to a `cash` component (no reference) at
 * `pending_validation`.
 */
class PaymentRecordFactory extends Factory
{
    protected $model = PaymentRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $group = fn (array $attributes) => PaymentRecordingGroup::query()
            ->whereKey($attributes['payment_recording_group_id'])->firstOrFail();

        return [
            'ulid' => (string) Str::ulid(),
            'payment_recording_group_id' => PaymentRecordingGroup::factory(),
            'merchant_id' => fn (array $attributes) => $group($attributes)->merchant_id,
            'branch_id' => fn (array $attributes) => $group($attributes)->branch_id,
            'invoice_id' => fn (array $attributes) => $group($attributes)->invoice_id,
            'recorded_by' => fn (array $attributes) => $group($attributes)->maker_user_id,
            'maker_user_id' => fn (array $attributes) => $group($attributes)->maker_user_id,
            'payer_client_id' => null,
            'method' => PaymentMethod::Cash,
            'amount_minor' => 500000,
            'currency' => 'KES',
            'reference_normalized' => null,
            'reference_display_encrypted' => null,
            'paid_at' => CarbonImmutable::now(),
            'status' => PaymentRecordStatus::PendingValidation,
            'validated_amount_minor' => null,
        ];
    }

    /** A referenced non-cash component (offline M-Pesa by default). */
    public function referenced(PaymentMethod $method = PaymentMethod::MpesaOffline, string $reference = 'QGX7YT1ABC'): static
    {
        return $this->state(fn (array $attributes): array => [
            'method' => $method,
            'reference_normalized' => strtoupper($reference),
            'reference_display_encrypted' => $reference,
        ]);
    }
}
