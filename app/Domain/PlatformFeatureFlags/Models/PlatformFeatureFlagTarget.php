<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Models;

use Database\Factories\PlatformFeatureFlagTargetFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * PlatformFeatureFlagTarget — one scalar targeting row (COR-UI08-001 §12; Phase UI-08).
 *
 * `target_type` is a closed vocabulary and `target_value` is a scalar identifier, so there is
 * nowhere to store an executable predicate and nothing here is ever evaluated as code.
 *
 * @property int $id
 * @property string $ulid
 * @property int $feature_flag_id
 * @property string $target_type
 * @property string $target_value
 * @property int $created_by_user_id
 */
class PlatformFeatureFlagTarget extends Model
{
    /** @use HasFactory<PlatformFeatureFlagTargetFactory> */
    use HasFactory;

    protected $table = 'platform_feature_flag_targets';

    protected $fillable = ['feature_flag_id', 'target_type', 'target_value', 'created_by_user_id'];

    /** @return Factory<PlatformFeatureFlagTarget> */
    protected static function newFactory(): Factory
    {
        return PlatformFeatureFlagTargetFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformFeatureFlagTarget $target): void {
            if (! isset($target->ulid)) {
                $target->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return BelongsTo<PlatformFeatureFlag, $this> */
    public function flag(): BelongsTo
    {
        return $this->belongsTo(PlatformFeatureFlag::class, 'feature_flag_id');
    }
}
