<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Compensation\Enums\CommissionAppliesTo;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CommissionRuleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommissionRuleService>
 *
 * Default: a valid membership on a DRAFT selected-services rule (the guard trigger only permits
 * inserts while the rule is draft, and requires rule.branch = service.branch = membership.branch).
 */
class CommissionRuleServiceFactory extends Factory
{
    protected $model = CommissionRuleService::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'merchant_id' => fn (array $a) => MerchantBranch::query()->whereKey($a['branch_id'])->value('merchant_id'),
            'commission_rule_id' => fn (array $a) => CommissionRule::factory()->create([
                'merchant_id' => $a['merchant_id'],
                'branch_id' => $a['branch_id'],
                'applies_to' => CommissionAppliesTo::SelectedServices,
                'service_category_id' => null,
            ])->id,
            'service_id' => fn (array $a) => Service::factory()->create([
                'merchant_id' => $a['merchant_id'],
                'branch_id' => $a['branch_id'],
            ])->id,
            'created_at' => CarbonImmutable::now(),
        ];
    }
}
