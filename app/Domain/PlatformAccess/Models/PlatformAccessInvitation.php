<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Models;

use App\Domain\PlatformAccess\Enums\PlatformAccessInvitationStatus;
use App\Models\User;
use Database\Factories\PlatformAccessInvitationFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PlatformAccessInvitation — the Magic Link-compatible credential that admits a person to internal
 * platform access (COR-UI08-001 §11.6; Phase UI-08). Lifecycle:
 * docs/architecture/state-machines/platform-access-invitation.md.
 *
 * `token_hash` IS A CREDENTIAL DIGEST AND IS HIDDEN. Only the SHA-256 hash is stored; the raw token
 * exists solely inside the emailed link. `$hidden` keeps it out of every serialization, and no
 * Resource, audit context or log line ever references it.
 *
 * @property int $id
 * @property string $ulid
 * @property string $email
 * @property string $role_key
 * @property string $purpose
 * @property string $environment
 * @property string $token_hash
 * @property PlatformAccessInvitationStatus $status
 * @property int $invited_by_user_id
 * @property int|null $accepted_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property string|null $revocation_reason
 * @property int $resend_count
 * @property Carbon|null $last_sent_at
 */
class PlatformAccessInvitation extends Model
{
    /** @use HasFactory<PlatformAccessInvitationFactory> */
    use HasFactory;

    /** Purpose binding: this credential can grant nothing else. */
    public const PURPOSE = 'platform_access';

    /** Matches `staff_invitations` — 72 hours. */
    public const EXPIRY_HOURS = 72;

    protected $table = 'platform_access_invitations';

    protected $fillable = [
        'email',
        'role_key',
        'purpose',
        'environment',
        'token_hash',
        'status',
        'invited_by_user_id',
        'accepted_user_id',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
        'resend_count',
        'last_sent_at',
    ];

    /** Never serialized, never logged, never audited. */
    protected $hidden = ['token_hash'];

    /** @return Factory<PlatformAccessInvitation> */
    protected static function newFactory(): Factory
    {
        return PlatformAccessInvitationFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformAccessInvitation $invitation): void {
            if (! isset($invitation->ulid)) {
                $invitation->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PlatformAccessInvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'resend_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** Redeemable right now: still pending AND not past its expiry. */
    public function isRedeemable(): bool
    {
        return $this->status === PlatformAccessInvitationStatus::Pending
            && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }
}
