<?php

declare(strict_types=1);

namespace App\Domain\Branches\Models;

use App\Domain\Branches\Enums\BranchStatus;
use App\Domain\Merchants\Models\Merchant;
use Database\Factories\MerchantBranchFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Branch — MINIMAL Phase 6 seam (Plan §7.2 full entity is Phase 7).
 *
 * Phase 6 creates branches only as part of first-time setup (Scope §3.2 step 3)
 * so initial staff have a branch to be assigned to. The full branch lifecycle
 * (operating hours, calendar exceptions, day open/close, cash-ups, closure
 * protection, CRUD endpoints, branch_user_assignments) is Phase 7, which expands
 * this model and table forward-only.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property string $name
 * @property string $code
 * @property string|null $address
 * @property string|null $town
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $business_category
 * @property BranchStatus $status
 * @property int|null $created_by
 */
class MerchantBranch extends Model
{
    /** @use HasFactory<MerchantBranchFactory> */
    use HasFactory;

    protected $table = 'merchant_branches';

    /** @return Factory<MerchantBranch> */
    protected static function newFactory(): Factory
    {
        return MerchantBranchFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'name',
        'code',
        'address',
        'town',
        'phone',
        'email',
        'business_category',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (MerchantBranch $branch): void {
            if (! isset($branch->ulid)) {
                $branch->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BranchStatus::class,
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
}
