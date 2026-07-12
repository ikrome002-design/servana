<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Models;

use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\MerchantUserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Membership — a user's role+status within one merchant (Plan §7.1, §8.1).
 *
 * All authorization derives from an `Active` row here. ULID is the public id.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $user_id
 * @property MerchantUserRole $role
 * @property MerchantUserStatus $status
 * @property int|null $invited_by
 * @property int|null $last_branch_id
 * @property Carbon|null $activated_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MerchantUser extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<MerchantUserFactory> */
    use HasFactory;

    /** @return Factory<MerchantUser> */
    protected static function newFactory(): Factory
    {
        return MerchantUserFactory::new();
    }

    /**
     * Role/status/timestamps are set by onboarding/lifecycle code, never from
     * raw request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'user_id',
        'role',
        'status',
        'invited_by',
        'last_branch_id',
        'activated_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (MerchantUser $membership): void {
            if (! isset($membership->ulid)) {
                $membership->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MerchantUserRole::class,
            'status' => MerchantUserStatus::class,
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'deactivated_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MerchantBranch, $this> */
    public function lastBranch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'last_branch_id');
    }

    /** @return HasMany<BranchUserAssignment, $this> */
    public function branchAssignments(): HasMany
    {
        return $this->hasMany(BranchUserAssignment::class);
    }

    /** @return HasOne<StaffProfile, $this> */
    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    public function isActive(): bool
    {
        return $this->status === MerchantUserStatus::Active;
    }

    /** Merchant Admin sees all own-merchant branches and needs no assignment row. */
    public function isBranchScoped(): bool
    {
        return $this->role !== MerchantUserRole::MerchantAdmin;
    }

    /** True when this membership holds at least one active branch assignment. */
    public function hasActiveBranchAssignment(): bool
    {
        return $this->branchAssignments()->active()->exists();
    }

    /**
     * Active branch ids for this membership (TenantContext branch scope, §8.2).
     *
     * @return list<int>
     */
    public function activeBranchIds(): array
    {
        return array_values($this->branchAssignments()
            ->active()
            ->pluck('branch_id')
            ->map(static fn (int $id): int => $id)
            ->all());
    }

    /**
     * @param  Builder<MerchantUser>  $query
     * @return Builder<MerchantUser>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MerchantUserStatus::Active->value);
    }
}
