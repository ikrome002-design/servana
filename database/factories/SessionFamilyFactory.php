<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Sessions\Enums\SessionRevocationReason;
use App\Domain\Sessions\Models\SessionFamily;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SessionFamily>
 */
class SessionFamilyFactory extends Factory
{
    protected $model = SessionFamily::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'user_id' => User::factory(),
            'environment' => 'testing',
            'lifecycle_version' => 1,
            'last_activity_at' => now(),
        ];
    }

    public function revoked(SessionRevocationReason $reason = SessionRevocationReason::GlobalLogout): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);
    }
}
