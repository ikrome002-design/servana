<?php

declare(strict_types=1);

namespace App\Domain\Branches\Models;

use App\Domain\Branches\Enums\BranchStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\MerchantBranchFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
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
 * @property string|null $status_reason
 * @property Carbon|null $suspended_at
 * @property Carbon|null $archived_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property-read Merchant $merchant
 */
class MerchantBranch extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<MerchantBranchFactory> */
    use HasFactory;

    protected $table = 'merchant_branches';

    /**
     * Mirror the DB default so a freshly created/factoried instance has a status
     * in memory before refresh (the status cast would otherwise be null).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

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
        'status_reason',
        'created_by',
        'updated_by',
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
            'suspended_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function isActive(): bool
    {
        return $this->status === BranchStatus::Active;
    }

    public function isArchived(): bool
    {
        return $this->status === BranchStatus::Archived;
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return HasMany<BranchUserAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(BranchUserAssignment::class, 'branch_id');
    }

    /** @return HasMany<BranchOperatingHour, $this> */
    public function operatingHours(): HasMany
    {
        return $this->hasMany(BranchOperatingHour::class, 'branch_id');
    }

    /** @return HasMany<BranchCalendarException, $this> */
    public function calendarExceptions(): HasMany
    {
        return $this->hasMany(BranchCalendarException::class, 'branch_id');
    }

    /** @return HasMany<BranchDayRecord, $this> */
    public function dayRecords(): HasMany
    {
        return $this->hasMany(BranchDayRecord::class, 'branch_id');
    }

    /** @return HasMany<BranchCashUp, $this> */
    public function cashUps(): HasMany
    {
        return $this->hasMany(BranchCashUp::class, 'branch_id');
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'branch_id');
    }
}
