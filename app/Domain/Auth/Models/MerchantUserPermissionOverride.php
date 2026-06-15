<?php

declare(strict_types=1);

namespace App\Domain\Auth\Models;

use App\Domain\Auth\Enums\PermissionOverrideEffect;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A per-membership grant/deny override (Plan §10.3). Deny beats grant.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_user_id
 * @property int $permission_id
 * @property PermissionOverrideEffect $effect
 * @property int|null $granted_by
 * @property string|null $reason
 */
class MerchantUserPermissionOverride extends Model
{
    protected $fillable = [
        'merchant_user_id',
        'permission_id',
        'effect',
        'granted_by',
        'reason',
    ];

    protected static function booted(): void
    {
        static::creating(function (MerchantUserPermissionOverride $override): void {
            if (! isset($override->ulid)) {
                $override->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effect' => PermissionOverrideEffect::class,
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

    /** @return BelongsTo<Permission, $this> */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * @param  Builder<MerchantUserPermissionOverride>  $query
     * @return Builder<MerchantUserPermissionOverride>
     */
    public function scopeGrants(Builder $query): Builder
    {
        return $query->where('effect', PermissionOverrideEffect::Grant->value);
    }

    /**
     * @param  Builder<MerchantUserPermissionOverride>  $query
     * @return Builder<MerchantUserPermissionOverride>
     */
    public function scopeDenies(Builder $query): Builder
    {
        return $query->where('effect', PermissionOverrideEffect::Deny->value);
    }
}
