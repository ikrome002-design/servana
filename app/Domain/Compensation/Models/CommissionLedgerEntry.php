<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\CommissionLedgerEntryType;
use App\Domain\Compensation\Enums\CommissionLedgerStatus;
use App\Domain\Compensation\Enums\CommissionReversalReason;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\CommissionLedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * CommissionLedgerEntry — an append-only earned/reversal commission fact (Plan §61; Phase 20G;
 * table `commission_ledger`). Branch-owned; append-only (`created_at` only). Earned only at
 * Finance validation; corrections are additive negative rows referencing the original. Money is
 * integer minor units (ADR-005). Immutability + append-only are DB-enforced by triggers; the
 * model exposes no monetary mutation.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $staff_profile_id
 * @property int $compensation_plan_id
 * @property int $commission_rule_id
 * @property int|null $service_session_id
 * @property int $invoice_id
 * @property int $invoice_item_id
 * @property int|null $payment_record_id
 * @property int|null $payment_validation_event_id
 * @property int|null $source_entry_id
 * @property CommissionLedgerEntryType $entry_type
 * @property CommissionReversalReason|null $reversal_reason
 * @property int $calculation_basis_minor
 * @property int|null $rate_basis_points
 * @property int|null $fixed_rate_minor
 * @property int $amount_minor
 * @property string $currency
 * @property Carbon|null $earned_at
 * @property CommissionLedgerStatus $status
 * @property int|null $payout_item_id
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $created_at
 */
class CommissionLedgerEntry extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<CommissionLedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'commission_ledger';

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'staff_profile_id',
        'compensation_plan_id',
        'commission_rule_id',
        'service_session_id',
        'invoice_id',
        'invoice_item_id',
        'payment_record_id',
        'payment_validation_event_id',
        'source_entry_id',
        'entry_type',
        'reversal_reason',
        'calculation_basis_minor',
        'rate_basis_points',
        'fixed_rate_minor',
        'amount_minor',
        'currency',
        'earned_at',
        'status',
        'payout_item_id',
        'created_by',
        'approved_by',
    ];

    /** @return Factory<CommissionLedgerEntry> */
    protected static function newFactory(): Factory
    {
        return CommissionLedgerEntryFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (CommissionLedgerEntry $entry): void {
            if (! isset($entry->ulid)) {
                $entry->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'entry_type' => CommissionLedgerEntryType::class,
            'reversal_reason' => CommissionReversalReason::class,
            'status' => CommissionLedgerStatus::class,
            'calculation_basis_minor' => 'integer',
            'rate_basis_points' => 'integer',
            'fixed_rate_minor' => 'integer',
            'amount_minor' => 'integer',
            'earned_at' => 'datetime',
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

    /** @return BelongsTo<StaffProfile, $this> */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
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

    /** @return BelongsTo<PersonnelCompensationPlan, $this> */
    public function compensationPlan(): BelongsTo
    {
        return $this->belongsTo(PersonnelCompensationPlan::class, 'compensation_plan_id');
    }

    /** @return BelongsTo<CommissionRule, $this> */
    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    /** @return BelongsTo<PaymentValidationEvent, $this> */
    public function validationEvent(): BelongsTo
    {
        return $this->belongsTo(PaymentValidationEvent::class, 'payment_validation_event_id');
    }

    /** @return BelongsTo<CommissionLedgerEntry, $this> */
    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_entry_id');
    }
}
