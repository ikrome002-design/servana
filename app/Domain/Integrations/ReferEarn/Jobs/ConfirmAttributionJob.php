<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Jobs;

use App\Domain\Integrations\ReferEarn\Actions\ConfirmAttribution;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Asynchronous attribution confirmation (Plan §58A.1, §58A.2, §67; Phase 21R-A).
 *
 * The confirm call is idempotent by snapshot ULID at R&E, so a retry after an ambiguous network
 * failure is safe. Same non-tenant-aware rationale as ValidateReferralCodeJob: the merchant may not
 * be active, the job carries one snapshot id, and it reaches no merchant-scoped surface.
 */
final class ConfirmAttributionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800, 3600];

    public function __construct(public readonly int $referralSnapshotId)
    {
        $this->onQueue((string) config('refer-earn.jobs.queue', 're-outbox'));
    }

    public function tries(): int
    {
        return (int) config('refer-earn.jobs.tries', 5);
    }

    public function handle(ConfirmAttribution $confirm): void
    {
        $snapshot = ReferralSnapshot::query()->find($this->referralSnapshotId);

        if ($snapshot === null) {
            return;
        }

        if (! $confirm->handle($snapshot)) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }
}
