<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Sessions\Enums\HandoffRejectionReason;
use App\Models\User;
use Database\Factories\AccountContextHandoffFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A single-use, short-lived, hashed credential that carries a user from one account host to
 * another (Phase UI-03; ADR-018 steps 3–10).
 *
 * `token_hash` is HIDDEN from serialization; the raw token is never stored at all.
 *
 * The `target_*` columns are a DESTINATION DESCRIPTION, not an authority grant. Every one of them
 * is re-verified against current database state inside the locked consume transaction — a role,
 * membership, branch assignment or merchant status that changed after issuance rejects the token.
 *
 * @property int $id
 * @property string $ulid
 * @property string $token_hash
 * @property int $user_id
 * @property int $source_session_family_id
 * @property int|null $source_host_session_id
 * @property string $source_account_key
 * @property string $target_account_key
 * @property string $target_host
 * @property string $environment
 * @property int|null $target_merchant_id
 * @property int|null $target_merchant_user_id
 * @property int|null $target_branch_id
 * @property string|null $redirect_path
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $invalidated_at
 * @property HandoffRejectionReason|null $invalidated_reason
 */
class AccountContextHandoff extends Model
{
    /** @use HasFactory<AccountContextHandoffFactory> */
    use HasFactory;

    /** @return Factory<AccountContextHandoff> */
    protected static function newFactory(): Factory
    {
        return AccountContextHandoffFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'token_hash',
        'user_id',
        'source_session_family_id',
        'source_host_session_id',
        'source_account_key',
        'target_account_key',
        'target_host',
        'environment',
        'target_merchant_id',
        'target_merchant_user_id',
        'target_branch_id',
        'redirect_path',
        'expires_at',
        'ip_hash',
        'user_agent_hash',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    protected static function booted(): void
    {
        static::creating(function (AccountContextHandoff $handoff): void {
            if (! isset($handoff->ulid)) {
                $handoff->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'invalidated_reason' => HandoffRejectionReason::class,
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

    /** @return BelongsTo<SessionFamily, $this> */
    public function sourceFamily(): BelongsTo
    {
        return $this->belongsTo(SessionFamily::class, 'source_session_family_id');
    }

    /** @return BelongsTo<HostSession, $this> */
    public function sourceHostSession(): BelongsTo
    {
        return $this->belongsTo(HostSession::class, 'source_host_session_id');
    }

    /** @return BelongsTo<Merchant, $this> */
    public function targetMerchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'target_merchant_id');
    }

    /** @return BelongsTo<MerchantUser, $this> */
    public function targetMembership(): BelongsTo
    {
        return $this->belongsTo(MerchantUser::class, 'target_merchant_user_id');
    }

    /** @return BelongsTo<MerchantBranch, $this> */
    public function targetBranch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'target_branch_id');
    }
}
