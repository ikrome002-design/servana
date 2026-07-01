<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentAllocation — allocation of a component payment to an invoice (Plan §13.8,
 * §41; Phase 18A). Branch-owned; no ULID (child evidence row). Phase 18A allocates
 * at the invoice level (`invoice_item_id` null); the nullable item column preserves
 * the Phase-18B item-level seam. Never mutates `invoices.validated_paid_minor`.
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $payment_record_id
 * @property int $invoice_id
 * @property int|null $invoice_item_id
 * @property int $amount_minor
 */
class PaymentAllocation extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'payment_record_id',
        'invoice_id',
        'invoice_item_id',
        'amount_minor',
    ];

    /** @return Factory<PaymentAllocation> */
    protected static function newFactory(): Factory
    {
        return PaymentAllocationFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
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

    /** @return BelongsTo<PaymentRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(PaymentRecord::class, 'payment_record_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /** @return BelongsTo<InvoiceItem, $this> */
    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id');
    }
}
