<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Models;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\ServiceFeeTier;
use App\Models\User;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Merchant tenant root (Plan §7.1, Scope §3.2/§5.1).
 *
 * Created only by Merchant Administrator self-registration (RegisterMerchant);
 * there is no platform path to create merchants. ULID is the public identifier
 * (A5) — the bigint PK never leaves the boundary.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $slug
 * @property MerchantStatus $status
 * @property ServiceFeeTier|null $service_fee_tier
 * @property Carbon|null $setup_completed_at
 * @property Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $last_fee_payment_at
 * @property int|null $created_by
 */
class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    /**
     * `status`, `slug`, `ulid`, `service_fee_tier` and the lifecycle timestamps
     * are set by onboarding/lifecycle code (never mass-assigned from input).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /** @return Factory<Merchant> */
    protected static function newFactory(): Factory
    {
        return MerchantFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Merchant $merchant): void {
            if (! isset($merchant->ulid)) {
                $merchant->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MerchantStatus::class,
            'service_fee_tier' => ServiceFeeTier::class,
            'setup_completed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'last_fee_payment_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasOne<MerchantProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(MerchantProfile::class);
    }

    /** @return HasMany<MerchantUser, $this> */
    public function merchantUsers(): HasMany
    {
        return $this->hasMany(MerchantUser::class);
    }

    /** @return HasMany<MerchantBranch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(MerchantBranch::class);
    }

    /** @return HasMany<MerchantStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(MerchantStatusHistory::class);
    }

    /** @return Attribute<bool, never> */
    protected function isActive(): Attribute
    {
        return Attribute::get(fn (): bool => $this->status === MerchantStatus::Active);
    }

    /** @return Attribute<bool, never> */
    protected function isPendingSetup(): Attribute
    {
        return Attribute::get(fn (): bool => $this->status === MerchantStatus::PendingSetup);
    }

    /**
     * Owner (merchant_admin) membership, if loaded/needed.
     *
     * @return HasOne<MerchantUser, $this>
     */
    public function owner(): HasOne
    {
        return $this->hasOne(MerchantUser::class)
            ->where('role', MerchantUserRole::MerchantAdmin->value);
    }

    /**
     * Created-by user (the registering owner).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
