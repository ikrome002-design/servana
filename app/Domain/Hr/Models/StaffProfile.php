<?php

declare(strict_types=1);

namespace App\Domain\Hr\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Enums\StaffEmploymentStatus;
use App\Domain\Hr\Enums\StaffEmploymentType;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Database\Factories\StaffProfileFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Staff profile, 1:1 with a staff membership (Plan §7.1, Scope §3.4).
 *
 * `is_active` is the denormalized flag that backs the partial unique phone index
 * (Duplicate Staff Prevention); StaffLifecycleService keeps it in sync with the
 * membership status. `profile_photo_path` is a Phase 23 upload seam.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_user_id
 * @property int $merchant_id
 * @property int $primary_branch_id
 * @property string $first_name
 * @property string $last_name
 * @property string $display_name
 * @property string $phone
 * @property string|null $profile_photo_path
 * @property string|null $role_title
 * @property StaffEmploymentType $employment_type
 * @property StaffEmploymentStatus $employment_status
 * @property Carbon|null $start_date
 * @property int|null $invited_by
 * @property bool $is_active
 */
class StaffProfile extends Model
{
    use BelongsToBranch;
    use BelongsToMerchant;

    /** @use HasFactory<StaffProfileFactory> */
    use HasFactory;

    /** @return Factory<StaffProfile> */
    protected static function newFactory(): Factory
    {
        return StaffProfileFactory::new();
    }

    /** StaffProfile is branch-scoped on its primary branch (Plan §8.2). */
    public function branchColumn(): string
    {
        return 'primary_branch_id';
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_user_id',
        'merchant_id',
        'primary_branch_id',
        'first_name',
        'last_name',
        'display_name',
        'phone',
        'profile_photo_path',
        'role_title',
        'employment_type',
        'employment_status',
        'start_date',
        'invited_by',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (StaffProfile $profile): void {
            if (! isset($profile->ulid)) {
                $profile->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_type' => StaffEmploymentType::class,
            'employment_status' => StaffEmploymentStatus::class,
            'start_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<MerchantUser, $this> */
    public function merchantUser(): BelongsTo
    {
        return $this->belongsTo(MerchantUser::class);
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return BelongsTo<MerchantBranch, $this> */
    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'primary_branch_id');
    }

    /** @return HasMany<StaffHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(StaffHistory::class);
    }
}
