<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Clients\Enums\ClientStatus;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Support\ClientContactIndex;
use App\Domain\Clients\Support\PhoneNumberNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Client>
 *
 * Sets phone_encrypted (plaintext — the `encrypted` cast encrypts on save),
 * phone_index (HMAC blind index) and phone_last_four consistently from one
 * normalized number, exactly as the CreateClient action does.
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $phone = '+2547'.fake()->unique()->numerify('########');
        $normalized = PhoneNumberNormalizer::normalize($phone);

        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'merchant_id' => fn (array $attributes) => MerchantBranch::query()
                ->whereKey($attributes['branch_id'])->value('merchant_id'),
            'full_name' => fake()->name(),
            'phone_encrypted' => $normalized,
            'phone_index' => ClientContactIndex::for($phone),
            'phone_last_four' => PhoneNumberNormalizer::lastFour($normalized),
            'email_encrypted' => fake()->optional()->safeEmail(),
            'notes' => fake()->optional()->sentence(),
            'status' => ClientStatus::Active,
        ];
    }

    /** Pin a specific phone (keeps encrypted/index/last_four consistent). */
    public function withPhone(string $phone): static
    {
        $normalized = PhoneNumberNormalizer::normalize($phone);

        return $this->state(fn (array $attributes): array => [
            'phone_encrypted' => $normalized,
            'phone_index' => ClientContactIndex::for($phone),
            'phone_last_four' => PhoneNumberNormalizer::lastFour($normalized),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => ClientStatus::Archived]);
    }
}
