<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Models;

use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagState;
use App\Models\User;
use Database\Factories\PlatformFeatureFlagFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PlatformFeatureFlag — per-environment rollout state (COR-UI08-001 §12; Phase UI-08). Lifecycle:
 * docs/architecture/state-machines/platform-feature-flag.md.
 *
 * The row holds STATE only. A flag's identity, ownership, risk class and dependencies live in the
 * code catalogue (`config/platform-feature-flags.php`), so this table can never bring a flag into
 * existence — which is what keeps "no flag exists that was not code-reviewed" true.
 *
 * @property int $id
 * @property string $ulid
 * @property string $flag_key
 * @property string $environment
 * @property PlatformFeatureFlagState $state
 * @property int $rollout_basis_points
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property int $version
 * @property string|null $approved_configuration_hash
 * @property int|null $applied_change_request_id
 * @property int|null $updated_by_user_id
 */
class PlatformFeatureFlag extends Model
{
    /** @use HasFactory<PlatformFeatureFlagFactory> */
    use HasFactory;

    protected $table = 'platform_feature_flags';

    protected $fillable = [
        'flag_key',
        'environment',
        'state',
        'rollout_basis_points',
        'effective_from',
        'effective_to',
        'version',
        'approved_configuration_hash',
        'applied_change_request_id',
        'updated_by_user_id',
    ];

    /** @return Factory<PlatformFeatureFlag> */
    protected static function newFactory(): Factory
    {
        return PlatformFeatureFlagFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformFeatureFlag $flag): void {
            if (! isset($flag->ulid)) {
                $flag->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => PlatformFeatureFlagState::class,
            'rollout_basis_points' => 'integer',
            'version' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<PlatformFeatureFlagTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(PlatformFeatureFlagTarget::class, 'feature_flag_id');
    }

    /** @return HasMany<PlatformFeatureFlagChangeRequest, $this> */
    public function changeRequests(): HasMany
    {
        return $this->hasMany(PlatformFeatureFlagChangeRequest::class, 'feature_flag_id');
    }

    /** @return HasMany<PlatformFeatureFlagHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(PlatformFeatureFlagHistory::class, 'feature_flag_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * The configuration this row represents, canonically ordered so the SHA-256 of it is stable.
     * Targets are sorted, because "which targets were approved?" must not depend on insertion order.
     *
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        return [
            'state' => $this->state->value,
            'rollout_basis_points' => $this->rollout_basis_points,
            'effective_from' => $this->effective_from?->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
            'targets' => $this->targets
                ->map(static fn (PlatformFeatureFlagTarget $target): string => $target->target_type.':'.$target->target_value)
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
