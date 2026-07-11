<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Database\Factories\PlanEntitlementFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PlanEntitlement — per-plan entitlement limit; the Plan §20 resolver/gate substrate
 * (Plan §13.9, §20, §47; Phase 20A). Platform-owned. `unique(plan_id, entitlement_key)`;
 * `limit_int` null = unlimited when `enabled`. Managed as a plan child (no ULID route key).
 *
 * @property int $id
 * @property int $plan_id
 * @property string $entitlement_key
 * @property int|null $limit_int
 * @property bool $enabled
 */
class PlanEntitlement extends Model
{
    /** @use HasFactory<PlanEntitlementFactory> */
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'entitlement_key',
        'limit_int',
        'enabled',
    ];

    /** @return Factory<PlanEntitlement> */
    protected static function newFactory(): Factory
    {
        return PlanEntitlementFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'limit_int' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }
}
