<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Models;

use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryStatus;
use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Merchants\Models\Merchant;
use Database\Factories\ReOutboundEventFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ReOutboundEvent — one row of the Servana→R&E transactional outbox (Plan §13.17, §58A.2, §25.6;
 * §9 rule 22; Phase 21R-A; table `re_outbound_events`).
 *
 * Inserted in the SAME transaction as the source domain fact, so the two commit or roll back
 * together. `event_id`, `payload` and `content_sha256` are frozen at insert (DB trigger), which is
 * what makes "retry with the same event id and the same body hash" a guarantee rather than an
 * intention.
 *
 * Not `BelongsToMerchant`: `merchant_id` is nullable by design (§13.17 reserves null for
 * product-level events, of which there are none at launch), the rows are written from platform-side
 * integration code, and no merchant-facing route exposes them. See `TenantOwnership::EXEMPT`.
 *
 * @property int $id
 * @property string $ulid
 * @property string $event_id
 * @property ReOutboundEventType $event_type
 * @property string $event_version
 * @property int|null $merchant_id
 * @property string|null $merchant_public_id
 * @property int $sequence_no
 * @property array<string, mixed> $payload
 * @property string $content_sha256
 * @property Carbon $occurred_at
 * @property ReDeliveryStatus $delivery_status
 * @property Carbon|null $delivered_at
 * @property int $attempt_count
 * @property Carbon|null $next_attempt_at
 * @property int|null $last_response_status
 * @property string|null $last_error_code
 * @property Carbon|null $created_at
 */
class ReOutboundEvent extends Model
{
    /** @use HasFactory<ReOutboundEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 're_outbound_events';

    protected $fillable = [
        'event_id',
        'event_type',
        'event_version',
        'merchant_id',
        'merchant_public_id',
        'sequence_no',
        'payload',
        'content_sha256',
        'occurred_at',
        'delivery_status',
        'delivered_at',
        'attempt_count',
        'next_attempt_at',
        'last_response_status',
        'last_error_code',
    ];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return Factory<ReOutboundEvent> */
    protected static function newFactory(): Factory
    {
        return ReOutboundEventFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (ReOutboundEvent $event): void {
            if (! isset($event->ulid)) {
                $event->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => ReOutboundEventType::class,
            'sequence_no' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'delivery_status' => ReDeliveryStatus::class,
            'delivered_at' => 'datetime',
            'attempt_count' => 'integer',
            'next_attempt_at' => 'datetime',
            'last_response_status' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return HasMany<ReEventDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ReEventDelivery::class, 're_outbound_event_id');
    }
}
