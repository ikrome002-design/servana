<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Compensation\Enums\CommissionAppliesTo;
use App\Domain\Compensation\Enums\CommissionCalculationBasis;
use App\Domain\Compensation\Enums\CommissionCalculationType;
use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Models\CommissionRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommissionRule>
 *
 * Default: a valid DRAFT percentage rule over all services, anchored on a branch so
 * merchant_id always agrees with the parent branch (the composite FK). Configuration only —
 * builds no earned commission, ledger, or payout row.
 */
class CommissionRuleFactory extends Factory
{
    protected $model = CommissionRule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'merchant_id' => fn (array $attributes) => MerchantBranch::query()
                ->whereKey($attributes['branch_id'])->value('merchant_id'),
            'calculation_type' => CommissionCalculationType::Percentage,
            'percentage_basis_points' => 1000, // 10.00%
            'fixed_amount_minor' => null,
            'currency' => null,
            'calculation_basis' => CommissionCalculationBasis::ServicePrice,
            'applies_to' => CommissionAppliesTo::AllServices,
            'service_category_id' => null,
            'applies_to_preferred_personnel_fee' => false,
            'effective_from' => today(),
            'effective_to' => null,
            'status' => CommissionRuleStatus::Draft,
            'notes' => null,
            'change_reason' => 'Initial commission rule.',
            'created_by' => User::factory(),
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    public function percentage(int $basisPoints = 1000): static
    {
        return $this->state(fn (array $attributes): array => [
            'calculation_type' => CommissionCalculationType::Percentage,
            'percentage_basis_points' => $basisPoints,
            'fixed_amount_minor' => null,
            'currency' => null,
        ]);
    }

    public function fixedAmount(int $amountMinor = 50000, string $currency = 'KES'): static
    {
        return $this->state(fn (array $attributes): array => [
            'calculation_type' => CommissionCalculationType::FixedAmount,
            'fixed_amount_minor' => $amountMinor,
            'currency' => $currency,
            'percentage_basis_points' => null,
        ]);
    }

    public function basis(CommissionCalculationBasis $basis): static
    {
        return $this->state(fn (array $attributes): array => ['calculation_basis' => $basis]);
    }

    public function allServices(): static
    {
        return $this->state(fn (array $attributes): array => [
            'applies_to' => CommissionAppliesTo::AllServices,
            'service_category_id' => null,
        ]);
    }

    public function selectedServices(): static
    {
        return $this->state(fn (array $attributes): array => [
            'applies_to' => CommissionAppliesTo::SelectedServices,
            'service_category_id' => null,
        ]);
    }

    /** Category-scoped applicability; the category is created inside the rule's own branch. */
    public function serviceCategory(): static
    {
        return $this->state(fn (array $attributes): array => [
            'applies_to' => CommissionAppliesTo::ServiceCategory,
            'service_category_id' => ServiceCategory::factory()->state([
                'merchant_id' => $attributes['merchant_id'],
                'branch_id' => $attributes['branch_id'],
            ]),
        ]);
    }

    /** F6: include the Phase 20A preferred-personnel fee in the FUTURE commission basis. */
    public function includingPreferredPersonnelFee(): static
    {
        return $this->state(fn (array $attributes): array => [
            'applies_to_preferred_personnel_fee' => true,
        ]);
    }

    public function status(CommissionRuleStatus $status): static
    {
        return $this->state(function (array $attributes) use ($status): array {
            $state = ['status' => $status];

            // Only states reached THROUGH approval carry approval metadata; draft/pending are
            // unapproved, and rejected/cancelled never were approved.
            $approved = [
                CommissionRuleStatus::Scheduled,
                CommissionRuleStatus::Active,
                CommissionRuleStatus::Superseded,
                CommissionRuleStatus::Expired,
            ];

            if (in_array($status, $approved, true)) {
                $state['approved_by'] = $attributes['approved_by'] ?? User::factory();
                $state['approved_at'] = $attributes['approved_at'] ?? now();
            }

            return $state;
        });
    }

    public function draft(): static
    {
        return $this->status(CommissionRuleStatus::Draft);
    }

    public function pendingApproval(): static
    {
        return $this->status(CommissionRuleStatus::PendingApproval);
    }

    public function scheduled(): static
    {
        return $this->status(CommissionRuleStatus::Scheduled)
            ->state(fn (array $attributes): array => ['effective_from' => today()->addDays(7)]);
    }

    public function active(): static
    {
        return $this->status(CommissionRuleStatus::Active);
    }

    public function superseded(): static
    {
        return $this->status(CommissionRuleStatus::Superseded);
    }

    public function expired(): static
    {
        return $this->status(CommissionRuleStatus::Expired);
    }

    public function rejected(): static
    {
        return $this->status(CommissionRuleStatus::Rejected);
    }

    public function cancelled(): static
    {
        return $this->status(CommissionRuleStatus::Cancelled);
    }
}
