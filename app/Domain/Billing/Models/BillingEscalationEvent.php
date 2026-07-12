<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingEscalationEventType;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\BillingEscalationEventFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * BillingEscalationEvent — append-only overdue-escalation log (Plan §13.15, §54; Phase 20B).
 * Merchant-owned. APPEND-ONLY: `created_at` only (no `updated_at`); no UPDATE/DELETE path.
 * Gate B4 idempotency: UNIQUE(merchant_subscription_id, event_type, period_boundary).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int|null $subscription_invoice_id
 * @property int $merchant_subscription_id
 * @property BillingEscalationEventType $event_type
 * @property string|null $from_billing_status
 * @property string|null $to_billing_status
 * @property string|null $reason
 * @property Carbon $period_boundary
 * @property Carbon|null $created_at
 */
class BillingEscalationEvent extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<BillingEscalationEventFactory> */
    use HasFactory;

    /** Append-only: created_at only, no updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'merchant_id',
        'subscription_invoice_id',
        'merchant_subscription_id',
        'event_type',
        'from_billing_status',
        'to_billing_status',
        'reason',
        'period_boundary',
    ];

    /** @return Factory<BillingEscalationEvent> */
    protected static function newFactory(): Factory
    {
        return BillingEscalationEventFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (BillingEscalationEvent $event): void {
            if (! isset($event->ulid)) {
                $event->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => BillingEscalationEventType::class,
            'period_boundary' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return BelongsTo<MerchantSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MerchantSubscription::class, 'merchant_subscription_id');
    }

    /** @return BelongsTo<SubscriptionInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }
}
