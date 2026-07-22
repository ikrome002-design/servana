<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Jobs;

use App\Domain\Integrations\ReferEarn\Actions\ValidateReferralCode;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Asynchronous referral-code validation (Plan §58A.1, §67; §58B.5 R-03; Phase 21R-A).
 *
 * Dispatched AFTER the registration transaction commits, so a queue failure can never roll back a
 * merchant registration and a worker can never read a half-written snapshot.
 *
 * NOT a `TenantAwareJob`: at dispatch time the merchant is `pending_setup`, and `TenantAwareJob`
 * refuses to rehydrate context for a non-active merchant. The job carries one snapshot id, touches
 * only that row, and reaches no merchant-scoped surface.
 *
 * Retries are bounded and backed off. A snapshot R&E never answers for stays `validating` and is
 * settled by the confirm-window expiry, not by an unbounded retry loop.
 */
final class ValidateReferralCodeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var list<int> exponential-ish backoff in seconds, bounded by */
    public array $backoff = [30, 120, 600, 1800, 3600];

    public function __construct(public readonly int $referralSnapshotId)
    {
        $this->onQueue((string) config('refer-earn.jobs.queue', 're-outbox'));
    }

    public function tries(): int
    {
        return (int) config('refer-earn.jobs.tries', 5);
    }

    public function handle(ValidateReferralCode $validate): void
    {
        $snapshot = ReferralSnapshot::query()->find($this->referralSnapshotId);

        if ($snapshot === null) {
            return;
        }

        // A false return means "no verdict yet" (R&E unreachable / 5xx / rate-limited). Releasing
        // the job back to the queue is what implements the §58A.1 "retried with backoff" contract;
        // the snapshot deliberately stays in the same state (§25.6).
        if (! $validate->handle($snapshot)) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }
}
