<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Merchants\Models\MerchantUser;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $status
 * @property bool $is_platform_staff
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** User-level lifecycle states (Plan §7.1; auth checks use these in §9.1). */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DEACTIVATED = 'deactivated';

    /**
     * The attributes that are mass assignable.
     *
     * `password` is intentionally absent: Servana has no passwords (Plan A3).
     * `status` and `ulid` are set by lifecycle/auth code, never mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Assign a ULID on creation (A5: public identifier, never the bigint PK).
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! isset($user->ulid)) {
                $user->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_platform_staff' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /** True only when the user is active at the user level (Scope §2.3 checks 3 & 5). */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Route-model / public references use the ULID, never the PK (A5). */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Merchant memberships (Plan §7.1). Launch rule is one membership per user,
     * but the relation is hasMany so the single active row is resolved explicitly.
     *
     * @return HasMany<MerchantUser, $this>
     */
    public function merchantUsers(): HasMany
    {
        return $this->hasMany(MerchantUser::class);
    }

    /**
     * The single active membership, if any (Plan §8.1). Drives tenant context
     * resolution and Magic Link eligibility check 4.
     */
    public function activeMembership(): ?MerchantUser
    {
        return $this->merchantUsers()
            ->active()
            ->with('merchant')
            ->first();
    }

    /** Eligibility check 2 (Scope §2.3): active merchant membership OR platform staff. */
    public function hasTenantAccess(): bool
    {
        return $this->is_platform_staff || $this->merchantUsers()->active()->exists();
    }
}
