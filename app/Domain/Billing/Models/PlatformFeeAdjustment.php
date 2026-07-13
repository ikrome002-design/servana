<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\PlatformFeeAdjustmentType;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\PlatformFeeAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * PlatformFeeAdjustment — append-only correction evidence over a platform-fee ledger entry
 * (Plan §13.10; Phase 20E). TENANT-OWNED (BelongsToMerchant; optional nullable branch_id). Fully
 * immutable after insert (DB trigger blocks UPDATE/DELETE). Money is integer minor units; the sign
 * follows the adjustment type. No `updated_at`.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int|null $branch_id
 * @property int $platform_fee_ledger_entry_id
 * @property PlatformFeeAdjustmentType $adjustment_type
 * @property int $amount_minor
 * @property string $currency
 * @property string $reason
 * @property string|null $source_reference
 * @property CarbonImmutable $effective_date
 * @property int $created_by
 * @property int|null $approved_by
 * @property string|null $idempotency_key
 * @property CarbonImmutable|null $created_at
 */
class PlatformFeeAdjustment extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<PlatformFeeAdjustmentFactory> */
    use HasFactory;

    /** Append-only: created_at only, no updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'platform_fee_ledger_entry_id',
        'adjustment_type',
        'amount_minor',
        'currency',
        'reason',
        'source_reference',
        'effective_date',
        'created_by',
        'approved_by',
        'idempotency_key',
    ];

    /** @return Factory<PlatformFeeAdjustment> */
    protected static function newFactory(): Factory
    {
        return PlatformFeeAdjustmentFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PlatformFeeAdjustment $adjustment): void {
            if (! isset($adjustment->ulid)) {
                $adjustment->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'adjustment_type' => PlatformFeeAdjustmentType::class,
            'amount_minor' => 'integer',
            'effective_date' => 'immutable_date',
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

    /** @return BelongsTo<PlatformFeeLedgerEntry, $this> */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(PlatformFeeLedgerEntry::class, 'platform_fee_ledger_entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
