<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\MerchantBranch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BranchCashUp>
 */
class BranchCashUpFactory extends Factory
{
    protected $model = BranchCashUp::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'expected_total' => 0,
            'cash_counted' => 0,
            'discrepancy_amount' => 0,
            'status' => CashUpStatus::Approved,
        ];
    }

    /** An unresolved discrepancy (submitted, non-zero) blocks branch closure. */
    public function unresolvedDiscrepancy(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CashUpStatus::Submitted,
            'discrepancy_amount' => 5000,
            'discrepancy_note' => 'Till short',
        ]);
    }
}
