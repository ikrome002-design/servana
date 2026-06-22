<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Enums\BranchUserAssignmentStatus;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\MerchantUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BranchUserAssignment>
 */
class BranchUserAssignmentFactory extends Factory
{
    protected $model = BranchUserAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $branch = MerchantBranch::factory();

        return [
            'ulid' => (string) Str::ulid(),
            'merchant_user_id' => MerchantUser::factory(),
            'branch_id' => $branch,
            // Derive merchant_id from the branch so the composite consistency FK holds.
            'merchant_id' => fn (array $attributes) => MerchantBranch::query()
                ->whereKey($attributes['branch_id'])->value('merchant_id'),
            'status' => BranchUserAssignmentStatus::Active,
            'assigned_at' => now(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BranchUserAssignmentStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }
}
