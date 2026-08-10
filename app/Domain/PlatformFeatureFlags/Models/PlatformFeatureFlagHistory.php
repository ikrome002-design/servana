<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Models;

use App\Models\User;
use App\Support\Casts\JsonObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PlatformFeatureFlagHistory — append-only governance history (COR-UI08-001 §12; Phase UI-08).
 *
 * `$timestamps` is disabled because the table has no `updated_at`: a history row is written once and
 * never touched again, and the `platform_feature_flag_history_append_only` trigger raises on UPDATE
 * and DELETE to make that structural rather than conventional.
 *
 * @property int $id
 * @property string $ulid
 * @property int $feature_flag_id
 * @property int|null $change_request_id
 * @property string $action
 * @property array<string, mixed>|null $before_configuration
 * @property array<string, mixed>|null $after_configuration
 * @property string|null $before_hash
 * @property string|null $after_hash
 * @property int|null $actor_user_id
 * @property string|null $reason
 * @property string|null $correlation_id
 * @property Carbon $created_at
 */
class PlatformFeatureFlagHistory extends Model
{
    protected $table = 'platform_feature_flag_history';

    /** No `updated_at` exists — a history row is never updated. */
    public $timestamps = false;

    protected $fillable = [
        'feature_flag_id',
        'change_request_id',
        'action',
        'before_configuration',
        'after_configuration',
        'before_hash',
        'after_hash',
        'actor_user_id',
        'reason',
        'correlation_id',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (PlatformFeatureFlagHistory $row): void {
            if (! isset($row->ulid)) {
                $row->ulid = (string) Str::ulid();
            }

            if (! isset($row->created_at)) {
                $row->created_at = now();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'before_configuration' => JsonObject::class,
            'after_configuration' => JsonObject::class,
            'created_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<PlatformFeatureFlag, $this> */
    public function flag(): BelongsTo
    {
        return $this->belongsTo(PlatformFeatureFlag::class, 'feature_flag_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
