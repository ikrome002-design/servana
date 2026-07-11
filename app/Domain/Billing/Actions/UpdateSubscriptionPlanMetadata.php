<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a subscription plan's NON-PRICE metadata (Plan §13.9, §47; ADR-011; Phase 20A).
 * Platform-governed. Never accepts a price/status change (retirement is a separate named action).
 * Audits `subscription_plan.metadata_updated`.
 */
final class UpdateSubscriptionPlanMetadata
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{name?:string,description?:string|null,tier?:string|null,metadata?:array<string,mixed>,sort_order?:int}  $data
     */
    public function handle(SubscriptionPlan $plan, array $data, User $actor): SubscriptionPlan
    {
        return DB::transaction(function () use ($plan, $data, $actor): SubscriptionPlan {
            $locked = SubscriptionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            $locked->fill(array_intersect_key($data, array_flip(['name', 'description', 'tier', 'metadata', 'sort_order'])));
            $locked->save();

            $this->audit->record(AuditEvent::SubscriptionPlanMetadataUpdated, $actor, null, null, $locked, [
                'plan_id' => $locked->ulid,
            ]);

            return $locked;
        });
    }
}
