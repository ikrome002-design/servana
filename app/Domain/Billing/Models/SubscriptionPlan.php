<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\SubscriptionPlanStatus;
use App\Support\Casts\JsonObject;
use Database\Factories\SubscriptionPlanFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * SubscriptionPlan — platform-global plan catalogue, NON-PRICE metadata only (Plan §13.9,
 * §47; ADR-011; Phase 20A). Platform-owned: no merchant/branch scope. Price lives solely in
 * {@see SubscriptionPlanPrice}. The ULID is the public id + route key.
 *
 * @property int $id
 * @property string $ulid
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string|null $tier
 * @property array<string, mixed> $metadata
 * @property SubscriptionPlanStatus $status
 * @property int $sort_order
 */
class SubscriptionPlan extends Model
{
    /** @use HasFactory<SubscriptionPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'tier',
        'metadata',
        'status',
        'sort_order',
    ];

    /** @return Factory<SubscriptionPlan> */
    protected static function newFactory(): Factory
    {
        return SubscriptionPlanFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (SubscriptionPlan $plan): void {
            if (! isset($plan->ulid)) {
                $plan->ulid = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => JsonObject::class,
            'status' => SubscriptionPlanStatus::class,
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<SubscriptionPlanPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(SubscriptionPlanPrice::class, 'plan_id');
    }

    /** @return HasMany<PlanEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class, 'plan_id');
    }
}
