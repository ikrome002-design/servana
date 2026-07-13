<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\PlatformFeeDisputeStatus;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\PlatformFeeDisputeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * PlatformFeeDispute — platform-side dispute case over a percentage platform-fee charge
 * (Plan §13.10 [Correction 3]; Phase 20E). TENANT-OWNED (BelongsToMerchant; optional nullable
 * branch_id). status ∈ {open,under_review,resolved,rejected} — no `escalated`. A money-changing
 * resolution creates a PlatformFeeAdjustment; it never rewrites a ledger amount. Lifecycle via
 * PlatformFeeDisputeStateMachine. No destructive deletion (DB trigger).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int|null $branch_id
 * @property int|null $platform_fee_ledger_entry_id
 * @property int|null $subscription_invoice_id
 * @property string $reason
 * @property PlatformFeeDisputeStatus $status
 * @property int|null $assigned_reviewer
 * @property int|null $evidence_file_id
 * @property string|null $resolution_note
 * @property int $created_by
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PlatformFeeDispute extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<PlatformFeeDisputeFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'platform_fee_ledger_entry_id',
        'subscription_invoice_id',
        'reason',
        'status',
        'assigned_reviewer',
        'evidence_file_id',
        'resolution_note',
        'created_by',
        'resolved_by',
        'resolved_at',
    ];

    /** @return Factory<PlatformFeeDispute> */
    protected static function newFactory(): Factory
    {
        return PlatformFeeDisputeFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformFeeDispute $dispute): void {
            if (! isset($dispute->ulid)) {
                $dispute->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PlatformFeeDisputeStatus::class,
            'resolved_at' => 'immutable_datetime',
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

    /** @return BelongsTo<PlatformFeeLedgerEntry, $this> */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(PlatformFeeLedgerEntry::class, 'platform_fee_ledger_entry_id');
    }

    /** @return BelongsTo<SubscriptionInvoice, $this> */
    public function subscriptionInvoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reviewer');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
