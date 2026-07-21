<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Compensation\Enums\PayoutItemStatus;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Hr\Models\StaffProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PersonnelPayoutItem>
 *
 * Default: a zero-value `draft` item on a fresh run, anchored so run + staff + item share one
 * merchant/branch and the run currency. `gross = salary + commission + adjustment` (all 0) satisfies
 * the DB CHECK.
 */
class PersonnelPayoutItemFactory extends Factory
{
    protected $model = PersonnelPayoutItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'payout_run_id' => PersonnelPayoutRun::factory(),
            'merchant_id' => fn (array $a) => PersonnelPayoutRun::query()->whereKey($a['payout_run_id'])->value('merchant_id'),
            'branch_id' => fn (array $a) => PersonnelPayoutRun::query()->whereKey($a['payout_run_id'])->value('branch_id'),
            'currency' => fn (array $a) => PersonnelPayoutRun::query()->whereKey($a['payout_run_id'])->value('currency'),
            'staff_profile_id' => fn (array $a) => StaffProfile::factory()->create([
                'merchant_id' => $a['merchant_id'],
                'primary_branch_id' => $a['branch_id'],
            ])->id,
            'salary_amount_minor' => 0,
            'commission_amount_minor' => 0,
            'adjustment_amount_minor' => 0,
            'gross_amount_minor' => 0,
            'source_ledger_refs' => ['salary' => [], 'commission' => [], 'adjustment' => []],
            'status' => PayoutItemStatus::Draft,
        ];
    }
}
