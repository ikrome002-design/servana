<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MerchantProfile>
 */
class MerchantProfileFactory extends Factory
{
    protected $model = MerchantProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'business_category' => fake()->randomElement(['Salon', 'Barbershop', 'Spa', 'Grooming Studio']),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '+2547'.fake()->numerify('########'),
            'address' => fake()->streetAddress(),
            'town' => fake()->city(),
            'country' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ];
    }
}
