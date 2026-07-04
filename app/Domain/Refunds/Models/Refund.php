<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Refund — EXTERNAL refund record (Servana never moves funds) (Plan §44; Gate D/E;
 * Phase 18B). Branch-owned; ULID is the public id + route key.
 *
 * Allocated to a concrete validated component (`payment_record_id`); a multi-component
 * refund shares `refund_group_ulid`. `external_reference_encrypted` is `$hidden`
 * (masked suffix only). Finalization is additive/non-destructive.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $invoice_id
 * @property int $payment_record_id
 * @property string $refund_group_ulid
 * @property int $amount_minor
 * @property string $currency
 * @property PaymentMethod $method
 * @property string|null $external_reference_encrypted
 * @property string $reason
 * @property RefundStatus $status
 * @property int $requested_by
 * @property int|null $approved_by
 * @property int|null $finalized_by
 * @property int|null $rejected_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $finalized_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Refund extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'invoice_id',
        'payment_record_id',
        'refund_group_ulid',
        'amount_minor',
        'currency',
        'method',
        'external_reference_encrypted',
        'reason',
        'status',
        'requested_by',
        'approved_by',
        'finalized_by',
        'rejected_by',
        'approved_at',
        'finalized_at',
        'rejected_at',
    ];

    /** Never serialised — the external reference stays server-side. */
    protected $hidden = [
        'external_reference_encrypted',
    ];

    /** @return Factory<Refund> */
    protected static function newFactory(): Factory
    {
        return RefundFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Refund $refund): void {
            if (! isset($refund->ulid)) {
                $refund->ulid = (string) Str::ulid();
            }
            if (! isset($refund->refund_group_ulid)) {
                $refund->refund_group_ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => RefundStatus::class,
            'amount_minor' => 'integer',
            'external_reference_encrypted' => 'encrypted',
            'approved_at' => 'datetime',
            'finalized_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** Masked reference suffix for display/audit (never the full reference). */
    public function maskedReference(): ?string
    {
        $display = $this->external_reference_encrypted;

        if (! is_string($display) || $display === '') {
            return null;
        }

        $suffix = substr($display, -4);

        return str_repeat('•', max(0, strlen($display) - strlen($suffix))).$suffix;
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

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
