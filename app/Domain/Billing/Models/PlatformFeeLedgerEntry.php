<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Enums\PlatformFeeBasisType;
use App\Domain\Billing\Enums\PlatformFeeEntryType;
use App\Domain\Billing\Enums\PlatformFeeLedgerStatus;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Carbon\CarbonImmutable;
use Database\Factories\PlatformFeeLedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * PlatformFeeLedgerEntry — append-only percentage platform-fee ledger fact (Plan §13.10, §51;
 * Phase 20E). TENANT-OWNED (BelongsToMerchant; optional nullable branch_id). The original `earned`
 * row is created at Finance validation; `reversal`/`adjustment` are additive rows. Monetary/snapshot
 * columns are immutable at the database (trigger); only `status` and `subscription_invoice_item_id`
 * transition. Append-only: no `updated_at` (UPDATED_AT disabled). Money is integer minor units.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int|null $branch_id
 * @property int $source_invoice_id
 * @property int|null $source_invoice_item_id
 * @property PlatformFeeEntryType $entry_type
 * @property PlatformFeeLedgerStatus $status
 * @property BillingMode $billing_mode_snapshot
 * @property CanonicalPlatformFeeTier $service_fee_tier_snapshot
 * @property PlatformFeeBasisType $fee_basis_type
 * @property int $fee_basis_amount_minor
 * @property int $percentage_rate_snapshot
 * @property int|null $shared_split_snapshot
 * @property int $gross_platform_fee_minor
 * @property int $client_shifted_amount_minor
 * @property int $merchant_absorbed_amount_minor
 * @property int $merchant_liability_minor
 * @property string $currency
 * @property int $effective_configuration_id
 * @property int|null $subscription_invoice_item_id
 * @property int|null $reversed_entry_id
 * @property int|null $source_validation_event_id
 * @property string|null $idempotency_key
 * @property CarbonImmutable|null $billable_at
 * @property CarbonImmutable|null $created_at
 */
class PlatformFeeLedgerEntry extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<PlatformFeeLedgerEntryFactory> */
    use HasFactory;

    /** Append-only: created_at only, no updated_at. */
    public const UPDATED_AT = null;

    /**
     * Only lifecycle status and the aggregation link may change after insert (the DB trigger
     * enforces this); monetary/snapshot columns are guarded and NOT mass-assignable post-creation.
     */
    protected $fillable = [
        'merchant_id',
        'branch_id',
        'source_invoice_id',
        'source_invoice_item_id',
        'entry_type',
        'status',
        'billing_mode_snapshot',
        'service_fee_tier_snapshot',
        'fee_basis_type',
        'fee_basis_amount_minor',
        'percentage_rate_snapshot',
        'shared_split_snapshot',
        'gross_platform_fee_minor',
        'client_shifted_amount_minor',
        'merchant_absorbed_amount_minor',
        'merchant_liability_minor',
        'currency',
        'effective_configuration_id',
        'subscription_invoice_item_id',
        'reversed_entry_id',
        'source_validation_event_id',
        'idempotency_key',
        'billable_at',
    ];

    /** @return Factory<PlatformFeeLedgerEntry> */
    protected static function newFactory(): Factory
    {
        return PlatformFeeLedgerEntryFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformFeeLedgerEntry $entry): void {
            if (! isset($entry->ulid)) {
                $entry->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'entry_type' => PlatformFeeEntryType::class,
            'status' => PlatformFeeLedgerStatus::class,
            'billing_mode_snapshot' => BillingMode::class,
            'service_fee_tier_snapshot' => CanonicalPlatformFeeTier::class,
            'fee_basis_type' => PlatformFeeBasisType::class,
            'fee_basis_amount_minor' => 'integer',
            'percentage_rate_snapshot' => 'integer',
            'shared_split_snapshot' => 'integer',
            'gross_platform_fee_minor' => 'integer',
            'client_shifted_amount_minor' => 'integer',
            'merchant_absorbed_amount_minor' => 'integer',
            'merchant_liability_minor' => 'integer',
            'billable_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
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

    /** @return BelongsTo<MerchantBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id');
    }

    /** @return BelongsTo<InvoiceItem, $this> */
    public function sourceInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'source_invoice_item_id');
    }

    /** @return BelongsTo<PlatformFeeConfiguration, $this> */
    public function effectiveConfiguration(): BelongsTo
    {
        return $this->belongsTo(PlatformFeeConfiguration::class, 'effective_configuration_id');
    }

    /** @return BelongsTo<SubscriptionInvoiceItem, $this> */
    public function subscriptionInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoiceItem::class, 'subscription_invoice_item_id');
    }

    /** @return BelongsTo<PlatformFeeLedgerEntry, $this> */
    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id');
    }

    /** @return BelongsTo<PaymentValidationEvent, $this> */
    public function sourceValidationEvent(): BelongsTo
    {
        return $this->belongsTo(PaymentValidationEvent::class, 'source_validation_event_id');
    }
}
