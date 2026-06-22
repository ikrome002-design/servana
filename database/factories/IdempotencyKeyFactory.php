<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Idempotency\Enums\IdempotencyState;
use App\Domain\Idempotency\Models\IdempotencyKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    protected $model = IdempotencyKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'idempotency_scope' => 'platform:user:'.Str::ulid(),
            'key_hash' => hash('sha256', (string) Str::random(40)),
            'route_name' => 'testing.idempotency.financial',
            'http_method' => 'POST',
            'request_content_type' => 'application/json',
            'request_hash' => hash('sha256', (string) Str::random(40)),
            'state' => IdempotencyState::Processing,
            'locked_at' => now(),
            'lock_expires_at' => now()->addSeconds(30),
            'expires_at' => now()->addHours(72),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => IdempotencyState::Completed,
            'response_status' => 200,
            'response_headers' => ['content-type' => 'application/json'],
            'response_body_encrypted' => ['ok' => true],
            'completed_at' => now(),
            'lock_expires_at' => now()->subSecond(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => IdempotencyState::Failed,
            'last_error_code' => 'server_error',
            'failed_at' => now(),
            'lock_expires_at' => now()->subSecond(),
        ]);
    }

    /** A processing row whose lock has already expired (abandoned). */
    public function expiredLock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => IdempotencyState::Processing,
            'locked_at' => now()->subMinutes(5),
            'lock_expires_at' => now()->subMinutes(4),
        ]);
    }
}
