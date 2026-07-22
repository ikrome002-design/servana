<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Models;

use App\Domain\Integrations\ReferEarn\Enums\ReferralCaptureChannel;
use App\Domain\Integrations\ReferEarn\Enums\ReferralSnapshotStatus;
use App\Domain\Merchants\Models\Merchant;
use Database\Factories\ReferralSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ReferralSnapshot — Servana's immutable local evidence that a merchant registered with a referral
 * code (Plan §13.17, §58A.1, §25.6; ADR-013; Phase 21R-A; table `referral_snapshots`).
 *
 * At most one row per merchant, written inside the public self-registration transaction. It exists so
 * a referrer's legitimate claim survives R&E being briefly unavailable at registration.
 *
 * **No referrer identity is stored here, ever** — Servana holds the submitted code (encrypted), its
 * normalized form, and R&E's opaque public attribution id. It deliberately does NOT use
 * `BelongsToMerchant`: the row is created on a public, unauthenticated route where no TenantContext
 * can exist, and it is read only by platform-side R&E jobs. No merchant-facing route exposes it, and
 * `TenantOwnership::EXEMPT` records that rationale.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property string $raw_code_encrypted
 * @property string|null $code_normalized
 * @property ReferralCaptureChannel $capture_channel
 * @property Carbon $captured_at
 * @property array<string, string>|null $landing_metadata
 * @property ReferralSnapshotStatus $snapshot_status
 * @property string|null $re_validation_result_code
 * @property string|null $re_attribution_public_id
 * @property Carbon|null $confirmed_at
 * @property Carbon $last_transition_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReferralSnapshot extends Model
{
    /** @use HasFactory<ReferralSnapshotFactory> */
    use HasFactory;

    protected $table = 'referral_snapshots';

    protected $fillable = [
        'merchant_id',
        'raw_code_encrypted',
        'code_normalized',
        'capture_channel',
        'captured_at',
        'landing_metadata',
        'snapshot_status',
        're_validation_result_code',
        're_attribution_public_id',
        'confirmed_at',
        'last_transition_at',
    ];

    /**
     * The decrypted referral code is Plan §24.5 redacted material. Hiding it keeps it out of
     * `toArray()`, log context, exception dumps and any accidental serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['raw_code_encrypted'];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return Factory<ReferralSnapshot> */
    protected static function newFactory(): Factory
    {
        return ReferralSnapshotFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (ReferralSnapshot $snapshot): void {
            if (! isset($snapshot->ulid)) {
                $snapshot->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'raw_code_encrypted' => 'encrypted',
            'capture_channel' => ReferralCaptureChannel::class,
            'captured_at' => 'datetime',
            'landing_metadata' => 'array',
            'snapshot_status' => ReferralSnapshotStatus::class,
            'confirmed_at' => 'datetime',
            'last_transition_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Emission-scope gate (Plan §58B.1). A merchant with no snapshot, a malformed code, or a
     * rejected claim streams nothing to R&E.
     */
    public function permitsEventEmission(): bool
    {
        return $this->snapshot_status->permitsEventEmission();
    }
}
