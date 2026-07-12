<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Enums\ScheduledPlanChangeStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use Carbon\CarbonImmutable;
use Database\Factories\MerchantSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * MerchantSubscription — subscription record lifecycle (Plan §13.9, §22, §25.4, §48;
 * Phase 20B). Merchant-owned (no branch scope). The record `status` is NOT the request-
 * authorization authority; `merchants.billing_status` is, projected transactionally (§22).
 * ULID is the public route key.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $plan_id
 * @property int $price_id
 * @property MerchantSubscriptionStatus $status
 * @property BillingInterval $billing_interval
 * @property int $trial_days_snapshot
 * @property CarbonImmutable $trial_started_at
 * @property CarbonImmutable $trial_ends_at
 * @property CarbonImmutable $current_period_start
 * @property CarbonImmutable $current_period_end
 * @property int|null $high_value_payout_threshold_minor
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $expired_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class MerchantSubscription extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<MerchantSubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'plan_id',
        'price_id',
        'status',
        'billing_interval',
        'trial_days_snapshot',
        'trial_started_at',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'high_value_payout_threshold_minor',
        'cancelled_at',
        'expired_at',
    ];

    /** @return Factory<MerchantSubscription> */
    protected static function newFactory(): Factory
    {
        return MerchantSubscriptionFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (MerchantSubscription $subscription): void {
            if (! isset($subscription->ulid)) {
                $subscription->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MerchantSubscriptionStatus::class,
            'billing_interval' => BillingInterval::class,
            'trial_days_snapshot' => 'integer',
            'trial_started_at' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'current_period_start' => 'immutable_date',
            'current_period_end' => 'immutable_date',
            'high_value_payout_threshold_minor' => 'integer',
            'cancelled_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
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

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /** @return BelongsTo<SubscriptionPlanPrice, $this> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlanPrice::class, 'price_id');
    }

    /** @return HasMany<ScheduledPlanChange, $this> */
    public function scheduledPlanChanges(): HasMany
    {
        return $this->hasMany(ScheduledPlanChange::class, 'merchant_subscription_id');
    }

    /**
     * The single pending (scheduled) plan change, if any (Plan §48). At most one may be pending per
     * subscription (partial unique index is the concurrency backstop).
     */
    public function pendingScheduledChange(): ?ScheduledPlanChange
    {
        return $this->scheduledPlanChanges()
            ->where('status', ScheduledPlanChangeStatus::Scheduled->value)
            ->latest('id')
            ->first();
    }
}
