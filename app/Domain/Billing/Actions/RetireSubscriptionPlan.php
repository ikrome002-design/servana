<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\SubscriptionPlanStatus;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Services\SubscriptionPlanStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Retire a subscription plan (active → retired) via the state machine (Plan §13.9, §47; Phase 20A).
 * Non-destructive: prices + entitlements are preserved for history / Phase-20B subscriptions.
 * Re-retiring a retired plan → 422 invalid_state_transition. Audits `subscription_plan.retired`.
 */
final class RetireSubscriptionPlan
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SubscriptionPlanStateMachine $stateMachine,
    ) {}

    public function handle(SubscriptionPlan $plan, User $actor): SubscriptionPlan
    {
        return DB::transaction(function () use ($plan, $actor): SubscriptionPlan {
            $locked = SubscriptionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            $this->stateMachine->ensure($locked->status, SubscriptionPlanStatus::Retired);

            $locked->status = SubscriptionPlanStatus::Retired;
            $locked->save();

            $this->audit->record(AuditEvent::SubscriptionPlanRetired, $actor, null, null, $locked, [
                'plan_id' => $locked->ulid,
            ]);

            return $locked;
        });
    }
}
