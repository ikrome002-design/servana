<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Enums\SalaryLedgerEntryType;
use App\Domain\Compensation\Enums\SalaryLedgerStatus;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Hr\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SalaryLedgerEntry>
 *
 * Default: a valid ACCRUAL row for a full monthly segment, anchored on one branch so the
 * composite FKs agree on the merchant. Configuration is salary_only.
 */
class SalaryLedgerEntryFactory extends Factory
{
    protected $model = SalaryLedgerEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $start = CarbonImmutable::parse('2026-07-01');
        $end = CarbonImmutable::parse('2026-07-31');

        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'merchant_id' => fn (array $a) => MerchantBranch::query()->whereKey($a['branch_id'])->value('merchant_id'),
            'staff_profile_id' => fn (array $a) => StaffProfile::factory()->create([
                'merchant_id' => $a['merchant_id'],
                'primary_branch_id' => $a['branch_id'],
            ])->id,
            'compensation_plan_id' => fn (array $a) => PersonnelCompensationPlan::factory()->create([
                'merchant_id' => $a['merchant_id'],
                'branch_id' => $a['branch_id'],
                'staff_profile_id' => $a['staff_profile_id'],
                'commission_rule_id' => null,
                'compensation_model' => CompensationModel::SalaryOnly,
                'salary_amount_minor' => 5000000,
                'salary_currency' => 'KES',
                'salary_period' => 'monthly',
            ])->id,
            'pay_period_start' => $start->toDateString(),
            'pay_period_end' => $end->toDateString(),
            'pay_period_segment_key' => fn (array $a) => 'monthly:2026-07:'.$a['compensation_plan_id'].':2026-07-01_2026-07-31',
            'amount_minor' => 5000000,
            'currency' => 'KES',
            'source_entry_id' => null,
            'entry_type' => SalaryLedgerEntryType::Accrual,
            'status' => SalaryLedgerStatus::Pending,
            'payout_item_id' => null,
            'created_by' => null,
            'approved_by' => null,
            'created_at' => CarbonImmutable::now(),
        ];
    }
}
