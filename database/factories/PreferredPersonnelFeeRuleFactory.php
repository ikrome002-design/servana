<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\Enums\PreferredFeeCalculationBasis;
use App\Domain\Billing\Enums\PreferredFeeCalculationType;
use App\Domain\Billing\Enums\PreferredFeeScope;
use App\Domain\Billing\Enums\PreferredPersonnelFeeRuleStatus;
use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Catalogue\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PreferredPersonnelFeeRule>
 *
 * Defaults to a platform-default, fixed-amount, ACTIVE rule. `percentage()`, `service()`,
 * `draft()`, and `scheduled()` states produce constraint-valid variants.
 */
class PreferredPersonnelFeeRuleFactory extends Factory
{
    protected $model = PreferredPersonnelFeeRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'calculation_type' => PreferredFeeCalculationType::FixedAmount,
            'fixed_amount_minor' => fake()->numberBetween(5000, 100000),
            'percentage_basis_points' => null,
            'currency' => 'KES',
            'calculation_basis' => PreferredFeeCalculationBasis::ServiceItemNetAmount,
            'scope' => PreferredFeeScope::PlatformDefault,
            'service_id' => null,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'status' => PreferredPersonnelFeeRuleStatus::Active,
            'created_by' => User::factory(),
            'approved_by' => User::factory(),
            'approved_at' => now(),
            'change_reason' => 'factory rule',
        ];
    }

    public function percentage(int $basisPoints = 500): static
    {
        return $this->state(fn (array $attributes): array => [
            'calculation_type' => PreferredFeeCalculationType::Percentage,
            'percentage_basis_points' => $basisPoints,
            'fixed_amount_minor' => null,
            'currency' => null,
        ]);
    }

    public function service(?Service $service = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => PreferredFeeScope::Service,
            'service_id' => $service instanceof Service ? $service->id : Service::factory(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PreferredPersonnelFeeRuleStatus::Draft,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PreferredPersonnelFeeRuleStatus::Scheduled,
        ]);
    }
}
