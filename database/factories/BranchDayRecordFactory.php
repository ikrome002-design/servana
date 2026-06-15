<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\MerchantBranch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BranchDayRecord>
 */
class BranchDayRecordFactory extends Factory
{
    protected $model = BranchDayRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'business_date' => now()->toDateString(),
            'status' => BranchDayStatus::Closed,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BranchDayStatus::Open,
            'opened_at' => now(),
        ]);
    }
}
