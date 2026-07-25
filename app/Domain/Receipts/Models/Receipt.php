<?php

declare(strict_types=1);

namespace App\Domain\Receipts\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Search\Concerns\SearchableDocument;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Receipt — one original per validated payment group (+ reissue) (Plan §13.16, §43;
 * Gate J; Phase 18B). Branch-owned; ULID is the public id + route key.
 *
 * `components` holds SAFE snapshots only ({method, amount_minor}) — never a full
 * reference, internal id, or storage path. The PDF is generated via the Phase 10F
 * file domain (`receipt_pdf`); a receipt is not downloadable until
 * `file_generation_status = ready`. Reissue references the immutable original.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $invoice_id
 * @property int|null $payment_validation_event_id
 * @property int $receipt_number
 * @property int $amount_minor
 * @property string $currency
 * @property array<int, array{method: string, amount_minor: int}> $components
 * @property int|null $reissue_of_receipt_id
 * @property string|null $reason
 * @property int|null $file_id
 * @property string $file_generation_status
 * @property int|null $issued_by
 */
class Receipt extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    use SearchableDocument;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'invoice_id',
        'payment_validation_event_id',
        'receipt_number',
        'amount_minor',
        'currency',
        'components',
        'reissue_of_receipt_id',
        'reason',
        'file_id',
        'file_generation_status',
        'issued_by',
    ];

    /** @return Factory<Receipt> */
    protected static function newFactory(): Factory
    {
        return ReceiptFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Receipt $receipt): void {
            if (! isset($receipt->ulid)) {
                $receipt->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'receipt_number' => 'integer',
            'components' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function isReissue(): bool
    {
        return $this->reissue_of_receipt_id !== null;
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
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /** @return BelongsTo<PaymentValidationEvent, $this> */
    public function validationEvent(): BelongsTo
    {
        return $this->belongsTo(PaymentValidationEvent::class, 'payment_validation_event_id');
    }

    /** @return BelongsTo<Receipt, $this> */
    public function reissueOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reissue_of_receipt_id');
    }

    /** @return BelongsTo<UploadedFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(UploadedFile::class, 'file_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
