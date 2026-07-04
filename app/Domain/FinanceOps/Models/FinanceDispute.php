<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\FinanceOps\Enums\FinanceDisputeStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\FinanceDisputeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * FinanceDispute — Finance-only investigation record over an invoice and/or payment
 * record (Plan §44; Phase 18B). Branch-owned; ULID is the public id + route key.
 * NEVER mutates the disputed source row. Evidence uses the private Phase 10F file
 * domain (`dispute_evidence`).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int|null $invoice_id
 * @property int|null $payment_record_id
 * @property FinanceDisputeStatus $status
 * @property string $reason
 * @property string|null $resolution_note
 * @property int|null $evidence_file_id
 * @property int $created_by
 * @property int|null $resolved_by
 */
class FinanceDispute extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<FinanceDisputeFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'invoice_id',
        'payment_record_id',
        'status',
        'reason',
        'resolution_note',
        'evidence_file_id',
        'created_by',
        'resolved_by',
    ];

    /** @return Factory<FinanceDispute> */
    protected static function newFactory(): Factory
    {
        return FinanceDisputeFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (FinanceDispute $dispute): void {
            if (! isset($dispute->ulid)) {
                $dispute->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => FinanceDisputeStatus::class,
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
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /** @return BelongsTo<PaymentRecord, $this> */
    public function paymentRecord(): BelongsTo
    {
        return $this->belongsTo(PaymentRecord::class, 'payment_record_id');
    }

    /** @return BelongsTo<UploadedFile, $this> */
    public function evidenceFile(): BelongsTo
    {
        return $this->belongsTo(UploadedFile::class, 'evidence_file_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
