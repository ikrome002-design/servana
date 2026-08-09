<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\PlatformSmsBillingRuleState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\PlatformSmsBillingRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PlatformSmsBillingRule — the single effective-dated SMS pricing authority (COR-UI08-001 §9;
 * Phase UI-08). Platform-owned: no merchant/branch scope. Append-only; the database guard
 * `platform_sms_billing_rules_guard` freezes every pricing column and permits only a
 * pending -> cancelled transition. The ULID is the public id + route key.
 *
 * IT CARRIES NO CURRENCY. Currency is read from the effective PlatformBillingSettings version at
 * the same instant, so the system keeps exactly one currency authority.
 *
 * @property int $id
 * @property string $ulid
 * @property int $unit_cost_minor
 * @property int|null $tax_basis_points
 * @property int|null $usage_warning_threshold_units
 * @property int|null $usage_anomaly_threshold_basis_points
 * @property Carbon $effective_from
 * @property string $reason
 * @property int $created_by_user_id
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancellation_reason
 */
class PlatformSmsBillingRule extends Model
{
    /** @use HasFactory<PlatformSmsBillingRuleFactory> */
    use HasFactory;

    protected $table = 'platform_sms_billing_rules';

    protected $fillable = [
        'unit_cost_minor',
        'tax_basis_points',
        'usage_warning_threshold_units',
        'usage_anomaly_threshold_basis_points',
        'effective_from',
        'reason',
        'created_by_user_id',
    ];

    /** @return Factory<PlatformSmsBillingRule> */
    protected static function newFactory(): Factory
    {
        return PlatformSmsBillingRuleFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformSmsBillingRule $rule): void {
            if (! isset($rule->ulid)) {
                $rule->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_cost_minor' => 'integer',
            'tax_basis_points' => 'integer',
            'usage_warning_threshold_units' => 'integer',
            'usage_anomaly_threshold_basis_points' => 'integer',
            'effective_from' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @param  Builder<PlatformSmsBillingRule>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('cancelled_at');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * The derived state at an instant. `$laterLiveRuleIsEffective` says whether another
     * uncancelled rule with a greater `effective_from` has also taken effect by then — the caller
     * knows the series, so it is passed in rather than re-queried per row.
     */
    public function stateAt(CarbonImmutable $at, bool $laterLiveRuleIsEffective = false): PlatformSmsBillingRuleState
    {
        if ($this->isCancelled()) {
            return PlatformSmsBillingRuleState::Cancelled;
        }

        if ($this->effective_from->greaterThan($at)) {
            return PlatformSmsBillingRuleState::Pending;
        }

        return $laterLiveRuleIsEffective
            ? PlatformSmsBillingRuleState::Superseded
            : PlatformSmsBillingRuleState::Effective;
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }
}
