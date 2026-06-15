<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Enums\StaffEmploymentStatus;
use App\Domain\Hr\Enums\StaffEmploymentType;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StaffProfile>
 */
class StaffProfileFactory extends Factory
{
    protected $model = StaffProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'ulid' => (string) Str::ulid(),
            'merchant_user_id' => MerchantUser::factory(),
            'merchant_id' => Merchant::factory(),
            'primary_branch_id' => MerchantBranch::factory(),
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => $first.' '.$last,
            'phone' => '+2547'.fake()->unique()->numerify('########'),
            'role_title' => 'Stylist',
            'employment_type' => StaffEmploymentType::FullTime,
            'employment_status' => StaffEmploymentStatus::Employed,
            'start_date' => now()->toDateString(),
            'is_active' => true,
        ];
    }
}
