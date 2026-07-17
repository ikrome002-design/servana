<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Enums\SalaryPeriod;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\PersonnelCompensationPlanFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * PersonnelCompensationPlan — the compensation model for one personnel in one branch
 * (Plan §59; Scope §12.2-§12.9; Phase 20F). Branch-owned; subject is `staff_profile_id`;
 * ULID is the public route key. One ACTIVE plan per personnel per branch (DB EXCLUDE).
 *
 * Salary is integer minor units (never float). Lifecycle via the compensation-plan state
 * machine; `draft` is the only editable state — once non-draft the terms are immutable at
 * the database and a change is a SUPERSEDE (new version). `salary_only` never carries a
 * commission rule (DB model-shape CHECK), so no rule can ever resolve for that personnel.
 *
 * Configuration only: creates no salary accrual, earned commission, ledger row, or payout
 * (Phases 20G/20H). A plan grants NO access (Plan §59).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $staff_profile_id
 * @property CompensationModel $compensation_model
 * @property int|null $salary_amount_minor
 * @property string|null $salary_currency
 * @property SalaryPeriod|null $salary_period
 * @property int|null $salary_payout_day
 * @property int|null $commission_rule_id
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property CompensationPlanStatus $status
 * @property bool $is_backdated
 * @property int|null $supersedes_plan_id
 * @property string|null $notes
 * @property string $change_reason
 * @property int $created_by
 * @property int|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $rejected_by
 * @property CarbonImmutable|null $rejected_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PersonnelCompensationPlan extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<PersonnelCompensationPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'staff_profile_id',
        'compensation_model',
        'salary_amount_minor',
        'salary_currency',
        'salary_period',
        'salary_payout_day',
        'commission_rule_id',
        'effective_from',
        'effective_to',
        'status',
        'is_backdated',
        'supersedes_plan_id',
        'notes',
        'change_reason',
        'created_by',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
    ];

    /** @return Factory<PersonnelCompensationPlan> */
    protected static function newFactory(): Factory
    {
        return PersonnelCompensationPlanFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PersonnelCompensationPlan $plan): void {
            if (! isset($plan->ulid)) {
                $plan->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'compensation_model' => CompensationModel::class,
            'salary_amount_minor' => 'integer',
            'salary_period' => SalaryPeriod::class,
            'salary_payout_day' => 'integer',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'status' => CompensationPlanStatus::class,
            'is_backdated' => 'boolean',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
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

    /**
     * The compensation subject.
     *
     * @return BelongsTo<StaffProfile, $this>
     */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    /** @return BelongsTo<CommissionRule, $this> */
    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    /**
     * The incumbent plan this version superseded.
     *
     * @return BelongsTo<PersonnelCompensationPlan, $this>
     */
    public function supersedesPlan(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_plan_id');
    }

    /** @return HasMany<CompensationPlanHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(CompensationPlanHistory::class, 'compensation_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
