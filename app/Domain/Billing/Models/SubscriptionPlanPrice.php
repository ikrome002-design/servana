<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingInterval;
use App\Models\User;
use Database\Factories\SubscriptionPlanPriceFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * SubscriptionPlanPrice — the SOLE plan-price source (Plan §13.9, §47; ADR-011; Phase 20A).
 * Platform-owned. Effective-dated; money is integer minor units; currency uppercase ISO;
 * `billing_interval` is a canonical {@see BillingInterval}. Overlapping ranges per
 * (plan, interval, currency) are rejected by a DB `EXCLUDE USING gist` constraint.
 *
 * @property int $id
 * @property string $ulid
 * @property int $plan_id
 * @property int $amount_minor
 * @property string $currency
 * @property BillingInterval $billing_interval
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int $created_by
 */
class SubscriptionPlanPrice extends Model
{
    /** @use HasFactory<SubscriptionPlanPriceFactory> */
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'amount_minor',
        'currency',
        'billing_interval',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    /** @return Factory<SubscriptionPlanPrice> */
    protected static function newFactory(): Factory
    {
        return SubscriptionPlanPriceFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (SubscriptionPlanPrice $price): void {
            if (! isset($price->ulid)) {
                $price->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'billing_interval' => BillingInterval::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
