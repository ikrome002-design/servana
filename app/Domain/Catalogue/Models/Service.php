<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Enums\ServiceStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Enums\Currency;
use App\Support\Money;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Service — Branch-Manager catalogue item (Plan §13.7, §39; 15A).
 *
 * Branch-owned. Money is integer minor units (`price_minor`) surfaced through the
 * Money value object; never a float. `preferred_personnel_fee_minor` is the
 * LEGACY fixed seam — internal, non-editable, NOT in $fillable, no API field
 * (superseded by preferred_personnel_fee_rules, Phase 20A). Status transitions go
 * through the ArchiveService domain action.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $category_id
 * @property string $name
 * @property string|null $description
 * @property int $price_minor
 * @property string $currency
 * @property int $duration_minutes
 * @property int|null $preferred_personnel_fee_minor
 * @property ServiceStatus $status
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Service extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /**
     * `preferred_personnel_fee_minor` is intentionally NOT fillable — the legacy
     * fixed seam is non-editable (Plan §39); Branch Manager has no pricing control
     * over the preferred-personnel fee.
     */
    protected $fillable = [
        'merchant_id',
        'branch_id',
        'category_id',
        'name',
        'description',
        'price_minor',
        'currency',
        'duration_minutes',
        'status',
        'created_by',
        'updated_by',
    ];

    /** @return Factory<Service> */
    protected static function newFactory(): Factory
    {
        return ServiceFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Service $service): void {
            if (! isset($service->ulid)) {
                $service->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'duration_minutes' => 'integer',
            'preferred_personnel_fee_minor' => 'integer',
            'status' => ServiceStatus::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** Price as a currency-checked Money value object (never a float). */
    public function price(): Money
    {
        return new Money($this->price_minor, Currency::from($this->currency));
    }

    public function isArchived(): bool
    {
        return $this->status === ServiceStatus::Archived;
    }

    /** @param Builder<Service> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ServiceStatus::Active->value);
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

    /** @return BelongsTo<ServiceCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    /** @return HasMany<ServicePersonnelEligibility, $this> */
    public function eligibilities(): HasMany
    {
        return $this->hasMany(ServicePersonnelEligibility::class, 'service_id');
    }
}
