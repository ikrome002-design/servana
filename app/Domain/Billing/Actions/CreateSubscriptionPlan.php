<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\SubscriptionPlanStatus;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a subscription plan — NON-PRICE catalogue metadata only (Plan §13.9, §47; ADR-011;
 * Phase 20A). Platform-governed (Super-Admin platform_mutation). Never accepts a price (price is
 * the sole responsibility of `subscription_plan_prices`). Audits `subscription_plan.created`.
 */
final class CreateSubscriptionPlan
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{key:string,name:string,description?:string|null,tier?:string|null,metadata?:array<string,mixed>,sort_order?:int}  $data
     */
    public function handle(array $data, User $actor): SubscriptionPlan
    {
        return DB::transaction(function () use ($data, $actor): SubscriptionPlan {
            $plan = SubscriptionPlan::query()->create([
                'key' => $data['key'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'tier' => $data['tier'] ?? null,
                'metadata' => $data['metadata'] ?? [],
                'status' => SubscriptionPlanStatus::Active,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            $this->audit->record(AuditEvent::SubscriptionPlanCreated, $actor, null, null, $plan, [
                'plan_id' => $plan->ulid,
                'key' => $plan->key,
            ]);

            return $plan;
        });
    }
}
