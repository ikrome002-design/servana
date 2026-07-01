<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentReferenceCheckResult;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\PaymentReferenceCheckFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PaymentReferenceCheck — durable duplicate-reference detection record (Plan
 * §13.15, §41; Phase 18A). Branch-owned; ULID is the public id + route key (the
 * Finance override binds {paymentReferenceCheck}).
 *
 * `reference_normalized` is `$hidden` — no full/normalized reference is ever
 * serialised. The partial unique index (result='unique') makes the first
 * reservation race-safe; duplicate_suspected/override_approved rows are durable
 * evidence outside the predicate.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $payment_record_id
 * @property PaymentMethod $method
 * @property string $reference_normalized
 * @property PaymentReferenceCheckResult $result
 * @property int|null $matched_payment_record_id
 * @property Carbon $checked_at
 * @property int|null $override_by
 * @property string|null $override_reason
 */
class PaymentReferenceCheck extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<PaymentReferenceCheckFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'payment_record_id',
        'method',
        'reference_normalized',
        'result',
        'matched_payment_record_id',
        'checked_at',
        'override_by',
        'override_reason',
    ];

    protected $hidden = [
        'reference_normalized',
    ];

    /** @return Factory<PaymentReferenceCheck> */
    protected static function newFactory(): Factory
    {
        return PaymentReferenceCheckFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentReferenceCheck $check): void {
            if (! isset($check->ulid)) {
                $check->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'result' => PaymentReferenceCheckResult::class,
            'checked_at' => 'datetime',
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

    /** @return BelongsTo<PaymentRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(PaymentRecord::class, 'payment_record_id');
    }

    /** @return BelongsTo<PaymentRecord, $this> */
    public function matchedRecord(): BelongsTo
    {
        return $this->belongsTo(PaymentRecord::class, 'matched_payment_record_id');
    }

    /** @return BelongsTo<User, $this> */
    public function overrideActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by');
    }
}
