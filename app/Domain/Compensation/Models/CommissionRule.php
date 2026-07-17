<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Compensation\Enums\CommissionAppliesTo;
use App\Domain\Compensation\Enums\CommissionCalculationBasis;
use App\Domain\Compensation\Enums\CommissionCalculationType;
use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\CommissionRuleFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * CommissionRule — HR-controlled commission CONFIGURATION (Plan §59; Scope §12.7 Step 3A /
 * §18.3; Phase 20F). Branch-owned; ULID is the public route key. A SIBLING record referenced
 * by PersonnelCompensationPlan::commission_rule_id — not a child of a plan, and NOT a ledger.
 *
 * Rates are integer basis points; fixed amounts are integer minor units (never float).
 * Lifecycle via the commission-rule state machine; active terms are immutable (supersede,
 * never edit) and a previously active rule is ENDED, not deleted.
 *
 * Configuration only: computes no commission and creates no earned row — Phase 20G (Plan §61).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property CommissionCalculationType $calculation_type
 * @property int|null $percentage_basis_points
 * @property int|null $fixed_amount_minor
 * @property string|null $currency
 * @property CommissionCalculationBasis $calculation_basis
 * @property CommissionAppliesTo $applies_to
 * @property int|null $service_category_id
 * @property bool $applies_to_preferred_personnel_fee
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property CommissionRuleStatus $status
 * @property string|null $notes
 * @property string $change_reason
 * @property int $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CommissionRule extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<CommissionRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'calculation_type',
        'percentage_basis_points',
        'fixed_amount_minor',
        'currency',
        'calculation_basis',
        'applies_to',
        'service_category_id',
        'applies_to_preferred_personnel_fee',
        'effective_from',
        'effective_to',
        'status',
        'notes',
        'change_reason',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    /** @return Factory<CommissionRule> */
    protected static function newFactory(): Factory
    {
        return CommissionRuleFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (CommissionRule $rule): void {
            if (! isset($rule->ulid)) {
                $rule->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'calculation_type' => CommissionCalculationType::class,
            'percentage_basis_points' => 'integer',
            'fixed_amount_minor' => 'integer',
            'calculation_basis' => CommissionCalculationBasis::class,
            'applies_to' => CommissionAppliesTo::class,
            'applies_to_preferred_personnel_fee' => 'boolean',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'status' => CommissionRuleStatus::class,
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return BelongsTo<MerchantBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    /** @return BelongsTo<ServiceCategory, $this> */
    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
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

    /** @return HasMany<PersonnelCompensationPlan, $this> */
    public function compensationPlans(): HasMany
    {
        return $this->hasMany(PersonnelCompensationPlan::class, 'commission_rule_id');
    }
}
