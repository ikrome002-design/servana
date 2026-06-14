<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Auth\Models\MagicLoginToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MagicLoginToken>
 */
class MagicLoginTokenFactory extends Factory
{
    protected $model = MagicLoginToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'email' => fake()->unique()->safeEmail(),
            // A plausible SHA-256 digest; tests that consume real tokens set this
            // explicitly via MagicLinkTokenService::hash().
            'token_hash' => hash('sha256', Str::random(80)),
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function consumed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'consumed_at' => now(),
        ]);
    }

    public function invalidated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'invalidated_at' => now(),
        ]);
    }
}
