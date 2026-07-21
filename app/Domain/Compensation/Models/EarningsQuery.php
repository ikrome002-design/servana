<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\EarningsQueryAssignedRole;
use App\Domain\Compensation\Enums\EarningsQueryStatus;
use App\Domain\Compensation\Enums\EarningsQuerySubjectType;
use App\Domain\Compensation\Enums\EarningsQueryType;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\EarningsQueryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * EarningsQuery — a personnel own-scope earnings query (Plan §63; Phase 20H; table
 * `earnings_queries`). Branch-owned + own-scope. Raised against one of the personnel's own facts;
 * Finance responds; resolution NEVER mutates a ledger silently (a monetary correction is a separate
 * `compensation_adjustments` referenced by `resolved_adjustment_id`).
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $branch_id
 * @property int $staff_profile_id
 * @property EarningsQuerySubjectType $subject_type
 * @property int $subject_id
 * @property EarningsQueryType $query_type
 * @property string $body
 * @property EarningsQueryStatus $status
 * @property EarningsQueryAssignedRole|null $assigned_role
 * @property int|null $assigned_to
 * @property string|null $resolution_note
 * @property int|null $resolved_adjustment_id
 * @property int|null $responded_by
 * @property Carbon|null $responded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EarningsQuery extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<EarningsQueryFactory> */
    use HasFactory;

    protected $table = 'earnings_queries';

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'staff_profile_id',
        'subject_type',
        'subject_id',
        'query_type',
        'body',
        'status',
        'assigned_role',
        'assigned_to',
        'resolution_note',
        'resolved_adjustment_id',
        'responded_by',
        'responded_at',
    ];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return Factory<EarningsQuery> */
    protected static function newFactory(): Factory
    {
        return EarningsQueryFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (EarningsQuery $query): void {
            if (! isset($query->ulid)) {
                $query->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subject_type' => EarningsQuerySubjectType::class,
            'query_type' => EarningsQueryType::class,
            'status' => EarningsQueryStatus::class,
            'assigned_role' => EarningsQueryAssignedRole::class,
            'responded_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
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

    /** @return BelongsTo<CompensationAdjustment, $this> */
    public function resolvedAdjustment(): BelongsTo
    {
        return $this->belongsTo(CompensationAdjustment::class, 'resolved_adjustment_id');
    }
}
