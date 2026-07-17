<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\CompensationPlanHistoryEvent;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\CompensationPlanHistoryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * CompensationPlanHistory — append-only compensation change history (Plan §59, §80; Scope §12;
 * Phase 20F). Branch-owned; `created_at` only (no updated_at — the row has no mutable column,
 * and a DB trigger blocks every UPDATE and DELETE).
 *
 * Written in the SAME transaction as the plan transition that produced it. Read via
 * compensation.history.view (HR, branch-scoped).
 *
 * NOT a ledger: records configuration changes — never money owed, accrued, earned, or paid.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $compensation_plan_id
 * @property int $staff_profile_id
 * @property CompensationPlanHistoryEvent $event
 * @property CompensationPlanStatus|null $from_status
 * @property CompensationPlanStatus $to_status
 * @property array<string, mixed>|null $changed_fields
 * @property bool $was_backdated
 * @property string $change_reason
 * @property int $actor_user_id
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $created_at
 */
class CompensationPlanHistory extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<CompensationPlanHistoryFactory> */
    use HasFactory;

    protected $table = 'compensation_plan_history';

    /** Append-only: created_at only. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'compensation_plan_id',
        'staff_profile_id',
        'event',
        'from_status',
        'to_status',
        'changed_fields',
        'was_backdated',
        'change_reason',
        'actor_user_id',
        'effective_from',
    ];

    /** @return Factory<CompensationPlanHistory> */
    protected static function newFactory(): Factory
    {
        return CompensationPlanHistoryFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (CompensationPlanHistory $history): void {
            if (! isset($history->ulid)) {
                $history->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event' => CompensationPlanHistoryEvent::class,
            'from_status' => CompensationPlanStatus::class,
            'to_status' => CompensationPlanStatus::class,
            'changed_fields' => 'array',
            'was_backdated' => 'boolean',
            'effective_from' => 'immutable_date',
            'created_at' => 'immutable_datetime',
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

    /** @return BelongsTo<PersonnelCompensationPlan, $this> */
    public function compensationPlan(): BelongsTo
    {
        return $this->belongsTo(PersonnelCompensationPlan::class, 'compensation_plan_id');
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
