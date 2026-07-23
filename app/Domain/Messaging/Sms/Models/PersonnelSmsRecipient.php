<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Clients\Models\Client;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Messaging\Sms\Actions\DeliverSmsRecipient;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Enums\SmsConsentSnapshotStatus;
use App\Domain\Messaging\Sms\Services\PersonnelSmsRecipientStateMachine;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Http\Resources\PersonnelSmsRecipientResource;
use Database\Factories\PersonnelSmsRecipientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * PersonnelSmsRecipient — the immutable per-recipient snapshot of a campaign (Plan §13.13, §64;
 * Phase 21S; ADR-010). Branch-owned.
 *
 * CONTACT PROTECTION — this class is the single place in the product where a full client phone
 * number is reachable for SMS, and every guard here exists to keep it there:
 *
 *   - `phone_encrypted` is the DELIVERY SNAPSHOT only: encrypted at rest, `$hidden` so it can
 *     never reach an array/JSON/log representation, and read solely by
 *     {@see DeliverSmsRecipient} immediately before the provider
 *     adapter call. It is NULL for a recipient excluded at confirm — a suppressed or opted-out
 *     client never has their number snapshotted at all (Plan §74 data minimization).
 *   - `phone_last_four` is the MAXIMUM display identifier; {@see maskedPhone()} is the only
 *     rendering helper and it never returns more.
 *   - `eligibility_snapshot_json` carries safe ids/statuses/reason codes only; a DB CHECK rejects
 *     a `phone`/`msisdn`/`phone_number`/`phone_encrypted` key outright.
 *   - There is deliberately NO scope, accessor, relation or helper on this model that returns a
 *     collection of phone numbers. Bulk reads go through
 *     {@see PersonnelSmsRecipientResource}, which exposes the masked form only.
 *
 * Delivery-status transitions go through {@see PersonnelSmsRecipientStateMachine}; the snapshot
 * columns are frozen by `personnel_sms_recipients_guard` and DELETE is blocked by
 * `personnel_sms_recipients_no_delete`.
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $campaign_id
 * @property int $client_id
 * @property int|null $service_session_id
 * @property string|null $phone_encrypted decrypted plaintext (encrypted at rest) — DELIVERY ONLY; null for a recipient excluded at confirm
 * @property string $phone_last_four
 * @property array<string, mixed> $eligibility_snapshot_json
 * @property SmsConsentSnapshotStatus $consent_status_snapshot
 * @property PersonnelSmsRecipientDeliveryStatus $delivery_status
 * @property string|null $provider_message_id
 * @property int|null $cost_minor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PersonnelSmsRecipient extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<PersonnelSmsRecipientFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'campaign_id',
        'client_id',
        'service_session_id',
        'phone_encrypted',
        'phone_last_four',
        'eligibility_snapshot_json',
        'consent_status_snapshot',
        'delivery_status',
        'provider_message_id',
        'cost_minor',
    ];

    /**
     * The delivery phone snapshot never serializes — not in a Resource, not in a log line, not in
     * an audit context, not in an exception payload (ADR-010, Plan §24.5, §74).
     *
     * @var list<string>
     */
    protected $hidden = [
        'phone_encrypted',
    ];

    /** @return Factory<PersonnelSmsRecipient> */
    protected static function newFactory(): Factory
    {
        return PersonnelSmsRecipientFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // AES-256-GCM at rest (APP_KEY); decrypted transparently on read.
            'phone_encrypted' => 'encrypted',
            'eligibility_snapshot_json' => 'array',
            'consent_status_snapshot' => SmsConsentSnapshotStatus::class,
            'delivery_status' => PersonnelSmsRecipientDeliveryStatus::class,
            'cost_minor' => 'integer',
        ];
    }

    /** Masked phone for display (e.g. "••• ••• 1234") — never the full number (ADR-010). */
    public function maskedPhone(): string
    {
        return '••• ••• '.$this->phone_last_four;
    }

    /**
     * Recipients that still have delivery work outstanding.
     *
     * @param  Builder<PersonnelSmsRecipient>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('delivery_status', array_map(
            static fn (PersonnelSmsRecipientDeliveryStatus $s): string => $s->value,
            PersonnelSmsRecipientDeliveryStatus::outstandingStatuses(),
        ));
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

    /** @return BelongsTo<PersonnelSmsCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PersonnelSmsCampaign::class, 'campaign_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** The completed session that evidences the served-client relationship. */
    /** @return BelongsTo<ServiceSession, $this> */
    public function serviceSession(): BelongsTo
    {
        return $this->belongsTo(ServiceSession::class, 'service_session_id');
    }

    /** @return HasMany<SmsDeliveryAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(SmsDeliveryAttempt::class, 'recipient_id');
    }
}
