<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\SubscriptionInvoiceItemType;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\SubscriptionInvoiceItemFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * SubscriptionInvoiceItem — immutable invoice line item (Plan §13.9, §49; Phase 20B).
 * Merchant-owned (denormalized merchant_id for tenant isolation, matching invoice_items).
 * Created at issuance; never edited/deleted. Phase 20B fixed mode issues a single `plan_fee`
 * line = captured plan price; no percentage/SMS/promotion/Wallet amounts are fabricated.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $subscription_invoice_id
 * @property string $description
 * @property int $amount_minor
 * @property SubscriptionInvoiceItemType $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SubscriptionInvoiceItem extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<SubscriptionInvoiceItemFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'subscription_invoice_id',
        'description',
        'amount_minor',
        'type',
    ];

    /** @return Factory<SubscriptionInvoiceItem> */
    protected static function newFactory(): Factory
    {
        return SubscriptionInvoiceItemFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (SubscriptionInvoiceItem $item): void {
            if (! isset($item->ulid)) {
                $item->ulid = (string) Str::ulid();
            }
        });

        // Line items are immutable once created and never deleted (Plan §49; financial retention).
        static::updating(function (): void {
            throw new \DomainException('Subscription invoice items are immutable.');
        });

        static::deleting(function (): void {
            throw new \DomainException('Subscription invoice items cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'type' => SubscriptionInvoiceItemType::class,
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

    /** @return BelongsTo<SubscriptionInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }
}
