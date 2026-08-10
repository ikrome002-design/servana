<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Models;

use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagChangeRequestStatus;
use App\Models\User;
use App\Support\Casts\JsonObject;
use Database\Factories\PlatformFeatureFlagChangeRequestFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PlatformFeatureFlagChangeRequest — maker/checker for a flag change (COR-UI08-001 §12.3;
 * Phase UI-08). Lifecycle:
 * docs/architecture/state-machines/platform-feature-flag-change-request.md.
 *
 * The impact statement, rollback plan, health criterion and reason are NOT NULL at the database, so
 * a production-sensitive change with no stated impact or no rollback plan is unrepresentable. The
 * approver is CHECK-constrained to differ from the requester, so a self-approved change cannot
 * exist as a row at all.
 *
 * @property int $id
 * @property string $ulid
 * @property int $feature_flag_id
 * @property PlatformFeatureFlagChangeRequestStatus $status
 * @property array<string, mixed> $proposed_configuration
 * @property string $proposed_configuration_hash
 * @property string $impact_statement
 * @property string $rollback_plan
 * @property string $health_criterion
 * @property string $reason
 * @property int $requested_by_user_id
 * @property int|null $approved_by_user_id
 * @property Carbon $requested_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $applied_at
 * @property string|null $decision_note
 * @property string|null $failure_reason
 */
class PlatformFeatureFlagChangeRequest extends Model
{
    /** @use HasFactory<PlatformFeatureFlagChangeRequestFactory> */
    use HasFactory;

    protected $table = 'platform_feature_flag_change_requests';

    protected $fillable = [
        'feature_flag_id',
        'status',
        'proposed_configuration',
        'proposed_configuration_hash',
        'impact_statement',
        'rollback_plan',
        'health_criterion',
        'reason',
        'requested_by_user_id',
        'approved_by_user_id',
        'requested_at',
        'decided_at',
        'applied_at',
        'decision_note',
        'failure_reason',
    ];

    /** @return Factory<PlatformFeatureFlagChangeRequest> */
    protected static function newFactory(): Factory
    {
        return PlatformFeatureFlagChangeRequestFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformFeatureFlagChangeRequest $request): void {
            if (! isset($request->ulid)) {
                $request->ulid = (string) Str::ulid();
            }

            if (! isset($request->requested_at)) {
                $request->requested_at = now();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PlatformFeatureFlagChangeRequestStatus::class,
            'proposed_configuration' => JsonObject::class,
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
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
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * The canonical SHA-256 of a proposed configuration. One definition, used by the request, the
     * flag's `approved_configuration_hash` and the history row, so "is what is live what was
     * approved?" is answerable by comparison rather than inference.
     *
     * @param  array<string, mixed>  $configuration
     */
    public static function hashConfiguration(array $configuration): string
    {
        ksort($configuration);

        return hash('sha256', (string) json_encode($configuration, JSON_THROW_ON_ERROR));
    }
}
