<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\ScheduledPlanChangeStatus;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Models\ScheduledPlanChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a scheduled plan change (Plan §48; Phase 20B). scheduled → cancelled; applied/cancelled are
 * immutable (422 invalid_state_transition). Requires an active tenant context.
 */
final class CancelScheduledPlanChange
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(ScheduledPlanChange $change, User $actor): ScheduledPlanChange
    {
        return DB::transaction(function () use ($change, $actor): ScheduledPlanChange {
            $locked = ScheduledPlanChange::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo(ScheduledPlanChangeStatus::Cancelled)) {
                throw BillingStateException::invalidTransition('scheduled plan change', $locked->status->value, ScheduledPlanChangeStatus::Cancelled->value);
            }

            $locked->status = ScheduledPlanChangeStatus::Cancelled;
            $locked->cancelled_at = CarbonImmutable::now();
            $locked->save();

            $this->audit->record(AuditEvent::SubscriptionPlanChangeCancelled, $actor, $locked->merchant_id, null, $locked, [
                'scheduled_change_id' => $locked->ulid,
                'merchant_subscription_id' => $locked->merchant_subscription_id,
            ]);

            return $locked;
        });
    }
}
