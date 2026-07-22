<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Integrations\ReferEarn\Clients\Dto\EventDeliveryResult;
use App\Domain\Integrations\ReferEarn\Clients\ReferEarnClientInterface;
use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryStatus;
use App\Domain\Integrations\ReferEarn\Models\ReEventDelivery;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use App\Domain\Integrations\ReferEarn\Support\CanonicalJson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deliver ONE outbox event to Citrus R&E and record the attempt (Plan §58A.2, §25.6; §9 rule 22;
 * Phase 21R-A).
 *
 * Shape of the operation:
 *
 *   1. **Claim** the event by moving `pending → delivering` under a row lock, so two workers cannot
 *      deliver the same event concurrently. A claim failure is not an error — it means someone else
 *      has it.
 *   2. **Order** — refuse to deliver while an EARLIER `sequence_no` for the same merchant is still
 *      undelivered, because R&E workers consume a merchant's events in order (§58A.2).
 *   3. **Send** the payload re-encoded canonically. Because the encoder is deterministic and the
 *      payload is frozen by trigger, those bytes are identical on every attempt — which is what
 *      makes R&E's `409 EVENT_ID_PAYLOAD_MISMATCH` a genuine tamper signal.
 *   4. **Record** every attempt in `re_event_deliveries` with a redacted, bounded body — including
 *      attempts that merely reschedule.
 *   5. **Settle** to `delivered`, back to `pending` with backoff, or to `dead_letter`.
 *
 * Nothing here throws for a partner failure: an unhappy R&E is an expected outcome that must be
 * recorded, not an exception that fails a job and loses the attempt trail.
 */
final class DeliverProductEvent
{
    public function __construct(
        private readonly ReferEarnClientInterface $client,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(ReOutboundEvent $event): ?EventDeliveryResult
    {
        $claimed = $this->claim($event);

        if ($claimed === null) {
            return null;
        }

        $body = CanonicalJson::encode($claimed->payload);

        $result = $this->client->deliverEvent($claimed, $body);

        $this->recordAttempt($claimed, $result);
        $this->settle($claimed, $result);

        return $result;
    }

    /**
     * Move `pending → delivering` under a row lock, honouring per-merchant ordering.
     *
     * Returns null when the event is not deliverable right now: already claimed, already terminal,
     * not yet due, or blocked behind an earlier undelivered event for the same merchant.
     */
    private function claim(ReOutboundEvent $event): ?ReOutboundEvent
    {
        return DB::transaction(function () use ($event): ?ReOutboundEvent {
            $locked = ReOutboundEvent::query()->whereKey($event->id)->lockForUpdate()->first();

            if ($locked === null || $locked->delivery_status !== ReDeliveryStatus::Pending) {
                return null;
            }

            if ($locked->next_attempt_at !== null && $locked->next_attempt_at->isFuture()) {
                return null;
            }

            if ($this->hasEarlierUndeliveredEvent($locked)) {
                return null;
            }

            $locked->delivery_status = ReDeliveryStatus::Delivering;
            $locked->save();

            return $locked;
        });
    }

    /** Per-merchant ordering guard (Plan §58A.2, §72). */
    private function hasEarlierUndeliveredEvent(ReOutboundEvent $event): bool
    {
        if ($event->merchant_id === null) {
            return false;
        }

        return ReOutboundEvent::query()
            ->where('merchant_id', $event->merchant_id)
            ->where('sequence_no', '<', $event->sequence_no)
            ->whereIn('delivery_status', [ReDeliveryStatus::Pending->value, ReDeliveryStatus::Delivering->value])
            ->exists();
    }

    private function recordAttempt(ReOutboundEvent $event, EventDeliveryResult $result): void
    {
        ReEventDelivery::query()->create([
            're_outbound_event_id' => $event->id,
            'attempted_at' => now(),
            'duration_ms' => max(0, $result->durationMs),
            'response_status' => $result->status,
            'response_class' => $result->class,
            'error_code' => $result->errorCode,
            // Already redacted by the client; the column bound is the second line of defence.
            'response_body_truncated_redacted' => $result->redactedBody,
        ]);
    }

    private function settle(ReOutboundEvent $event, EventDeliveryResult $result): void
    {
        $attemptCount = $event->attempt_count + 1;

        if ($result->isAccepted()) {
            $event->forceFill([
                'delivery_status' => ReDeliveryStatus::Delivered,
                'delivered_at' => now(),
                'attempt_count' => $attemptCount,
                'next_attempt_at' => null,
                'last_response_status' => $result->status,
                'last_error_code' => null,
            ])->save();

            return;
        }

        if ($result->class->isPermanentFailure() || $this->exceededMaxAge($event)) {
            $this->deadLetter($event, $result, $attemptCount);

            return;
        }

        if ($result->class->requiresCredentialAlert()) {
            // Plan §58A.2: 401/403 pauses the queue and alerts. The event itself stays retriable —
            // a credential problem is not the event's fault. No credential value is logged (§24.5).
            Log::critical('refer_earn.delivery.credential_failure', [
                'event_id' => $event->event_id,
                'event_type' => $event->event_type->value,
                'response_status' => $result->status,
            ]);
        }

        $event->forceFill([
            'delivery_status' => ReDeliveryStatus::Pending,
            'attempt_count' => $attemptCount,
            'next_attempt_at' => $this->nextAttemptAt($attemptCount, $result),
            'last_response_status' => $result->status,
            'last_error_code' => $result->errorCode ?? $result->class->value,
        ])->save();
    }

    private function deadLetter(ReOutboundEvent $event, EventDeliveryResult $result, int $attemptCount): void
    {
        $event->forceFill([
            'delivery_status' => ReDeliveryStatus::DeadLetter,
            'attempt_count' => $attemptCount,
            'next_attempt_at' => null,
            'last_response_status' => $result->status,
            'last_error_code' => $result->errorCode ?? $result->class->value,
        ])->save();

        // Platform-chain audit (null merchant chain): this is an integration-operations fact, not a
        // merchant action, and it has no actor. Context is deliberately minimal — no signature, no
        // nonce, no credential, no response body (Plan §24.5).
        $this->audit->record(AuditEvent::ReEventDeadLettered, null, null, null, $event, [
            'event_id' => $event->event_id,
            'event_type' => $event->event_type->value,
            'response_class' => $result->class->value,
            'response_status' => $result->status,
            'attempt_count' => $attemptCount,
        ]);
    }

    private function exceededMaxAge(ReOutboundEvent $event): bool
    {
        $maxAgeDays = (int) config('refer-earn.delivery.max_age_days', 7);

        return $event->created_at !== null
            && $event->created_at->addDays($maxAgeDays)->isPast();
    }

    /**
     * Exponential backoff with jitter (Plan §58A.2: base 30 s, cap 1 h), honouring `Retry-After`
     * when the partner supplied one. Jitter prevents a whole backlog retrying in lockstep.
     */
    private function nextAttemptAt(int $attemptCount, EventDeliveryResult $result): Carbon
    {
        $base = (int) config('refer-earn.delivery.backoff_base_seconds', 30);
        $cap = (int) config('refer-earn.delivery.backoff_cap_seconds', 3600);

        $exponential = min($cap, $base * (2 ** max(0, $attemptCount - 1)));
        $delay = max($exponential, $result->retryAfterSeconds ?? 0);
        $delay = min($cap, $delay);

        $jitter = random_int(0, max(1, (int) floor($delay * 0.2)));

        return now()->addSeconds($delay + $jitter);
    }
}
