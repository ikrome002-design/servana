<?php

declare(strict_types=1);

namespace App\Domain\Branches\Models;

use App\Domain\Branches\Enums\CalendarExceptionType;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Date-specific branch closure / modified hours (Plan §7.2, Scope §3.3).
 *
 * @property int $id
 * @property int $branch_id
 * @property Carbon $date
 * @property CalendarExceptionType $type
 * @property string|null $opens_at
 * @property string|null $closes_at
 * @property string|null $reason
 * @property int|null $created_by
 */
class BranchCalendarException extends Model
{
    use BelongsToBranch;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'date',
        'type',
        'opens_at',
        'closes_at',
        'reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => CalendarExceptionType::class,
        ];
    }

    /** @return BelongsTo<MerchantBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }
}
