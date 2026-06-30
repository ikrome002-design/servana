<?php

declare(strict_types=1);

namespace App\Domain\Branches\Models;

use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\BranchDayRecordFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Branch business-day open/close record (Plan §7.2, Scope §3.3).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property Carbon $business_date
 * @property BranchDayStatus $status
 * @property int|null $opened_by
 * @property Carbon|null $opened_at
 * @property int|null $closed_by
 * @property Carbon|null $closed_at
 * @property string|null $reopened_reason
 * @property array<string, mixed>|null $summary
 * @property bool $queue_is_open
 * @property int|null $queue_capacity
 * @property QueueAssignmentMode $queue_default_assignment_mode
 */
class BranchDayRecord extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<BranchDayRecordFactory> */
    use HasFactory;

    /** @return Factory<BranchDayRecord> */
    protected static function newFactory(): Factory
    {
        return BranchDayRecordFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'branch_id',
        'business_date',
        'status',
        'opened_by',
        'opened_at',
        'closed_by',
        'closed_at',
        'reopened_reason',
        'summary',
        'queue_is_open',
        'queue_capacity',
        'queue_default_assignment_mode',
    ];

    protected static function booted(): void
    {
        static::creating(function (BranchDayRecord $record): void {
            if (! isset($record->ulid)) {
                $record->ulid = (string) Str::ulid();
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
            'status' => BranchDayStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'summary' => 'array',
            'queue_is_open' => 'boolean',
            'queue_capacity' => 'integer',
            'queue_default_assignment_mode' => QueueAssignmentMode::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * The effective queue is open only when the day is operationally open AND the
     * Branch Manager has opened the queue (Plan §37). A paused/closed/not-opened
     * day is effectively a closed queue regardless of the flag.
     */
    public function effectiveQueueOpen(): bool
    {
        return $this->status === BranchDayStatus::Open && $this->queue_is_open;
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
