<?php

declare(strict_types=1);

namespace App\Domain\Hr\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Database\Factories\StaffInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Staff invitation (Plan §7.1, Scope §3.4). Only the SHA-256 hash of the raw
 * token is stored (Plan §3 rule 14); the raw token lives only in the email link.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property string $email
 * @property MerchantUserRole $role
 * @property string|null $role_title
 * @property array<int, int>|null $service_eligibility_ids
 * @property string $token_hash
 * @property StaffInvitationStatus $status
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property int $resend_count
 * @property Carbon|null $last_sent_at
 */
class StaffInvitation extends Model
{
    /** @use HasFactory<StaffInvitationFactory> */
    use HasFactory;

    /**
     * Mirror DB defaults so a freshly created instance has them in memory before
     * refresh (the status cast would otherwise be null).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'resend_count' => 0,
    ];

    /** @return Factory<StaffInvitation> */
    protected static function newFactory(): Factory
    {
        return StaffInvitationFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'branch_id',
        'email',
        'role',
        'role_title',
        'service_eligibility_ids',
        'token_hash',
        'status',
        'invited_by',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'resend_count',
        'last_sent_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (StaffInvitation $invitation): void {
            if (! isset($invitation->ulid)) {
                $invitation->ulid = (string) Str::ulid();
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
            'status' => StaffInvitationStatus::class,
            'service_eligibility_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function isPending(): bool
    {
        return $this->status === StaffInvitationStatus::Pending;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return BelongsTo<MerchantBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @param  Builder<StaffInvitation>  $query
     * @return Builder<StaffInvitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', StaffInvitationStatus::Pending->value);
    }
}
