<?php

declare(strict_types=1);

namespace App\Domain\Hr\Models;

use App\Domain\Hr\Enums\StaffHistoryField;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Append-only staff history (Plan §7.1, Scope §3.4). Only `created_at`; no
 * UPDATE/DELETE path exists. Records old/new value as JSON + actor + reason.
 *
 * @property int $id
 * @property string $ulid
 * @property int $staff_profile_id
 * @property StaffHistoryField $field
 * @property mixed $old_value
 * @property mixed $new_value
 * @property int|null $changed_by
 * @property string|null $reason
 * @property string $approval_status
 */
class StaffHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'staff_history';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'staff_profile_id',
        'field',
        'old_value',
        'new_value',
        'changed_by',
        'reason',
        'approval_status',
    ];

    protected static function booted(): void
    {
        static::creating(function (StaffHistory $history): void {
            if (! isset($history->ulid)) {
                $history->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field' => StaffHistoryField::class,
            'old_value' => 'array',
            'new_value' => 'array',
        ];
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
