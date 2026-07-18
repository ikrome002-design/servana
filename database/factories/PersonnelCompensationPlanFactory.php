<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Enums\SalaryPeriod;
use App\Domain\Compensation\Enums\SuspensionSalaryPolicy;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Hr\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PersonnelCompensationPlan>
 *
 * Default: a valid DRAFT commission_only plan. Anchored on a branch, with the staff profile
 * and the commission rule both created INSIDE that branch/merchant, so every composite FK
 * (branch→merchant, staff_profile→merchant, commission_rule→merchant) holds.
 *
 * Configuration only — builds no salary accrual, earned commission, ledger, or payout row.
 */
class PersonnelCompensationPlanFactory extends Factory
{
    protected $model = PersonnelCompensationPlan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'merchant_id' => fn (array $attributes) => MerchantBranch::query()
                ->whereKey($attributes['branch_id'])->value('merchant_id'),
            'staff_profile_id' => fn (array $attributes) => StaffProfile::factory()->state([
                'merchant_id' => $attributes['merchant_id'],
                'primary_branch_id' => $attributes['branch_id'],
            ]),
            'compensation_model' => CompensationModel::CommissionOnly,
            'salary_amount_minor' => null,
            'salary_currency' => null,
            'salary_period' => null,
            'salary_payout_day' => null,
            'suspension_salary_policy' => SuspensionSalaryPolicy::Continue,
            'commission_rule_id' => fn (array $attributes) => CommissionRule::factory()->state([
                'merchant_id' => $attributes['merchant_id'],
                'branch_id' => $attributes['branch_id'],
            ]),
            'effective_from' => today(),
            'effective_to' => null,
            'status' => CompensationPlanStatus::Draft,
            'is_backdated' => false,
            'supersedes_plan_id' => null,
            'notes' => null,
            'change_reason' => 'Initial compensation plan.',
            'created_by' => User::factory(),
            'submitted_by' => null,
            'submitted_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
        ];
    }

    /** Commission only: no salary terms; a commission rule is required (F1). */
    public function commissionOnly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'compensation_model' => CompensationModel::CommissionOnly,
            'salary_amount_minor' => null,
            'salary_currency' => null,
            'salary_period' => null,
            'salary_payout_day' => null,
            'suspension_salary_policy' => SuspensionSalaryPolicy::Continue,
        ]);
    }

    /**
     * Salary only: salary terms required; NEVER a commission rule (F1 — Plan §80's named
     * "salary-only has no commission rule" invariant, enforced by the DB model-shape CHECK).
     */
    public function salaryOnly(int $amountMinor = 5000000, SalaryPeriod $period = SalaryPeriod::Monthly): static
    {
        return $this->state(fn (array $attributes): array => [
            'compensation_model' => CompensationModel::SalaryOnly,
            'salary_amount_minor' => $amountMinor,
            'salary_currency' => 'KES',
            'salary_period' => $period,
            'commission_rule_id' => null,
        ]);
    }

    /** Salary plus commission: salary terms AND a commission rule are both required (F1). */
    public function salaryPlusCommission(int $amountMinor = 3000000, SalaryPeriod $period = SalaryPeriod::Monthly): static
    {
        return $this->state(fn (array $attributes): array => [
            'compensation_model' => CompensationModel::SalaryPlusCommission,
            'salary_amount_minor' => $amountMinor,
            'salary_currency' => 'KES',
            'salary_period' => $period,
            'commission_rule_id' => $attributes['commission_rule_id'] ?? CommissionRule::factory()->state([
                'merchant_id' => $attributes['merchant_id'],
                'branch_id' => $attributes['branch_id'],
            ]),
        ]);
    }

    public function status(CompensationPlanStatus $status): static
    {
        return $this->state(function (array $attributes) use ($status): array {
            $state = ['status' => $status];

            $submitted = [
                CompensationPlanStatus::PendingApproval,
                CompensationPlanStatus::Scheduled,
                CompensationPlanStatus::Active,
                CompensationPlanStatus::Expired,
                CompensationPlanStatus::Superseded,
                CompensationPlanStatus::Rejected,
            ];

            // States reached through approval require recorded approval (DB approval/status
            // CHECK); the approver is never the submitter (DB maker/checker CHECK).
            $approved = [
                CompensationPlanStatus::Scheduled,
                CompensationPlanStatus::Active,
                CompensationPlanStatus::Expired,
                CompensationPlanStatus::Superseded,
            ];

            if (in_array($status, $submitted, true)) {
                $state['submitted_by'] = $attributes['submitted_by'] ?? User::factory();
                $state['submitted_at'] = $attributes['submitted_at'] ?? now();
            }

            if (in_array($status, $approved, true)) {
                $state['approved_by'] = $attributes['approved_by'] ?? User::factory();
                $state['approved_at'] = $attributes['approved_at'] ?? now();
            }

            if ($status === CompensationPlanStatus::Rejected) {
                $state['rejected_by'] = $attributes['rejected_by'] ?? User::factory();
                $state['rejected_at'] = $attributes['rejected_at'] ?? now();
            }

            return $state;
        });
    }

    public function draft(): static
    {
        return $this->status(CompensationPlanStatus::Draft);
    }

    public function pendingApproval(): static
    {
        return $this->status(CompensationPlanStatus::PendingApproval);
    }

    /** Approved with a FUTURE effective_from — participates in the overlap exclusion. */
    public function scheduled(): static
    {
        return $this->status(CompensationPlanStatus::Scheduled)
            ->state(fn (array $attributes): array => ['effective_from' => today()->addDays(7)]);
    }

    public function active(): static
    {
        return $this->status(CompensationPlanStatus::Active);
    }

    public function superseded(): static
    {
        return $this->status(CompensationPlanStatus::Superseded);
    }

    public function expired(): static
    {
        return $this->status(CompensationPlanStatus::Expired);
    }

    public function rejected(): static
    {
        return $this->status(CompensationPlanStatus::Rejected);
    }

    public function cancelled(): static
    {
        return $this->status(CompensationPlanStatus::Cancelled);
    }

    /** F8: effective_from before the current Africa/Nairobi business date. */
    public function backdated(int $daysBack = 30): static
    {
        return $this->state(fn (array $attributes): array => [
            'effective_from' => today()->subDays($daysBack),
            'is_backdated' => true,
        ]);
    }

    /** Bind the plan to an existing subject (keeps merchant/branch consistent). */
    public function forStaffProfile(StaffProfile $staffProfile): static
    {
        return $this->state(fn (array $attributes): array => [
            'staff_profile_id' => $staffProfile->id,
            'merchant_id' => $staffProfile->merchant_id,
            'branch_id' => $staffProfile->primary_branch_id,
        ]);
    }
}
