<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\CompensationAdjustmentType;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Hr\Models\StaffProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompensationAdjustment>
 *
 * Default: a valid Finance MANUAL adjustment anchored on one branch. `amount_minor` is non-zero.
 */
class CompensationAdjustmentFactory extends Factory
{
    protected $model = CompensationAdjustment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'merchant_id' => fn (array $a) => MerchantBranch::query()->whereKey($a['branch_id'])->value('merchant_id'),
            'staff_profile_id' => fn (array $a) => StaffProfile::factory()->create([
                'merchant_id' => $a['merchant_id'],
                'primary_branch_id' => $a['branch_id'],
            ])->id,
            'adjustment_type' => CompensationAdjustmentType::Manual,
            'amount_minor' => 100000,
            'currency' => 'KES',
            'reason' => 'Manual compensation adjustment.',
            'source_commission_ledger_id' => null,
            'source_salary_ledger_id' => null,
            'created_by' => User::factory(),
            'approved_by' => User::factory(),
            'payout_item_id' => null,
            'created_at' => CarbonImmutable::now(),
        ];
    }
}
