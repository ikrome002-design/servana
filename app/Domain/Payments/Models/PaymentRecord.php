<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Database\Factories\PaymentRecordFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PaymentRecord — a concrete-method component of a recording group (Plan §13.8,
 * §41; Phase 18A). Branch-owned; ULID is the public id + route key.
 *
 * `reference_normalized` (the normalized comparison key) and
 * `reference_display_encrypted` (the encrypted original) are BOTH `$hidden` — a
 * Resource never returns a raw/normalized reference; only a masked suffix
 * ({@see maskedReference()}) is ever exposed. `validated_amount_minor` is written
 * only by the Phase-18B validation workflow.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $invoice_id
 * @property int $payment_recording_group_id
 * @property int $recorded_by
 * @property int|null $payer_client_id
 * @property PaymentMethod $method
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $reference_normalized
 * @property string|null $reference_display_encrypted
 * @property Carbon $paid_at
 * @property PaymentRecordStatus $status
 * @property int $maker_user_id
 * @property int|null $validated_amount_minor
 */
class PaymentRecord extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<PaymentRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'invoice_id',
        'payment_recording_group_id',
        'recorded_by',
        'payer_client_id',
        'method',
        'amount_minor',
        'currency',
        'reference_normalized',
        'reference_display_encrypted',
        'paid_at',
        'status',
        'maker_user_id',
        'validated_amount_minor',
    ];

    /** Never serialised — the normalized key and the encrypted display value stay server-side. */
    protected $hidden = [
        'reference_normalized',
        'reference_display_encrypted',
    ];

    /** @return Factory<PaymentRecord> */
    protected static function newFactory(): Factory
    {
        return PaymentRecordFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentRecord $record): void {
            if (! isset($record->ulid)) {
                $record->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentRecordStatus::class,
            'amount_minor' => 'integer',
            'validated_amount_minor' => 'integer',
            'reference_display_encrypted' => 'encrypted',
            'paid_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Masked reference suffix for display/audit (e.g. "••••1234"). Decrypts the
     * stored display value only to compute the mask; returns null for cash / no
     * reference. The full reference is never returned.
     */
    public function maskedReference(): ?string
    {
        $display = $this->reference_display_encrypted;

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

    /** @return BelongsTo<PaymentRecordingGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PaymentRecordingGroup::class, 'payment_recording_group_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'payer_client_id');
    }

    /** @return BelongsTo<User, $this> */
    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_user_id');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return HasMany<PaymentReferenceCheck, $this> */
    public function referenceChecks(): HasMany
    {
        return $this->hasMany(PaymentReferenceCheck::class);
    }
}
