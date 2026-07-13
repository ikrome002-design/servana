<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Enums\PlatformFeeBasisType;
use App\Domain\Billing\Enums\PlatformFeeEntryType;
use App\Domain\Billing\Enums\PlatformFeeLedgerStatus;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformFeeLedgerEntry>
 */
class PlatformFeeLedgerEntryFactory extends Factory
{
    protected $model = PlatformFeeLedgerEntry::class;

    /**
     * Default: an earned/pending customer-centric entry (gross 250 on a 10 000 basis @ 2.50%).
     * customer_centric ⇒ shifted 0, absorbed = gross, liability = gross.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gross = 250;

        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'branch_id' => null,
            'source_invoice_id' => Invoice::factory(),
            'source_invoice_item_id' => null,
            'entry_type' => PlatformFeeEntryType::Earned,
            'status' => PlatformFeeLedgerStatus::Pending,
            'billing_mode_snapshot' => BillingMode::PercentageOnMerchantClientInvoice,
            'service_fee_tier_snapshot' => CanonicalPlatformFeeTier::CustomerCentric,
            'fee_basis_type' => PlatformFeeBasisType::MerchantClientInvoiceServiceSubtotal,
            'fee_basis_amount_minor' => 10000,
            'percentage_rate_snapshot' => 250,
            'shared_split_snapshot' => null,
            'gross_platform_fee_minor' => $gross,
            'client_shifted_amount_minor' => 0,
            'merchant_absorbed_amount_minor' => $gross,
            'merchant_liability_minor' => $gross,
            'currency' => 'KES',
            'effective_configuration_id' => PlatformFeeConfiguration::factory(),
            'subscription_invoice_item_id' => null,
            'reversed_entry_id' => null,
            'idempotency_key' => 'earned:'.Str::ulid(),
            'billable_at' => now(),
            'created_at' => now(),
        ];
    }

    public function customerCentric(): static
    {
        return $this->state(function (array $attributes): array {
            $gross = $attributes['gross_platform_fee_minor'];

            return [
                'service_fee_tier_snapshot' => CanonicalPlatformFeeTier::CustomerCentric,
                'shared_split_snapshot' => null,
                'client_shifted_amount_minor' => 0,
                'merchant_absorbed_amount_minor' => $gross,
                'merchant_liability_minor' => $gross,
            ];
        });
    }

    public function shared(int $splitBasisPoints = 5000): static
    {
        return $this->state(function (array $attributes) use ($splitBasisPoints): array {
            $gross = $attributes['gross_platform_fee_minor'];
            $shifted = intdiv($gross * $splitBasisPoints + 5000, 10000); // round-half-up

            return [
                'service_fee_tier_snapshot' => CanonicalPlatformFeeTier::Shared,
                'shared_split_snapshot' => $splitBasisPoints,
                'client_shifted_amount_minor' => $shifted,
                'merchant_absorbed_amount_minor' => $gross - $shifted,
                'merchant_liability_minor' => $gross,
            ];
        });
    }

    public function businessCentric(): static
    {
        return $this->state(function (array $attributes): array {
            $gross = $attributes['gross_platform_fee_minor'];

            return [
                'service_fee_tier_snapshot' => CanonicalPlatformFeeTier::BusinessCentric,
                'shared_split_snapshot' => null,
                'client_shifted_amount_minor' => $gross,
                'merchant_absorbed_amount_minor' => 0,
                'merchant_liability_minor' => $gross,
            ];
        });
    }

    public function status(PlatformFeeLedgerStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    public function pending(): static
    {
        return $this->status(PlatformFeeLedgerStatus::Pending);
    }

    public function aggregated(): static
    {
        return $this->status(PlatformFeeLedgerStatus::Aggregated);
    }

    public function invoiced(): static
    {
        return $this->status(PlatformFeeLedgerStatus::Invoiced);
    }

    /** A reversal row referencing an original earned entry. */
    public function reversalOf(PlatformFeeLedgerEntry $original): static
    {
        return $this->state(fn (array $attributes): array => [
            'entry_type' => PlatformFeeEntryType::Reversal,
            'merchant_id' => $original->merchant_id,
            'branch_id' => $original->branch_id,
            'source_invoice_id' => $original->source_invoice_id,
            'reversed_entry_id' => $original->id,
            'idempotency_key' => 'reversal:'.Str::ulid(),
        ]);
    }
}
