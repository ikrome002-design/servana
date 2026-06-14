<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MerchantBranch>
 */
class MerchantBranchFactory extends Factory
{
    protected $model = MerchantBranch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'name' => fake()->city().' Branch',
            'code' => Str::upper(Str::random(4)),
            'address' => fake()->streetAddress(),
            'town' => fake()->city(),
            'phone' => '+2547'.fake()->numerify('########'),
        ];
    }
}
