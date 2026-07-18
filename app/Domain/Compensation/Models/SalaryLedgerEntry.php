<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\SalaryLedgerEntryType;
use App\Domain\Compensation\Enums\SalaryLedgerStatus;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\SalaryLedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * SalaryLedgerEntry — an append-only salary accrual fact (Plan §60; Phase 20G; table
 * `salary_ledger`). Branch-owned; append-only (`created_at` only). One accrual per payable
 * pay-period segment (Actual/Actual proration, Africa/Nairobi); corrections are additive
 * negative rows. Money is integer minor units (ADR-005). Immutability is DB-enforced.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $staff_profile_id
 * @property int $compensation_plan_id
 * @property Carbon $pay_period_start
 * @property Carbon $pay_period_end
 * @property string $pay_period_segment_key
 * @property int $amount_minor
 * @property string $currency
 * @property int|null $source_entry_id
 * @property SalaryLedgerEntryType $entry_type
 * @property SalaryLedgerStatus $status
 * @property int|null $payout_item_id
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $created_at
 */
class SalaryLedgerEntry extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<SalaryLedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'salary_ledger';

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'staff_profile_id',
        'compensation_plan_id',
        'pay_period_start',
        'pay_period_end',
        'pay_period_segment_key',
        'amount_minor',
        'currency',
        'source_entry_id',
        'entry_type',
        'status',
        'payout_item_id',
        'created_by',
        'approved_by',
    ];

    /** @return Factory<SalaryLedgerEntry> */
    protected static function newFactory(): Factory
    {
        return SalaryLedgerEntryFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (SalaryLedgerEntry $entry): void {
            if (! isset($entry->ulid)) {
                $entry->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pay_period_start' => 'date',
            'pay_period_end' => 'date',
            'amount_minor' => 'integer',
            'entry_type' => SalaryLedgerEntryType::class,
            'status' => SalaryLedgerStatus::class,
            'created_at' => 'datetime',
        ];
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

    /** @return BelongsTo<PersonnelCompensationPlan, $this> */
    public function compensationPlan(): BelongsTo
    {
        return $this->belongsTo(PersonnelCompensationPlan::class, 'compensation_plan_id');
    }

    /** @return BelongsTo<SalaryLedgerEntry, $this> */
    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_entry_id');
    }
}
