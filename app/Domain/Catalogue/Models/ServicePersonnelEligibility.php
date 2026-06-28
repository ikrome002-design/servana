<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\ServicePersonnelEligibilityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Service-personnel eligibility — HR-owned gate (Plan §13.7, §39; 15A).
 *
 * Branch-owned junction (no ulid; not directly route-bound). One row per
 * (service, staff_profile); assign/revoke toggles `active`. Same-merchant linkage
 * is DB-enforced (composite FKs); same-branch linkage is enforced by the
 * AssignEligibility action. Mutation requires HR `personnel.eligibility.manage`.
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $service_id
 * @property int $staff_profile_id
 * @property bool $active
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class ServicePersonnelEligibility extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<ServicePersonnelEligibilityFactory> */
    use HasFactory;

    protected $table = 'service_personnel_eligibility';

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'service_id',
        'staff_profile_id',
        'active',
        'created_by',
        'updated_by',
    ];

    /** @return Factory<ServicePersonnelEligibility> */
    protected static function newFactory(): Factory
    {
        return ServicePersonnelEligibilityFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /** @param Builder<ServicePersonnelEligibility> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
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

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }
}
