<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\ScheduledPlanChangeStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduledPlanChangeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * ScheduledPlanChange — no-proration next-cycle plan change (Plan §13.9, §48; Phase 20B).
 * Merchant-owned. Applied only at the next cycle boundary; applied/cancelled history retained.
 *
 * @property int $id
 * @property string $ulid
 * @property int $merchant_id
 * @property int $merchant_subscription_id
 * @property int $target_plan_id
 * @property int $target_price_id
 * @property CarbonImmutable $effective_at
 * @property ScheduledPlanChangeStatus $status
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ScheduledPlanChange extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<ScheduledPlanChangeFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'merchant_subscription_id',
        'target_plan_id',
        'target_price_id',
        'effective_at',
        'status',
        'applied_at',
        'cancelled_at',
        'created_by',
    ];

    /** @return Factory<ScheduledPlanChange> */
    protected static function newFactory(): Factory
    {
        return ScheduledPlanChangeFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (ScheduledPlanChange $change): void {
            if (! isset($change->ulid)) {
                $change->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_date',
            'status' => ScheduledPlanChangeStatus::class,
            'applied_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
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

    /** @return BelongsTo<MerchantSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MerchantSubscription::class, 'merchant_subscription_id');
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function targetPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'target_plan_id');
    }

    /** @return BelongsTo<SubscriptionPlanPrice, $this> */
    public function targetPrice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlanPrice::class, 'target_price_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
