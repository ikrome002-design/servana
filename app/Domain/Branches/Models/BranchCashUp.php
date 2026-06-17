<?php

declare(strict_types=1);

namespace App\Domain\Branches\Models;

use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use Database\Factories\BranchCashUpFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Branch cash-up — SEAM ONLY (Plan §7.2; full workflow Phase 18).
 *
 * Money columns are integer minor units (bigint). No finance logic lives here in
 * Phase 7; BranchClosureGuard only reads `status`/`discrepancy_amount` to block
 * closure on an unresolved discrepancy (Scope §3.3).
 *
 * @property int $id
 * @property string $ulid
 * @property int $branch_id
 * @property int|null $branch_day_record_id
 * @property int $expected_total
 * @property int $cash_counted
 * @property int $discrepancy_amount
 * @property CashUpStatus $status
 */
class BranchCashUp extends Model
{
    use BelongsToBranch;

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
        'branch_id',
        'branch_day_record_id',
        'expected_total',
        'recorded_totals',
        'cash_counted',
        'discrepancy_amount',
        'discrepancy_note',
        'status',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
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
            'expected_total' => 'integer',
            'cash_counted' => 'integer',
            'discrepancy_amount' => 'integer',
            'recorded_totals' => 'array',
            'status' => CashUpStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MerchantBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }
}
