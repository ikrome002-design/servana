<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Enums\PlatformFeeBasisType;
use App\Domain\Billing\Enums\PlatformFeeConfigurationStatus;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformFeeConfiguration>
 */
class PlatformFeeConfigurationFactory extends Factory
{
    protected $model = PlatformFeeConfiguration::class;

    /**
     * Default: a valid percentage configuration, customer-centric tier (no split).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'billing_mode' => BillingMode::PercentageOnMerchantClientInvoice,
            'percentage_basis_points' => 250, // 2.50%
            'fixed_component_minor' => null,
            'tier_behavior' => CanonicalPlatformFeeTier::CustomerCentric,
            'shared_split_basis_points' => null,
            'fee_basis_type' => PlatformFeeBasisType::MerchantClientInvoiceServiceSubtotal,
            'currency' => 'KES',
            'effective_from' => today(),
            'effective_to' => null,
            'status' => PlatformFeeConfigurationStatus::Draft,
            'created_by' => User::factory(),
            'approved_by' => null,
            'approved_at' => null,
            'change_reason' => 'Initial percentage fee configuration.',
        ];
    }

    public function fixedAmount(): static
    {
        return $this->state(fn (array $attributes): array => [
            'billing_mode' => BillingMode::FixedAmount,
            'percentage_basis_points' => null,
            'fixed_component_minor' => null,
            'tier_behavior' => null,
            'shared_split_basis_points' => null,
            'fee_basis_type' => null,
        ]);
    }

    public function percentage(int $basisPoints = 250): static
    {
        return $this->state(fn (array $attributes): array => [
            'billing_mode' => BillingMode::PercentageOnMerchantClientInvoice,
            'percentage_basis_points' => $basisPoints,
            'fixed_component_minor' => null,
        ]);
    }

    public function fixedPlusPercentage(int $basisPoints = 250, int $fixedMinor = 100000): static
    {
        return $this->state(fn (array $attributes): array => [
            'billing_mode' => BillingMode::FixedAmountPlusPercentageOnMerchantClientInvoice,
            'percentage_basis_points' => $basisPoints,
            'fixed_component_minor' => $fixedMinor,
        ]);
    }

    public function customerCentric(): static
    {
        return $this->state(fn (array $attributes): array => [
            'tier_behavior' => CanonicalPlatformFeeTier::CustomerCentric,
            'shared_split_basis_points' => null,
        ]);
    }

    public function shared(int $splitBasisPoints = 5000): static
    {
        return $this->state(fn (array $attributes): array => [
            'tier_behavior' => CanonicalPlatformFeeTier::Shared,
            'shared_split_basis_points' => $splitBasisPoints,
        ]);
    }

    public function businessCentric(): static
    {
        return $this->state(fn (array $attributes): array => [
            'tier_behavior' => CanonicalPlatformFeeTier::BusinessCentric,
            'shared_split_basis_points' => null,
        ]);
    }

    public function basis(PlatformFeeBasisType $basis): static
    {
        return $this->state(fn (array $attributes): array => ['fee_basis_type' => $basis]);
    }

    public function status(PlatformFeeConfigurationStatus $status): static
    {
        return $this->state(function (array $attributes) use ($status): array {
            $state = ['status' => $status];

            if ($status->requiresApproval()) {
                $state['approved_by'] = $attributes['approved_by'] ?? User::factory();
                $state['approved_at'] = $attributes['approved_at'] ?? now();
            }

            return $state;
        });
    }

    public function draft(): static
    {
        return $this->status(PlatformFeeConfigurationStatus::Draft);
    }

    public function scheduled(): static
    {
        return $this->status(PlatformFeeConfigurationStatus::Scheduled)
            ->state(fn (array $attributes): array => ['effective_from' => today()->addDays(7)]);
    }

    public function active(): static
    {
        return $this->status(PlatformFeeConfigurationStatus::Active);
    }

    public function superseded(): static
    {
        return $this->status(PlatformFeeConfigurationStatus::Superseded);
    }

    public function cancelled(): static
    {
        return $this->status(PlatformFeeConfigurationStatus::Cancelled);
    }
}
