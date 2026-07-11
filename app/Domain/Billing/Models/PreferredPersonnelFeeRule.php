<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\PreferredFeeCalculationBasis;
use App\Domain\Billing\Enums\PreferredFeeCalculationType;
use App\Domain\Billing\Enums\PreferredFeeScope;
use App\Domain\Billing\Enums\PreferredPersonnelFeeRuleStatus;
use App\Domain\Catalogue\Models\Service;
use App\Models\User;
use Database\Factories\PreferredPersonnelFeeRuleFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PreferredPersonnelFeeRule — launch-active, effective-dated fixed/percentage rule
 * (Plan §13.10, §47; ADR-005; Phase 20A). Platform-owned; Super-Admin governed. Fixed vs
 * percentage is mutually exclusive (DB value-shape CHECK); scope binds `service_id`; a partial
 * `EXCLUDE USING gist` (over active + scheduled) rejects overlapping ranges per scope
 * (+ service_id). Active monetary terms are immutable — a change supersedes with a new version.
 * `created_by` is null for the system/migration legacy backfill. The ULID is the route key.
 *
 * @property int $id
 * @property string $ulid
 * @property PreferredFeeCalculationType $calculation_type
 * @property int|null $fixed_amount_minor
 * @property int|null $percentage_basis_points
 * @property string|null $currency
 * @property PreferredFeeCalculationBasis $calculation_basis
 * @property PreferredFeeScope $scope
 * @property int|null $service_id
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property PreferredPersonnelFeeRuleStatus $status
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string $change_reason
 */
class PreferredPersonnelFeeRule extends Model
{
    /** @use HasFactory<PreferredPersonnelFeeRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'calculation_type',
        'fixed_amount_minor',
        'percentage_basis_points',
        'currency',
        'calculation_basis',
        'scope',
        'service_id',
        'effective_from',
        'effective_to',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'change_reason',
    ];

    /** @return Factory<PreferredPersonnelFeeRule> */
    protected static function newFactory(): Factory
    {
        return PreferredPersonnelFeeRuleFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PreferredPersonnelFeeRule $rule): void {
            if (! isset($rule->ulid)) {
                $rule->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'calculation_type' => PreferredFeeCalculationType::class,
            'fixed_amount_minor' => 'integer',
            'percentage_basis_points' => 'integer',
            'calculation_basis' => PreferredFeeCalculationBasis::class,
            'scope' => PreferredFeeScope::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'status' => PreferredPersonnelFeeRuleStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
