<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PersonnelPayoutRun>
 *
 * Default: a KES `draft` run for July 2026, anchored on one branch so the composite (branch,
 * merchant) FK agrees. No threshold snapshot (high-value gate inactive by default).
 */
class PersonnelPayoutRunFactory extends Factory
{
    protected $model = PersonnelPayoutRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'merchant_id' => fn (array $a) => MerchantBranch::query()->whereKey($a['branch_id'])->value('merchant_id'),
            'period_start' => CarbonImmutable::parse('2026-07-01')->toDateString(),
            'period_end' => CarbonImmutable::parse('2026-07-31')->toDateString(),
            'currency' => 'KES',
            'high_value_threshold_snapshot_minor' => null,
            'status' => PayoutRunStatus::Draft,
            'gross_total_minor' => 0,
            'created_by' => User::factory(),
            'submitted_by' => null,
            'verified_by' => null,
            'approved_by' => null,
            'paid_by' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
            'external_payment_reference_encrypted' => null,
            'paid_at' => null,
        ];
    }
}
