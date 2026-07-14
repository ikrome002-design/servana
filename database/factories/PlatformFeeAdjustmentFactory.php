<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\PlatformFeeAdjustmentType;
use App\Domain\Billing\Models\PlatformFeeAdjustment;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformFeeAdjustment>
 */
class PlatformFeeAdjustmentFactory extends Factory
{
    protected $model = PlatformFeeAdjustment::class;

    /**
     * Default: a reversal adjustment (negative amount) over an earned ledger entry.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $entry = PlatformFeeLedgerEntry::factory();

        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'branch_id' => null,
            'platform_fee_ledger_entry_id' => $entry,
            'adjustment_type' => PlatformFeeAdjustmentType::Reversal,
            'amount_minor' => -250,
            'currency' => 'KES',
            'reason' => 'Merchant-client invoice voided.',
            'source_reference' => 'invoice_void:'.Str::ulid(),
            'effective_date' => today(),
            'created_by' => User::factory(),
            'approved_by' => null,
            'idempotency_key' => 'adjustment:'.Str::ulid(),
        ];
    }

    public function forEntry(PlatformFeeLedgerEntry $entry): static
    {
        return $this->state(fn (array $attributes): array => [
            'platform_fee_ledger_entry_id' => $entry->id,
            'merchant_id' => $entry->merchant_id,
            'branch_id' => $entry->branch_id,
            'currency' => $entry->currency,
        ]);
    }

    public function reversal(int $amountMinor = -250): static
    {
        return $this->state(fn (array $attributes): array => [
            'adjustment_type' => PlatformFeeAdjustmentType::Reversal,
            'amount_minor' => -abs($amountMinor),
        ]);
    }

    public function partialRefund(int $amountMinor = -100): static
    {
        return $this->state(fn (array $attributes): array => [
            'adjustment_type' => PlatformFeeAdjustmentType::PartialRefund,
            'amount_minor' => -abs($amountMinor),
        ]);
    }

    public function correction(int $amountMinor = 50): static
    {
        return $this->state(fn (array $attributes): array => [
            'adjustment_type' => PlatformFeeAdjustmentType::Correction,
            'amount_minor' => $amountMinor,
        ]);
    }

    public function disputeResolution(int $amountMinor = -50): static
    {
        return $this->state(fn (array $attributes): array => [
            'adjustment_type' => PlatformFeeAdjustmentType::DisputeResolution,
            'amount_minor' => $amountMinor,
        ]);
    }
}
