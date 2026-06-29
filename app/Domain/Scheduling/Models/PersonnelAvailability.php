<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Enums\AvailabilityType;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\PersonnelAvailabilityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Personnel availability schedule row (Plan §13.7, §80 Phase 15B).
 *
 * Branch-owned (no ulid). A recurring row carries `weekday` (0=Sun … 6=Sat) with
 * null `date`; an exception row carries `date` with null `weekday`. Intervals are
 * half-open [start_time, end_time) in branch business time. `available=false`
 * subtracts a break / unavailable period. Same-merchant linkage is DB-enforced
 * (composite FKs); same-branch linkage (branch_id = staff.primary_branch_id) is
 * set by the domain action. Mutation requires HR `personnel.availability.manage`.
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $staff_profile_id
 * @property int|null $weekday
 * @property Carbon|null $date
 * @property string $start_time
 * @property string $end_time
 * @property AvailabilityType $type
 * @property bool $available
 */
class PersonnelAvailability extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<PersonnelAvailabilityFactory> */
    use HasFactory;

    protected $table = 'personnel_availability';

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'staff_profile_id',
        'weekday',
        'date',
        'start_time',
        'end_time',
        'type',
        'available',
    ];

    /** @return Factory<PersonnelAvailability> */
    protected static function newFactory(): Factory
    {
        return PersonnelAvailabilityFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'date' => 'date',
            'type' => AvailabilityType::class,
            'available' => 'boolean',
        ];
    }

    /** @param Builder<PersonnelAvailability> $query */
    public function scopeRecurring(Builder $query): void
    {
        $query->where('type', AvailabilityType::Recurring->value);
    }

    /** @param Builder<PersonnelAvailability> $query */
    public function scopeException(Builder $query): void
    {
        $query->where('type', AvailabilityType::Exception->value);
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

    /** @return BelongsTo<StaffProfile, $this> */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }
}
