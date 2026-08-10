<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Models;

use App\Domain\Auth\Models\Permission;
use App\Models\User;
use Database\Factories\PlatformAccessPermissionOverrideFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * PlatformAccessPermissionOverride — a DENY-only, platform-scope override (COR-UI08-001 §11).
 *
 * There is no grant effect and there is no grant column. An override can only ever subtract from
 * the `super_admin` default grants, which is what makes self-escalation structurally impossible
 * rather than merely policed; a database trigger additionally rejects any permission whose
 * `permissions.category` is not `platform`.
 *
 * @property int $id
 * @property string $ulid
 * @property int $platform_access_membership_id
 * @property int $permission_id
 * @property string $effect
 * @property string $reason
 * @property int $created_by_user_id
 */
class PlatformAccessPermissionOverride extends Model
{
    /** @use HasFactory<PlatformAccessPermissionOverrideFactory> */
    use HasFactory;

    /** The only representable effect at launch. */
    public const EFFECT_DENY = 'deny';

    protected $table = 'platform_access_permission_overrides';

    protected $fillable = [
        'platform_access_membership_id',
        'permission_id',
        'effect',
        'reason',
        'created_by_user_id',
    ];

    /** @return Factory<PlatformAccessPermissionOverride> */
    protected static function newFactory(): Factory
    {
        return PlatformAccessPermissionOverrideFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformAccessPermissionOverride $override): void {
            if (! isset($override->ulid)) {
                $override->ulid = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<PlatformAccessMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(PlatformAccessMembership::class, 'platform_access_membership_id');
    }

    /** @return BelongsTo<Permission, $this> */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
