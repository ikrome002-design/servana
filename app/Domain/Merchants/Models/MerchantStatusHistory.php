<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Models;

use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Append-only merchant status transition trail (Scope §5.1).
 *
 * Only `created_at` is tracked (no `updated_at`) — rows are never mutated.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $reason
 * @property int|null $changed_by
 */
class MerchantStatusHistory extends Model
{
    use BelongsToMerchant;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'from_status',
        'to_status',
        'reason',
        'changed_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (MerchantStatusHistory $history): void {
            if (! isset($history->ulid)) {
                $history->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
