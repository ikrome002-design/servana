<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Sessions\Enums\SessionRevocationReason;
use App\Models\User;
use Database\Factories\HostSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One Laravel browser session, bound to the account context it was created for
 * (Phase UI-03; ADR-018; UI/UX plan §5.2).
 *
 * `session_id` is HIDDEN from every array/JSON serialization: it is required to delete the row
 * from Laravel's `sessions` table on revocation, but it must never leave the server. The own-session
 * API returns the `ulid` instead.
 *
 * The context columns record WHICH membership/merchant/branch this session belongs to — never WHAT
 * it may do. There is no permission column and there never may be (ADR-018; the whole point of the
 * design is that the target host re-resolves authority from the database).
 *
 * @property int $id
 * @property string $ulid
 * @property int $session_family_id
 * @property int $user_id
 * @property string $session_id
 * @property string $account_key
 * @property string $host
 * @property string $environment
 * @property int|null $merchant_id
 * @property int|null $merchant_user_id
 * @property int|null $branch_id
 * @property bool $mfa_required_at_creation
 * @property Carbon $last_activity_at
 * @property Carbon|null $revoked_at
 * @property SessionRevocationReason|null $revoked_reason
 */
class HostSession extends Model
{
    /** @use HasFactory<HostSessionFactory> */
    use HasFactory;

    /** @return Factory<HostSession> */
    protected static function newFactory(): Factory
    {
        return HostSessionFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'session_family_id',
        'user_id',
        'session_id',
        'account_key',
        'host',
        'environment',
        'merchant_id',
        'merchant_user_id',
        'branch_id',
        'mfa_required_at_creation',
        'last_activity_at',
    ];

    /**
     * The raw Laravel session id never leaves the server (guardrail §6.4, UI/UX plan §18.7).
     *
     * @var list<string>
     */
    protected $hidden = ['session_id'];

    protected static function booted(): void
    {
        static::creating(function (HostSession $session): void {
            if (! isset($session->ulid)) {
                $session->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mfa_required_at_creation' => 'boolean',
            'last_activity_at' => 'datetime',
            'revoked_at' => 'datetime',
            'revoked_reason' => SessionRevocationReason::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<SessionFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(SessionFamily::class, 'session_family_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return BelongsTo<MerchantUser, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(MerchantUser::class, 'merchant_user_id');
    }

    /** @return BelongsTo<MerchantBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * @param  Builder<HostSession>  $query
     * @return Builder<HostSession>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
