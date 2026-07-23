<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Models;

use App\Domain\Billing\Models\SubscriptionInvoiceItem;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Messaging\Sms\Enums\SmsBillingEntryStatus;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Enums\Currency;
use App\Support\Money;
use Database\Factories\SmsBillingEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * SmsBillingEntry — what a Personnel SMS campaign OWES (Plan §13.13, §64; Phase 21S; ADR-005).
 * Branch-owned.
 *
 * `amount_minor = quantity * unit_cost_minor` is a database CHECK, so a float-derived or
 * hand-edited amount cannot exist. A partial unique index permits at most ONE live entry
 * (`provisional`/`billable`/`invoiced`) per campaign, which is the structural guarantee that a
 * duplicate confirm or a job retry can never double-bill.
 *
 * SERVANA MOVES NO MONEY (ADR-012): this creates no Wallet payment resource, no payment attempt and
 * no provider call. `billing_invoice_line_id` is the nullable seam to `subscription_invoice_items`
 * for the future phase that rolls SMS charges into a subscription invoice; Phase 21S owns the
 * queue, never the invoicing.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $campaign_id
 * @property int $quantity
 * @property int $unit_cost_minor
 * @property int $amount_minor
 * @property string $currency
 * @property SmsBillingEntryStatus $status
 * @property int|null $billing_invoice_line_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SmsBillingEntry extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<SmsBillingEntryFactory> */
    use HasFactory;

    protected $table = 'sms_billing_entries';

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'campaign_id',
        'quantity',
        'unit_cost_minor',
        'amount_minor',
        'currency',
        'status',
        'billing_invoice_line_id',
    ];

    /** @return Factory<SmsBillingEntry> */
    protected static function newFactory(): Factory
    {
        return SmsBillingEntryFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (SmsBillingEntry $entry): void {
            if (! isset($entry->ulid)) {
                $entry->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost_minor' => 'integer',
            'amount_minor' => 'integer',
            'status' => SmsBillingEntryStatus::class,
            'billing_invoice_line_id' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** The owed amount as a Money value object (ADR-005 integer minor units). */
    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, Currency::from($this->currency));
    }

    /**
     * The single live entry per campaign (the partial unique index covers exactly these).
     *
     * @param  Builder<SmsBillingEntry>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', SmsBillingEntryStatus::liveValues());
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

    /** @return BelongsTo<PersonnelSmsCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PersonnelSmsCampaign::class, 'campaign_id');
    }

    /** The future subscription-invoice line this charge rolls into (null until a billing phase links it). */
    /** @return BelongsTo<SubscriptionInvoiceItem, $this> */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoiceItem::class, 'billing_invoice_line_id');
    }
}
