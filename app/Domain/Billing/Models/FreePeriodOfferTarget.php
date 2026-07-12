<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\PromotionTargetType;
use App\Domain\Merchants\Models\Merchant;
use Carbon\CarbonImmutable;
use Database\Factories\FreePeriodOfferTargetFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * FreePeriodOfferTarget — one explicit normalized target row (Plan §53; Phase 20C).
 * Same structure as PromotionalDiscountTarget, parented by free_period_offer_id. Exactly
 * one of merchant_id / subscription_plan_id / billing_mode set, matching `target_type`
 * (DB CHECK). Immutable `ulid` tie-break key. PLATFORM-SCOPED (TenantOwnership::EXEMPT).
 * Append-only.
 *
 * @property int $id
 * @property string $ulid
 * @property int $free_period_offer_id
 * @property PromotionTargetType $target_type
 * @property int|null $merchant_id
 * @property int|null $subscription_plan_id
 * @property BillingMode|null $billing_mode
 * @property CarbonImmutable|null $created_at
 */
class FreePeriodOfferTarget extends Model
{
    /** @use HasFactory<FreePeriodOfferTargetFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'free_period_offer_id',
        'target_type',
        'merchant_id',
        'subscription_plan_id',
        'billing_mode',
    ];

    /** @return Factory<FreePeriodOfferTarget> */
    protected static function newFactory(): Factory
    {
        return FreePeriodOfferTargetFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (FreePeriodOfferTarget $target): void {
            if (! isset($target->ulid)) {
                $target->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_type' => PromotionTargetType::class,
            'billing_mode' => BillingMode::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<FreePeriodOffer, $this> */
    public function freePeriodOffer(): BelongsTo
    {
        return $this->belongsTo(FreePeriodOffer::class, 'free_period_offer_id');
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
