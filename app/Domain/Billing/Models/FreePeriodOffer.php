<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\FreePeriodOfferFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * FreePeriodOffer — platform-governed free-period (trial-length) offer (Plan §53;
 * Phase 20C). PLATFORM-SCOPED (TenantOwnership::EXEMPT). ULID is the public route key.
 * `free_period_days` (1..365) sets a new subscription's trial length once at binding.
 * Lifecycle via FreePeriodOfferStateMachine; approval yields `scheduled` (no direct
 * draft→active); approved terms immutable.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property int $free_period_days
 * @property PromotionTargetScope $target_scope
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property FreePeriodOfferStatus $status
 * @property int $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $change_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class FreePeriodOffer extends Model
{
    /** @use HasFactory<FreePeriodOfferFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'free_period_days',
        'target_scope',
        'effective_from',
        'effective_to',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'change_reason',
    ];

    /** @return Factory<FreePeriodOffer> */
    protected static function newFactory(): Factory
    {
        return FreePeriodOfferFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (FreePeriodOffer $offer): void {
            if (! isset($offer->ulid)) {
                $offer->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'free_period_days' => 'integer',
            'target_scope' => PromotionTargetScope::class,
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'status' => FreePeriodOfferStatus::class,
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<FreePeriodOfferTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(FreePeriodOfferTarget::class, 'free_period_offer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
