<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\PaymentRecordingGroupFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PaymentRecordingGroup — a durable merchant-client payment recording group
 * (Plan §13.15, §41; Phase 18A). Branch-owned; the ULID is the public id + route
 * key. One group = one recording workflow; a single-method payment is a group of
 * one concrete component, a split/multi-method payment is one group with several.
 * The group is the unit of Finance validation (Phase 18B). Status transitions go
 * through the state machine + named actions — never assigned directly.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $invoice_id
 * @property int $maker_user_id
 * @property int $total_amount_minor
 * @property string $currency
 * @property int|null $idempotency_key_id
 * @property PaymentRecordingGroupStatus $status
 * @property Carbon|null $recorded_at
 * @property Carbon|null $submitted_for_validation_at
 * @property Carbon|null $validated_at
 * @property Carbon|null $rejected_at
 */
class PaymentRecordingGroup extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<PaymentRecordingGroupFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'invoice_id',
        'maker_user_id',
        'total_amount_minor',
        'currency',
        'idempotency_key_id',
        'status',
        'recorded_at',
        'submitted_for_validation_at',
        'validated_at',
        'rejected_at',
    ];

    /** @return Factory<PaymentRecordingGroup> */
    protected static function newFactory(): Factory
    {
        return PaymentRecordingGroupFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentRecordingGroup $group): void {
            if (! isset($group->ulid)) {
                $group->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PaymentRecordingGroupStatus::class,
            'total_amount_minor' => 'integer',
            'recorded_at' => 'datetime',
            'submitted_for_validation_at' => 'datetime',
            'validated_at' => 'datetime',
            'rejected_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_user_id');
    }

    /** @return HasMany<PaymentRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(PaymentRecord::class);
    }
}
