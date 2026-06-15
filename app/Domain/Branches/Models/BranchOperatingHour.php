<?php

declare(strict_types=1);

namespace App\Domain\Branches\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Weekly operating hours, one row per weekday per branch (Plan §7.2, Scope §3.3).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $weekday
 * @property string|null $opens_at
 * @property string|null $closes_at
 * @property bool $is_closed
 * @property string|null $break_start
 * @property string|null $break_end
 */
class BranchOperatingHour extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'weekday',
        'opens_at',
        'closes_at',
        'is_closed',
        'break_start',
        'break_end',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_closed' => 'boolean',
        ];
    }

    /** @return BelongsTo<MerchantBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }
}
