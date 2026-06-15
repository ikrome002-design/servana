<?php

declare(strict_types=1);

namespace App\Domain\Branches\Models;

use App\Domain\Branches\Enums\BranchUserAssignmentStatus;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Database\Factories\BranchUserAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Branch scope for one membership (Plan §7.1, §8.2). A branch-scoped role needs
 * an `active` row here to touch a branch's data.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_user_id
 * @property int $branch_id
 * @property BranchUserAssignmentStatus $status
 * @property int|null $assigned_by
 * @property Carbon|null $assigned_at
 * @property Carbon|null $revoked_at
 */
class BranchUserAssignment extends Model
{
    /** @use HasFactory<BranchUserAssignmentFactory> */
    use HasFactory;

    /** @return Factory<BranchUserAssignment> */
    protected static function newFactory(): Factory
    {
        return BranchUserAssignmentFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_user_id',
        'branch_id',
        'status',
        'assigned_by',
        'assigned_at',
        'revoked_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (BranchUserAssignment $assignment): void {
            if (! isset($assignment->ulid)) {
                $assignment->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BranchUserAssignmentStatus::class,
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<MerchantUser, $this> */
    public function merchantUser(): BelongsTo
    {
        return $this->belongsTo(MerchantUser::class);
    }

    /** @return BelongsTo<MerchantBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @param  Builder<BranchUserAssignment>  $query
     * @return Builder<BranchUserAssignment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', BranchUserAssignmentStatus::Active->value);
    }
}
