<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Auth\Models\MfaRecoveryCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MfaRecoveryCode>
 */
class MfaRecoveryCodeFactory extends Factory
{
    protected $model = MfaRecoveryCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'user_id' => User::factory(),
            // Hash of a random raw code (the raw code is never stored).
            'code_hash' => hash('sha256', (string) Str::random(40)),
            'used_at' => null,
        ];
    }

    public function used(): static
    {
        return $this->state(fn (array $attributes): array => [
            'used_at' => now(),
        ]);
    }
}
