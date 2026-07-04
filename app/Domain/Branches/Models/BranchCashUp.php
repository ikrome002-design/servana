<?php

declare(strict_types=1);

namespace App\Domain\Branches\Models;

use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\BranchCashUpFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Branch cash-up (Plan §45; Phase 18B — evolved from the Phase 7 seam).
 *
 * Money columns are integer minor units (bigint). Phase 18B adds the canonical
 * reconciliation columns (business_date, expected_minor/counted_minor/variance_minor,
 * approved_by/approved_at, notes) and per-method {@see CashUpLine} rows. Maker = Branch
 * Manager; checker = Finance. Expected totals are server-derived (Gate H).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int|null $branch_day_record_id
 * @property Carbon|null $business_date
 * @property int $expected_minor
 * @property int $counted_minor
 * @property int $variance_minor
 * @property int $expected_total
 * @property int $cash_counted
 * @property int $discrepancy_amount
 * @property CashUpStatus $status
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, CashUpLine> $lines
 */
class BranchCashUp extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<BranchCashUpFactory> */
    use HasFactory;

    /** @return Factory<BranchCashUp> */
    protected static function newFactory(): Factory
    {
        return BranchCashUpFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'branch_id',
        'branch_day_record_id',
        'business_date',
        'expected_minor',
        'counted_minor',
        'variance_minor',
        'expected_total',
        'recorded_totals',
        'cash_counted',
        'discrepancy_amount',
        'discrepancy_note',
        'status',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (BranchCashUp $cashUp): void {
            if (! isset($cashUp->ulid)) {
                $cashUp->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'expected_minor' => 'integer',
            'counted_minor' => 'integer',
            'variance_minor' => 'integer',
            'expected_total' => 'integer',
            'cash_counted' => 'integer',
            'discrepancy_amount' => 'integer',
            'recorded_totals' => 'array',
            'status' => CashUpStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<CashUpLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CashUpLine::class, 'cash_up_id');
    }

    /** @return BelongsTo<MerchantBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
