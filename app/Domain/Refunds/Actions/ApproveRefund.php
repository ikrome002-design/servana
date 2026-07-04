<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Exceptions\RefundException;
use App\Domain\Refunds\Models\Refund;
use App\Domain\Refunds\Services\RefundStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Approve a requested refund (Plan §44; Phase 18B). Maker/checker: the approver may not
 * be the requester. Fresh MFA step-up is enforced by the route (§19.3). Period gate
 * enforced. One atomic transaction: lock the refund → require requested → approver !=
 * requester → status approved → safe audit. Approval does not yet recognise any balance
 * change (that is {@see FinalizeRefund}).
 */
final class ApproveRefund
{
    public function __construct(
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly RefundStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Refund $refund, User $approver): Refund
    {
        $this->periodGuard->ensureOpen($refund->merchant_id, $refund->branch_id);

        return DB::transaction(function () use ($refund, $approver): Refund {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($locked->requested_by === $approver->id) {
                throw RefundException::makerIsChecker();
            }
            $this->machine->ensure($locked->status, RefundStatus::Approved);

            $locked->forceFill([
                'status' => RefundStatus::Approved->value,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ])->save();

            $this->audit->record(AuditEvent::RefundApproved, $approver, $locked->merchant_id, $locked->branch_id, $locked, [
                'refund_id' => $locked->ulid,
                'amount_minor' => $locked->amount_minor,
                'currency' => $locked->currency,
            ]);

            return $locked;
        });
    }
}
