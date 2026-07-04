<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Enums\FinanceDisputeStatus;
use App\Domain\FinanceOps\Models\FinanceDispute;
use App\Domain\FinanceOps\Services\FinanceDisputeStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Move a finance dispute from open to under_review (Plan §44; Phase 18B). Does not
 * mutate the disputed source record.
 */
final class StartFinanceDisputeReview
{
    public function __construct(
        private readonly FinanceDisputeStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(FinanceDispute $dispute, User $actor): FinanceDispute
    {
        return DB::transaction(function () use ($dispute, $actor): FinanceDispute {
            /** @var FinanceDispute $locked */
            $locked = FinanceDispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, FinanceDisputeStatus::UnderReview);
            $locked->forceFill(['status' => FinanceDisputeStatus::UnderReview->value])->save();

            $this->audit->record(AuditEvent::FinanceDisputeReviewStarted, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
                'dispute_id' => $locked->ulid,
            ]);

            return $locked;
        });
    }
}
