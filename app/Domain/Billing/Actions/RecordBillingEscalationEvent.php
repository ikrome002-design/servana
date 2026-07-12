<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\BillingEscalationEventType;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Models\BillingEscalationEvent;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Record a durable, append-only billing-escalation event (Plan §13.15, §54; Gate B4; Phase 20B).
 *
 * Idempotent per `(merchant_subscription_id, event_type, period_boundary)` — enforced by the DB unique
 * constraint via `insertOrIgnore` (never by `created_at`). A replayed escalation for the same
 * subscription/event/period is a no-op and returns the existing row. No UPDATE/DELETE path exists.
 * Emits the matching typed audit event only when a new row is written. Requires an active tenant context.
 */
final class RecordBillingEscalationEvent
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(
        MerchantSubscription $subscription,
        BillingEscalationEventType $eventType,
        CarbonImmutable $periodBoundary,
        ?MerchantBillingStatus $fromStatus = null,
        ?MerchantBillingStatus $toStatus = null,
        ?string $reason = null,
        ?int $subscriptionInvoiceId = null,
        ?User $actor = null,
    ): BillingEscalationEvent {
        return DB::transaction(function () use ($subscription, $eventType, $periodBoundary, $fromStatus, $toStatus, $reason, $subscriptionInvoiceId, $actor): BillingEscalationEvent {
            $boundary = $periodBoundary->toDateString();

            $inserted = BillingEscalationEvent::query()->insertOrIgnore([
                'ulid' => (string) Str::ulid(),
                'merchant_id' => $subscription->merchant_id,
                'subscription_invoice_id' => $subscriptionInvoiceId,
                'merchant_subscription_id' => $subscription->id,
                'event_type' => $eventType->value,
                'from_billing_status' => $fromStatus?->value,
                'to_billing_status' => $toStatus?->value,
                'reason' => $reason,
                'period_boundary' => $boundary,
                'created_at' => now(),
            ]);

            /** @var BillingEscalationEvent $event */
            $event = BillingEscalationEvent::query()
                ->where('merchant_subscription_id', $subscription->id)
                ->where('event_type', $eventType->value)
                ->where('period_boundary', $boundary)
                ->firstOrFail();

            if ($inserted > 0) {
                $this->audit->record($this->auditEventFor($eventType), $actor, $subscription->merchant_id, null, $event, [
                    'subscription_id' => $subscription->ulid,
                    'event_type' => $eventType->value,
                    'period_boundary' => $boundary,
                ]);
            }

            return $event;
        });
    }

    private function auditEventFor(BillingEscalationEventType $eventType): AuditEvent
    {
        return match ($eventType) {
            BillingEscalationEventType::Reminder => AuditEvent::BillingEscalationReminder,
            BillingEscalationEventType::GraceEntered => AuditEvent::BillingEscalationGraceEntered,
            BillingEscalationEventType::Overdue => AuditEvent::BillingEscalationOverdue,
            BillingEscalationEventType::SuspendedBilling => AuditEvent::BillingEscalationSuspended,
            BillingEscalationEventType::Recovered => AuditEvent::BillingEscalationRecovered,
        };
    }
}
