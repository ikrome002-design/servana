<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\CommissionHandoffKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\CommissionHandoffEventFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * CommissionHandoffEvent — durable, immutable, idempotent per-component seam for Phase
 * 20G (Gate C/E; Phase 18B). Branch-owned; append-only (`created_at` only). NOT a
 * commission ledger — carries no rate/earned/payable. Written in the same transaction
 * as a group validation (validated_allocation) and a refund finalization (reversal).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property CommissionHandoffKind $kind
 * @property int|null $payment_validation_event_id
 * @property int|null $refund_id
 * @property int $payment_record_id
 * @property int $invoice_id
 * @property int|null $invoice_item_id
 * @property int|null $service_id
 * @property int|null $staff_profile_id
 * @property int $amount_minor
 * @property string $currency
 * @property Carbon $effective_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 */
class CommissionHandoffEvent extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<CommissionHandoffEventFactory> */
    use HasFactory;

    /** Append-only: created_at only. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'kind',
        'payment_validation_event_id',
        'refund_id',
        'payment_record_id',
        'invoice_id',
        'invoice_item_id',
        'service_id',
        'staff_profile_id',
        'amount_minor',
        'currency',
        'effective_at',
        'consumed_at',
    ];

    /** @return Factory<CommissionHandoffEvent> */
    protected static function newFactory(): Factory
    {
        return CommissionHandoffEventFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (CommissionHandoffEvent $event): void {
            if (! isset($event->ulid)) {
                $event->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => CommissionHandoffKind::class,
            'amount_minor' => 'integer',
            'effective_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
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
    public function paymentRecord(): BelongsTo
    {
        return $this->belongsTo(PaymentRecord::class, 'payment_record_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
