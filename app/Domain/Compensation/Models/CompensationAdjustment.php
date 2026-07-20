<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\CompensationAdjustmentType;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\CompensationAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * CompensationAdjustment — an append-only additive compensation adjustment (Plan §60/§61;
 * Phase 20G; table `compensation_adjustments`). Branch-owned; append-only (`created_at` only).
 * Either a Finance manual adjustment (`compensation.adjustment.create`) or a system-created
 * negative adjustment offsetting an already-paid ledger row. Money is integer minor units and
 * may be negative. Immutability is DB-enforced.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $staff_profile_id
 * @property CompensationAdjustmentType $adjustment_type
 * @property int $amount_minor
 * @property string $currency
 * @property string $reason
 * @property int|null $source_commission_ledger_id
 * @property int|null $source_salary_ledger_id
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property int|null $payout_item_id
 * @property Carbon|null $created_at
 */
class CompensationAdjustment extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<CompensationAdjustmentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'staff_profile_id',
        'adjustment_type',
        'amount_minor',
        'currency',
        'reason',
        'source_commission_ledger_id',
        'source_salary_ledger_id',
        'created_by',
        'approved_by',
        'payout_item_id',
    ];

    /** @return Factory<CompensationAdjustment> */
    protected static function newFactory(): Factory
    {
        return CompensationAdjustmentFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (CompensationAdjustment $adjustment): void {
            if (! isset($adjustment->ulid)) {
                $adjustment->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'adjustment_type' => CompensationAdjustmentType::class,
            'amount_minor' => 'integer',
            'created_at' => 'datetime',
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

    /** @return BelongsTo<StaffProfile, $this> */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }
}
