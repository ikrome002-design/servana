<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Actions;

use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryStatus;
use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Integrations\ReferEarn\Jobs\DeliverReOutboxJob;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use App\Domain\Integrations\ReferEarn\Support\CanonicalJson;
use App\Domain\Integrations\ReferEarn\Support\MerchantEventPayloadBuilder;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Enqueue one outbound fact for Citrus R&E (Plan §58A.2, §13.17, §9 rule 22; ADR-013; Phase 21R-A).
 *
 * This is the ONLY writer of `re_outbound_events`. It:
 *
 *   1. refuses to run outside a database transaction — the outbox guarantee is that a fact and its
 *      event commit or roll back TOGETHER, and an enqueue outside the source transaction would
 *      silently break that;
 *   2. applies the §58B.1 emission-scope rule — a merchant with no referral snapshot, a malformed
 *      code, or a rejected claim streams NOTHING to R&E;
 *   3. allocates a per-merchant monotonic `sequence_no` under a transaction-scoped advisory lock,
 *      so concurrent registrations/status changes for the same merchant cannot interleave;
 *   4. generates the ULID `event_id` ONCE and computes `content_sha256` over the canonical JSON, so
 *      every retry signs and sends byte-identical content;
 *   5. dispatches delivery `afterCommit()`, so a worker can never observe — or deliver — an event
 *      whose source transaction rolled back.
 *
 * The advisory lock is keyed on the merchant, so events for different merchants never contend —
 * which matters because ordering is only required *within* a merchant partition (§58A.2).
 */
final class EnqueueProductEvent
{
    /** Namespace constant for pg_advisory_xact_lock, so this lock cannot collide with another feature's. */
    private const LOCK_NAMESPACE = 21_0001;

    public function __construct(private readonly MerchantEventPayloadBuilder $payloads) {}

    /**
     * @param  array<string, mixed>  $context  event-specific inputs (reason category, hashes, …)
     * @return ReOutboundEvent|null null when the emission-scope rule suppressed the event
     */
    public function handle(
        ReOutboundEventType $type,
        Merchant $merchant,
        array $context = [],
        ?Carbon $occurredAt = null,
    ): ?ReOutboundEvent {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'EnqueueProductEvent must run inside the source fact\'s database transaction (outbox pattern, Plan §58A.2).'
            );
        }

        if (! $this->mayEmitFor($merchant)) {
            return null;
        }

        $occurredAt ??= now();
        $eventId = (string) Str::ulid();
        $sequenceNo = $this->nextSequenceNo($merchant);

        $payload = $this->payloads->envelope($type, $eventId, $merchant->ulid, $sequenceNo, $occurredAt)
            + $this->payloads->facts($type, $merchant, $context);

        $event = ReOutboundEvent::query()->create([
            'event_id' => $eventId,
            'event_type' => $type,
            'event_version' => $type->version(),
            'merchant_id' => $merchant->id,
            'merchant_public_id' => $merchant->ulid,
            'sequence_no' => $sequenceNo,
            'payload' => $payload,
            'content_sha256' => CanonicalJson::sha256($payload),
            'occurred_at' => $occurredAt,
            'delivery_status' => ReDeliveryStatus::Pending,
            'attempt_count' => 0,
            'next_attempt_at' => $occurredAt,
        ]);

        // Event-driven dispatch AT COMMIT is the normal delivery path; the scheduler sweep is only
        // the safety net (Plan §58A.2, §67). `afterCommit()` is what makes that safe: a worker must
        // never see — let alone deliver — an event whose source transaction later rolled back.
        DeliverReOutboxJob::dispatch($event->id)->afterCommit();

        return $event;
    }

    /**
     * Emission-scope rule (Plan §58B.1). R&E has no business need for facts about merchants it has
     * no live referral claim on, and Servana must not stream its merchant lifecycle to a partner
     * beyond that need.
     */
    public function mayEmitFor(Merchant $merchant): bool
    {
        $snapshot = ReferralSnapshot::query()->where('merchant_id', $merchant->id)->first();

        return $snapshot !== null && $snapshot->permitsEventEmission();
    }

    /**
     * Per-merchant monotonic sequence under a transaction-scoped advisory lock.
     *
     * The lock is held until the enclosing transaction commits, so a concurrent enqueue for the same
     * merchant blocks until this row exists and therefore reads the new maximum. The
     * `UNIQUE (merchant_id, sequence_no)` constraint remains the authoritative backstop.
     */
    private function nextSequenceNo(Merchant $merchant): int
    {
        DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, $merchant->id]);

        $max = DB::table('re_outbound_events')->where('merchant_id', $merchant->id)->max('sequence_no');

        return (int) $max + 1;
    }
}
