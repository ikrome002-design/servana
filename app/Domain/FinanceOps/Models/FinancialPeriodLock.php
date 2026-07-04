<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\FinanceOps\Enums\FinancialPeriodLockStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\FinancialPeriodLockFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FinancialPeriodLock — database-backed period lock (Plan §46; ADR-0007; Gate F;
 * Phase 18B). Merchant-owned with an optional branch scope (`branch_id` null =
 * merchant-wide). ULID is the public id + route key. Read by
 * DatabasePeriodLockRepository for 423 enforcement.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int|null $branch_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property FinancialPeriodLockStatus $status
 * @property bool $exception_required
 * @property int $locked_by
 * @property Carbon|null $locked_at
 * @property int|null $reopen_requested_by
 * @property Carbon|null $reopen_requested_at
 * @property string|null $reopen_reason
 * @property int|null $reopen_approved_by
 * @property Carbon|null $reopen_approved_at
 * @property int|null $reopened_by
 * @property Carbon|null $reopened_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FinancialPeriodLock extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<FinancialPeriodLockFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'period_start',
        'period_end',
        'status',
        'exception_required',
        'locked_by',
        'locked_at',
        'reopen_requested_by',
        'reopen_requested_at',
        'reopen_reason',
        'reopen_approved_by',
        'reopen_approved_at',
        'reopened_by',
        'reopened_at',
    ];

    /** @return Factory<FinancialPeriodLock> */
    protected static function newFactory(): Factory
    {
        return FinancialPeriodLockFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (FinancialPeriodLock $lock): void {
            if (! isset($lock->ulid)) {
                $lock->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => FinancialPeriodLockStatus::class,
            'exception_required' => 'boolean',
            'period_start' => 'date',
            'period_end' => 'date',
            'locked_at' => 'datetime',
            'reopen_requested_at' => 'datetime',
            'reopen_approved_at' => 'datetime',
            'reopened_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
