<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentRecordingGroup>
 *
 * Anchors on a branch, derives its merchant, and creates a same-branch issued
 * invoice so the composite consistency FKs hold. Defaults to a `recorded` group
 * (recorded_at set, not yet submitted — coherent with the timestamp CHECKs).
 */
class PaymentRecordingGroupFactory extends Factory
{
    protected $model = PaymentRecordingGroup::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'merchant_id' => fn (array $attributes) => MerchantBranch::query()
                ->whereKey($attributes['branch_id'])->value('merchant_id'),
            'invoice_id' => fn (array $attributes) => Invoice::factory()->issued()->create([
                'branch_id' => $attributes['branch_id'],
                'merchant_id' => $attributes['merchant_id'],
            ])->id,
            'maker_user_id' => User::factory(),
            'total_amount_minor' => 500000,
            'currency' => 'KES',
            'idempotency_key_id' => null,
            'status' => PaymentRecordingGroupStatus::Recorded,
            'recorded_at' => CarbonImmutable::now(),
            'submitted_for_validation_at' => null,
            'validated_at' => null,
            'rejected_at' => null,
        ];
    }

    /** A group submitted for Finance validation (Phase-18A success terminal). */
    public function pendingValidation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentRecordingGroupStatus::PendingValidation,
            'submitted_for_validation_at' => CarbonImmutable::now(),
        ]);
    }
}
