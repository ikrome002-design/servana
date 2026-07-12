<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\PromotionalDiscountType;
use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Enums\WalletRegistrationStatus;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * SubscriptionInvoice — immutable issued invoice financial snapshot (Plan §13.9, §25.4, §49;
 * ADR-014; Phase 20B). Merchant-owned. Integer minor units. Cancellation terminology is `void`
 * only. Wallet columns are an orthogonal technical projection shipping at defaults in 20B
 * (null / unregistered); populated only in 20D-W.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $plan_id
 * @property int $price_id
 * @property string|null $invoice_number
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $total_minor
 * @property string $currency
 * @property int $balance_minor
 * @property int|null $promotional_discount_id
 * @property PromotionalDiscountType|null $promotion_type
 * @property int|null $promotion_value_snapshot
 * @property string|null $promotion_currency
 * @property CarbonImmutable|null $promotion_resolved_at
 * @property SubscriptionInvoiceStatus $status
 * @property string|null $account_reference
 * @property string|null $wallet_payment_id
 * @property WalletRegistrationStatus $wallet_registration_status
 * @property CarbonImmutable|null $wallet_registered_at
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $due_at
 * @property int|null $file_id
 * @property int $pdf_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SubscriptionInvoice extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<SubscriptionInvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'plan_id',
        'price_id',
        'invoice_number',
        'period_start',
        'period_end',
        'subtotal_minor',
        'discount_minor',
        'total_minor',
        'currency',
        'balance_minor',
        'promotional_discount_id',
        'promotion_type',
        'promotion_value_snapshot',
        'promotion_currency',
        'promotion_resolved_at',
        'status',
        'account_reference',
        'wallet_payment_id',
        'wallet_registration_status',
        'wallet_registered_at',
        'issued_at',
        'due_at',
        'file_id',
        'pdf_version',
    ];

    /** @return Factory<SubscriptionInvoice> */
    protected static function newFactory(): Factory
    {
        return SubscriptionInvoiceFactory::new();
    }

    /**
     * Financial fields that become immutable once the invoice leaves `draft` (Plan §49). The Wallet
     * projection columns, `status`, and `balance_minor` are intentionally NOT here — they are updated
     * by 20D-W registration/payment, not part of the financial snapshot (ADR-014).
     *
     * @var list<string>
     */
    private const IMMUTABLE_AFTER_ISSUE = [
        'plan_id', 'price_id', 'invoice_number', 'period_start', 'period_end',
        'subtotal_minor', 'discount_minor', 'total_minor', 'currency', 'issued_at', 'due_at',
        // Phase 20C — the promotion snapshot joins the immutable financial snapshot (Gate C4/C5).
        'promotional_discount_id', 'promotion_type', 'promotion_value_snapshot', 'promotion_currency',
        'promotion_resolved_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (SubscriptionInvoice $invoice): void {
            if (! isset($invoice->ulid)) {
                $invoice->ulid = (string) Str::ulid();
            }
        });

        // Defence-in-depth: an issued invoice's financial snapshot is immutable (Plan §49). The
        // primary protection is that no route mutates these fields; this guard makes an accidental
        // write fail loudly rather than corrupt a financial record.
        static::updating(function (SubscriptionInvoice $invoice): void {
            if ((string) $invoice->getRawOriginal('status') === SubscriptionInvoiceStatus::Draft->value) {
                return;
            }

            foreach (self::IMMUTABLE_AFTER_ISSUE as $field) {
                if ($invoice->isDirty($field)) {
                    throw new \DomainException("Issued subscription invoice field '{$field}' is immutable.");
                }
            }
        });

        static::deleting(function (): void {
            throw new \DomainException('Subscription invoices cannot be deleted (financial retention).');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'balance_minor' => 'integer',
            'promotional_discount_id' => 'integer',
            'promotion_type' => PromotionalDiscountType::class,
            'promotion_value_snapshot' => 'integer',
            'promotion_resolved_at' => 'immutable_datetime',
            'status' => SubscriptionInvoiceStatus::class,
            'wallet_registration_status' => WalletRegistrationStatus::class,
            'wallet_registered_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'pdf_version' => 'integer',
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

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /** @return BelongsTo<SubscriptionPlanPrice, $this> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlanPrice::class, 'price_id');
    }

    /** @return HasMany<SubscriptionInvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionInvoiceItem::class);
    }

    /** The current generated PDF (Phase 10F private file), if any. */
    /** @return BelongsTo<UploadedFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(UploadedFile::class, 'file_id');
    }

    /**
     * Whether the Wallet payment reference is available. While false, the PDF renders the
     * pending-reference placeholder and no account reference is shown (Plan §49; ADR-014).
     */
    public function hasWalletReference(): bool
    {
        return $this->wallet_registration_status === WalletRegistrationStatus::Registered
            && $this->account_reference !== null;
    }
}
