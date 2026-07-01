<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\InvoiceItemFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * InvoiceItem — a service line item (Plan §13.8; Phase 17). Branch-owned; ULID
 * public id. Every Phase 17 item sources from a COMPLETED service session
 * ({@see $service_session_id}; Gate A). Snapshots (unit price, preferred fee,
 * description, commission eligibility) are frozen at finalization and never
 * recalculated. Money is integer minor units.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $invoice_id
 * @property int $service_session_id
 * @property int $service_id
 * @property int|null $staff_profile_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $line_total_minor
 * @property int|null $preferred_personnel_fee_minor
 * @property bool $eligible_for_commission
 * @property string $currency
 */
class InvoiceItem extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<InvoiceItemFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'invoice_id',
        'service_session_id',
        'service_id',
        'staff_profile_id',
        'description',
        'quantity',
        'unit_price_minor',
        'line_total_minor',
        'preferred_personnel_fee_minor',
        'eligible_for_commission',
        'currency',
    ];

    /** @return Factory<InvoiceItem> */
    protected static function newFactory(): Factory
    {
        return InvoiceItemFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (InvoiceItem $item): void {
            if (! isset($item->ulid)) {
                $item->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
            'preferred_personnel_fee_minor' => 'integer',
            'eligible_for_commission' => 'boolean',
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
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<ServiceSession, $this> */
    public function serviceSession(): BelongsTo
    {
        return $this->belongsTo(ServiceSession::class, 'service_session_id');
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }
}
