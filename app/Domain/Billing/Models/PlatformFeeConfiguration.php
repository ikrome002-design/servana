<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Enums\PlatformFeeBasisType;
use App\Domain\Billing\Enums\PlatformFeeConfigurationStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\PlatformFeeConfigurationFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * PlatformFeeConfiguration — effective-dated percentage platform-fee configuration (Plan §13.10,
 * §51, §52; Phase 20E). PLATFORM-SCOPED (no merchant/branch ownership; TenantOwnership::EXEMPT).
 * ULID is the public route key. Rates are integer basis points; the fixed component is integer
 * minor units. Lifecycle via PlatformFeeConfigurationStateMachine; approved monetary terms are
 * immutable (supersede, never edit).
 *
 * @property int $id
 * @property string $ulid
 * @property BillingMode $billing_mode
 * @property int|null $percentage_basis_points
 * @property int|null $fixed_component_minor
 * @property CanonicalPlatformFeeTier|null $tier_behavior
 * @property int|null $shared_split_basis_points
 * @property PlatformFeeBasisType|null $fee_basis_type
 * @property string $currency
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property PlatformFeeConfigurationStatus $status
 * @property int $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string $change_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PlatformFeeConfiguration extends Model
{
    /** @use HasFactory<PlatformFeeConfigurationFactory> */
    use HasFactory;

    protected $fillable = [
        'billing_mode',
        'percentage_basis_points',
        'fixed_component_minor',
        'tier_behavior',
        'shared_split_basis_points',
        'fee_basis_type',
        'currency',
        'effective_from',
        'effective_to',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'change_reason',
    ];

    /** @return Factory<PlatformFeeConfiguration> */
    protected static function newFactory(): Factory
    {
        return PlatformFeeConfigurationFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformFeeConfiguration $config): void {
            if (! isset($config->ulid)) {
                $config->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_mode' => BillingMode::class,
            'percentage_basis_points' => 'integer',
            'fixed_component_minor' => 'integer',
            'tier_behavior' => CanonicalPlatformFeeTier::class,
            'shared_split_basis_points' => 'integer',
            'fee_basis_type' => PlatformFeeBasisType::class,
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'status' => PlatformFeeConfigurationStatus::class,
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
