<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Jobs;

use App\Domain\Integrations\ReferEarn\Actions\DeliverProductEvent;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deliver one outbox event to Citrus R&E (Plan §58A.2, §67 queue `re-outbox`; Phase 21R-A).
 *
 * NOT a `TenantAwareJob`: the outbox is platform-side integration infrastructure, it must keep
 * delivering facts for merchants that are suspended or deactivated (a `merchant.status_changed`
 * event for a suspended merchant is exactly the fact R&E needs), and `TenantAwareJob` deliberately
 * refuses to run for a non-active merchant. Isolation is not at risk: the job carries one event id,
 * touches only that row and its attempt log, and no merchant-facing surface exists.
 *
 * `$tries = 1` on purpose. Retrying is the OUTBOX's job, not the queue's: `DeliverProductEvent`
 * records every attempt, applies the §58A.2 backoff and decides dead-lettering. A queue-level retry
 * would duplicate attempts, skew `attempt_count` and bypass the backoff schedule.
 */
final class DeliverReOutboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $outboundEventId)
    {
        $this->onQueue((string) config('refer-earn.delivery.queue', 're-outbox'));
    }

    public function handle(DeliverProductEvent $deliver): void
    {
        // The event may have been claimed, delivered or dead-lettered since dispatch; the action's
        // claim step is the authority and simply returns null when there is nothing to do.
        $event = ReOutboundEvent::query()->find($this->outboundEventId);

        if ($event === null) {
            return;
        }

        $deliver->handle($event);
    }
}
