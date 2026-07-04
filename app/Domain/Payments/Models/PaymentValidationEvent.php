<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Payments\Enums\PaymentValidationDecision;
use App\Domain\Receipts\Models\Receipt;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\PaymentValidationEventFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PaymentValidationEvent — immutable, GROUP-LEVEL Finance validation decision
 * (Plan §42; Gate A/B; Phase 18B). Branch-owned; ULID is the public id.
 *
 * Append-only: the row has `created_at` only (no `updated_at`) and no update/delete
 * route. One event per whole-group decision; a validated decision carries
 * `validated_amount_minor` = the group total.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $payment_recording_group_id
 * @property int $invoice_id
 * @property int $checker_user_id
 * @property PaymentValidationDecision $decision
 * @property int|null $validated_amount_minor
 * @property string|null $reason
 * @property Carbon $created_at
 */
class PaymentValidationEvent extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<PaymentValidationEventFactory> */
    use HasFactory;

    /** Append-only: created_at only. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'payment_recording_group_id',
        'invoice_id',
        'checker_user_id',
        'decision',
        'validated_amount_minor',
        'reason',
    ];

    /** @return Factory<PaymentValidationEvent> */
    protected static function newFactory(): Factory
    {
        return PaymentValidationEventFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentValidationEvent $event): void {
            if (! isset($event->ulid)) {
                $event->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decision' => PaymentValidationDecision::class,
            'validated_amount_minor' => 'integer',
            'created_at' => 'datetime',
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

    /** @return BelongsTo<PaymentRecordingGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PaymentRecordingGroup::class, 'payment_recording_group_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_user_id');
    }

    /** The one ORIGINAL receipt issued for this validated event (null for non-validated). */
    /** @return HasOne<Receipt, $this> */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class, 'payment_validation_event_id')->whereNull('reissue_of_receipt_id');
    }
}
