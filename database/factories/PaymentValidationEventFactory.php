<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Payments\Enums\PaymentValidationDecision;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentValidationEvent>
 *
 * Anchors on a pending-validation group, derives its branch/merchant/invoice, and
 * defaults to a `validated` decision (validated_amount_minor = the group total).
 */
class PaymentValidationEventFactory extends Factory
{
    protected $model = PaymentValidationEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'payment_recording_group_id' => PaymentRecordingGroup::factory()->pendingValidation(),
            'merchant_id' => fn (array $attributes) => PaymentRecordingGroup::query()
                ->whereKey($attributes['payment_recording_group_id'])->value('merchant_id'),
            'branch_id' => fn (array $attributes) => PaymentRecordingGroup::query()
                ->whereKey($attributes['payment_recording_group_id'])->value('branch_id'),
            'invoice_id' => fn (array $attributes) => PaymentRecordingGroup::query()
                ->whereKey($attributes['payment_recording_group_id'])->value('invoice_id'),
            'checker_user_id' => User::factory(),
            'decision' => PaymentValidationDecision::Validated,
            'validated_amount_minor' => fn (array $attributes) => PaymentRecordingGroup::query()
                ->whereKey($attributes['payment_recording_group_id'])->value('total_amount_minor'),
            'reason' => null,
            'created_at' => CarbonImmutable::now(),
        ];
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'decision' => PaymentValidationDecision::Rejected,
            'validated_amount_minor' => null,
            'reason' => 'Reference did not match the bank statement.',
        ]);
    }

    public function correctionRequired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'decision' => PaymentValidationDecision::CorrectionRequired,
            'validated_amount_minor' => null,
            'reason' => 'Please re-enter the M-Pesa reference.',
        ]);
    }
}
