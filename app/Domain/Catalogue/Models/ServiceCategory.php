<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\ServiceCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Service category — Branch-Manager catalogue grouping (Plan §13.7, §39; 15A).
 *
 * Branch-owned (BelongsToMerchant + BelongsToBranch). Soft-archived via
 * `archived_at`; a referenced category is never hard-deleted (services.category_id
 * is RESTRICT). Branch-scoped active-name uniqueness lives in the DB (partial
 * unique index).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property string $name
 * @property int $sort_order
 * @property Carbon|null $archived_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class ServiceCategory extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<ServiceCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'name',
        'sort_order',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    /** @return Factory<ServiceCategory> */
    protected static function newFactory(): Factory
    {
        return ServiceCategoryFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (ServiceCategory $category): void {
            if (! isset($category->ulid)) {
                $category->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param Builder<ServiceCategory> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
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

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }
}
