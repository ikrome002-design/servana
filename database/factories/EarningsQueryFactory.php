<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\EarningsQueryAssignedRole;
use App\Domain\Compensation\Enums\EarningsQueryStatus;
use App\Domain\Compensation\Enums\EarningsQuerySubjectType;
use App\Domain\Compensation\Enums\EarningsQueryType;
use App\Domain\Compensation\Models\EarningsQuery;
use App\Domain\Hr\Models\StaffProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EarningsQuery>
 *
 * Default: an `open` commission-disagreement query routed to Finance, anchored on one branch so the
 * composite FKs agree on the merchant. `subject_id` is a raw id (no FK — the action validates
 * in-scope ownership).
 */
class EarningsQueryFactory extends Factory
{
    protected $model = EarningsQuery::class;

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
            'subject_type' => EarningsQuerySubjectType::CommissionLedger,
            'subject_id' => 1,
            'query_type' => EarningsQueryType::CommissionDisagreement,
            'body' => 'I believe my commission for this service was calculated incorrectly.',
            'status' => EarningsQueryStatus::Open,
            'assigned_role' => EarningsQueryAssignedRole::Finance,
            'assigned_to' => null,
            'resolution_note' => null,
            'resolved_adjustment_id' => null,
            'responded_by' => null,
            'responded_at' => null,
        ];
    }
}
