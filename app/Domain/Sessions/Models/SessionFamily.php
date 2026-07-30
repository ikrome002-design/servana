<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Models;

use App\Domain\Sessions\Enums\SessionRevocationReason;
use App\Domain\Tenancy\TenantOwnership;
use App\Models\User;
use Database\Factories\SessionFamilyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One user's related browser sessions across the eight account hosts (Phase UI-03; ADR-018).
 *
 * IDENTITY-OWNED, not tenant-owned: a family may legitimately span merchants, so it carries no
 * `merchant_id` and deliberately does not use `BelongsToMerchant` (registered EXEMPT in
 * {@see TenantOwnership}).
 *
 * The `ulid` is a public, NON-SECRET handle. It is not a credential and possessing one grants
 * nothing — every read surface still resolves the family from the authenticated user.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $environment
 * @property int $lifecycle_version
 * @property Carbon $last_activity_at
 * @property Carbon|null $revoked_at
 * @property SessionRevocationReason|null $revoked_reason
 * @property int|null $revoked_by_user_id
 */
class SessionFamily extends Model
{
    /** @use HasFactory<SessionFamilyFactory> */
    use HasFactory;

    /** @return Factory<SessionFamily> */
    protected static function newFactory(): Factory
    {
        return SessionFamilyFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'environment',
        'last_activity_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (SessionFamily $family): void {
            if (! isset($family->ulid)) {
                $family->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lifecycle_version' => 'integer',
            'last_activity_at' => 'datetime',
            'revoked_at' => 'datetime',
            'revoked_reason' => SessionRevocationReason::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<HostSession, $this> */
    public function hostSessions(): HasMany
    {
        return $this->hasMany(HostSession::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * @param  Builder<SessionFamily>  $query
     * @return Builder<SessionFamily>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
