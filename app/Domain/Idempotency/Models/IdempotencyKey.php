<?php

declare(strict_types=1);

namespace App\Domain\Idempotency\Models;

use App\Domain\Idempotency\Enums\IdempotencyState;
use App\Domain\Idempotency\IdempotencyStore;
use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A durable idempotency claim + replay record (Plan §13.5, §24.4; Phase R4).
 *
 * Written only through {@see IdempotencyStore}. The
 * response body is encrypted at rest via the `encrypted` cast; only
 * `key_hash` (SHA-256 of the raw key) is ever stored, never the raw key.
 *
 * @property int $id
 * @property string $ulid
 * @property string $idempotency_scope
 * @property string $key_hash
 * @property int|null $actor_user_id
 * @property int|null $merchant_id
 * @property int|null $branch_id
 * @property string $route_name
 * @property string $http_method
 * @property string|null $request_content_type
 * @property string $request_hash
 * @property IdempotencyState $state
 * @property int|null $response_status
 * @property array<string, string>|null $response_headers
 * @property array<string, mixed>|null $response_body_encrypted
 * @property Carbon $locked_at
 * @property Carbon $lock_expires_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property string|null $last_error_code
 * @property Carbon $expires_at
 */
class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    protected $fillable = [
        'ulid',
        'idempotency_scope',
        'key_hash',
        'actor_user_id',
        'merchant_id',
        'branch_id',
        'route_name',
        'http_method',
        'request_content_type',
        'request_hash',
        'state',
        'response_status',
        'response_headers',
        'response_body_encrypted',
        'locked_at',
        'lock_expires_at',
        'completed_at',
        'failed_at',
        'last_error_code',
        'expires_at',
    ];

    /** Never expose the (encrypted) stored body or the key hash in serialization. */
    protected $hidden = [
        'response_body_encrypted',
        'key_hash',
    ];

    /** @return Factory<IdempotencyKey> */
    protected static function newFactory(): Factory
    {
        return IdempotencyKeyFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (IdempotencyKey $key): void {
            if (! isset($key->ulid)) {
                $key->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => IdempotencyState::class,
            'response_headers' => 'array',
            // Replay body encrypted at rest (AES-256-GCM via APP_KEY), JSON-shaped.
            'response_body_encrypted' => 'encrypted:array',
            'response_status' => 'integer',
            'locked_at' => 'datetime',
            'lock_expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** True while this row's execution lock has not expired. */
    public function lockIsActive(): bool
    {
        return $this->lock_expires_at->isFuture();
    }
}
