<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Models;

use App\Domain\PlatformAccess\Enums\PlatformAccessStatus;
use App\Models\User;
use Database\Factories\PlatformAccessMembershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PlatformAccessMembership — the lifecycle authority for internal Citrus Labs platform access
 * (COR-UI08-001 §11; Phase UI-08). Lifecycle:
 * docs/architecture/state-machines/platform-access-membership.md.
 *
 * PLATFORM-OWNED. It carries no merchant, branch or staff-profile column and never may: a platform
 * administrator holds no merchant structure of any kind.
 *
 * `users.is_platform_staff` is a DERIVED MIRROR of `status = 'active'`, written in the same
 * transaction as every transition, so the shipped eligibility, context and MFA paths keep reading
 * the flag unchanged.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $role_key
 * @property PlatformAccessStatus $status
 * @property int|null $invitation_id
 * @property int|null $invited_by_user_id
 * @property Carbon|null $invited_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $deactivated_at
 * @property string|null $last_action
 * @property string|null $last_action_reason
 * @property int|null $last_action_by_user_id
 * @property Carbon|null $last_action_at
 */
class PlatformAccessMembership extends Model
{
    /** @use HasFactory<PlatformAccessMembershipFactory> */
    use HasFactory;

    /** The only platform role at launch (COR-UI08-001 §11.1). */
    public const ROLE_SUPER_ADMIN = 'super_admin';

    protected $table = 'platform_access_memberships';

    protected $fillable = [
        'user_id',
        'role_key',
        'status',
        'invitation_id',
        'invited_by_user_id',
        'invited_at',
        'activated_at',
        'suspended_at',
        'deactivated_at',
        'last_action',
        'last_action_reason',
        'last_action_by_user_id',
        'last_action_at',
    ];

    /** @return Factory<PlatformAccessMembership> */
    protected static function newFactory(): Factory
    {
        return PlatformAccessMembershipFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformAccessMembership $membership): void {
            if (! isset($membership->ulid)) {
                $membership->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PlatformAccessStatus::class,
            'invited_at' => 'datetime',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'last_action_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @param  Builder<PlatformAccessMembership>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', PlatformAccessStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === PlatformAccessStatus::Active;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<PlatformAccessInvitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(PlatformAccessInvitation::class, 'invitation_id');
    }

    /** @return HasMany<PlatformAccessPermissionOverride, $this> */
    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(PlatformAccessPermissionOverride::class, 'platform_access_membership_id');
    }
}
