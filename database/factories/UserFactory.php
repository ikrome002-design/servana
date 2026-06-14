<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'status' => User::STATUS_ACTIVE,
            // No password column is populated — Servana is Magic Link only (A3).
        ];
    }

    /** Email not yet verified (verified on first Magic Link consume, §9.1). */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => User::STATUS_SUSPENDED,
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => User::STATUS_DEACTIVATED,
        ]);
    }
}
