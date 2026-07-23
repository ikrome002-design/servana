<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Domain\Messaging\Sms\Services\PersonnelSmsCampaignStateMachine;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Domain\Tenancy\TenantOwnership;
use App\Enums\Currency;
use App\Models\User;
use App\Support\Money;
use Database\Factories\PersonnelSmsCampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PersonnelSmsCampaign — a Personnel user's bulk SMS to clients they personally served
 * (Plan §13.13, §64; Phase 21S; ADR-010). Branch-owned ({@see TenantOwnership::BRANCH_OWNED});
 * the ULID is the public id + route key.
 *
 * OWN SCOPE: `staff_profile_id` is derived from the authenticated membership when the draft is
 * created and is never client-supplied; a composite FK to `staff_profiles(id, merchant_id)` makes a
 * cross-merchant subject impossible in the database.
 *
 * CONTACT PROTECTION (ADR-010): the campaign holds NO contact of any kind — not even masked.
 * Recipients live in {@see PersonnelSmsRecipient}. `message_body_encrypted` is encrypted at rest
 * and `$hidden`, because a personnel-authored message may name a client; it is never logged, never
 * placed in an audit context and never returned by a collection Resource.
 *
 * Status transitions go through {@see PersonnelSmsCampaignStateMachine} + the named domain
 * actions — never assigned directly.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $staff_profile_id
 * @property string $message_body_encrypted decrypted plaintext (encrypted at rest)
 * @property int|null $message_template_id
 * @property int $recipient_count
 * @property int $message_character_count
 * @property int $segment_count
 * @property int $estimated_cost_minor
 * @property int|null $final_cost_minor
 * @property string $currency
 * @property PersonnelSmsCampaignStatus $status
 * @property string|null $failure_reason_code
 * @property Carbon|null $consent_snapshot_at
 * @property int $created_by
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $queued_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 */
class PersonnelSmsCampaign extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<PersonnelSmsCampaignFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'staff_profile_id',
        'message_body_encrypted',
        'message_template_id',
        'recipient_count',
        'message_character_count',
        'segment_count',
        'estimated_cost_minor',
        'final_cost_minor',
        'currency',
        'status',
        'failure_reason_code',
        'consent_snapshot_at',
        'created_by',
        'confirmed_at',
        'queued_at',
        'completed_at',
        'cancelled_at',
    ];

    /**
     * The message body never serializes. A personnel-authored message may name a client, so
     * Plan §24.5 keeps it out of every array/JSON representation and every log line.
     *
     * @var list<string>
     */
    protected $hidden = [
        'message_body_encrypted',
    ];

    /** @return Factory<PersonnelSmsCampaign> */
    protected static function newFactory(): Factory
    {
        return PersonnelSmsCampaignFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PersonnelSmsCampaign $campaign): void {
            if (! isset($campaign->ulid)) {
                $campaign->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // AES-256-GCM at rest (APP_KEY); decrypted transparently on read.
            'message_body_encrypted' => 'encrypted',
            'status' => PersonnelSmsCampaignStatus::class,
            'message_template_id' => 'integer',
            'recipient_count' => 'integer',
            'message_character_count' => 'integer',
            'segment_count' => 'integer',
            'estimated_cost_minor' => 'integer',
            'final_cost_minor' => 'integer',
            'consent_snapshot_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'queued_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** Server-authoritative estimated cost as a Money value object (ADR-005 integer minor units). */
    public function estimatedCost(): Money
    {
        return Money::ofMinor($this->estimated_cost_minor, Currency::from($this->currency));
    }

    /** Final cost once the campaign has settled, or null while it is still in flight. */
    public function finalCost(): ?Money
    {
        return $this->final_cost_minor === null
            ? null
            : Money::ofMinor($this->final_cost_minor, Currency::from($this->currency));
    }

    /**
     * Campaigns still doing delivery work.
     *
     * @param  Builder<PersonnelSmsCampaign>  $query
     */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', [
            PersonnelSmsCampaignStatus::Queued->value,
            PersonnelSmsCampaignStatus::Sending->value,
            PersonnelSmsCampaignStatus::PartiallyFailed->value,
        ]);
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

    /** @return BelongsTo<StaffProfile, $this> */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PersonnelSmsRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(PersonnelSmsRecipient::class, 'campaign_id');
    }

    /** @return HasMany<SmsBillingEntry, $this> */
    public function billingEntries(): HasMany
    {
        return $this->hasMany(SmsBillingEntry::class, 'campaign_id');
    }
}
