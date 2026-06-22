<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Auth\Models\MfaCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<MfaCredential>
 */
class MfaCredentialFactory extends Factory
{
    protected $model = MfaCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'user_id' => User::factory(),
            'type' => MfaCredential::TYPE_TOTP,
            // The `encrypted` cast encrypts this on save; tests read it back decrypted.
            'secret_encrypted' => (new Google2FA)->generateSecretKey(),
            'confirmed_at' => null,
            'last_used_at' => null,
            'last_used_timestep' => null,
        ];
    }

    /** A confirmed (enrolled) credential. */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'confirmed_at' => now(),
        ]);
    }
}
