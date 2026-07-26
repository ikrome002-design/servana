<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Services\InvoiceStateMachine;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Search\Concerns\SearchableDocument;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Invoice — a merchant-client invoice (Plan §13.8, §40, §25.3; Phase 17).
 * Branch-owned; the ULID is the public id + route key. Front Office drafts and
 * finalizes (number allocated at `draft → issued`, prices/preferred-fee/percentage
 * config snapshotted); Finance voids/adjusts (additive, non-destructive). Status
 * transitions go through {@see InvoiceStateMachine} + the named domain actions —
 * never assigned directly.
 *
 * Money is integer minor units. Finalized monetary snapshots and the invoice
 * number are immutable. `validated_paid_minor` is written only by the Phase-18B
 * validated-payment workflow.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $client_id
 * @property string|null $invoice_number
 * @property InvoiceStatus $status
 * @property InvoiceStatus|null $previous_status
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $tax_minor
 * @property int|null $preferred_personnel_fee_snapshot_minor
 * @property int $total_minor
 * @property int $validated_paid_minor
 * @property string $currency
 * @property array<string, mixed>|null $percentage_fee_config_snapshot
 * @property Carbon|null $finalized_at
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property Carbon|null $adjusted_at
 * @property int|null $adjusted_by
 * @property string|null $adjustment_reason
 * @property int|null $adjustment_of_invoice_id
 * @property int|null $created_by
 */
class Invoice extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use SearchableDocument;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'client_id',
        'invoice_number',
        'status',
        'previous_status',
        'subtotal_minor',
        'discount_minor',
        'tax_minor',
        'preferred_personnel_fee_snapshot_minor',
        'total_minor',
        'validated_paid_minor',
        'currency',
        'percentage_fee_config_snapshot',
        'finalized_at',
        'voided_at',
        'voided_by',
        'void_reason',
        'adjusted_at',
        'adjusted_by',
        'adjustment_reason',
        'adjustment_of_invoice_id',
        'created_by',
    ];

    /** @return Factory<Invoice> */
    protected static function newFactory(): Factory
    {
        return InvoiceFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice): void {
            if (! isset($invoice->ulid)) {
                $invoice->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'previous_status' => InvoiceStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'preferred_personnel_fee_snapshot_minor' => 'integer',
            'total_minor' => 'integer',
            'validated_paid_minor' => 'integer',
            'percentage_fee_config_snapshot' => 'array',
            'finalized_at' => 'datetime',
            'voided_at' => 'datetime',
            'adjusted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** Displayed balance owed (integer minor units): total minus validated payments. */
    public function balanceMinor(): int
    {
        return $this->total_minor - $this->validated_paid_minor;
    }

    /**
     * @param  Builder<Invoice>  $query
     */
    public function scopeDrafts(Builder $query): void
    {
        $query->where('status', InvoiceStatus::Draft->value);
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

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function adjustmentOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'adjustment_of_invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** @return BelongsTo<User, $this> */
    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
