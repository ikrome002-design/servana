<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\EarningsQueryStatus;
use App\Domain\Compensation\Exceptions\CompensationValidationException;
use App\Domain\Compensation\Models\EarningsQuery;
use App\Domain\Compensation\Services\EarningsQueryStateMachine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finance responds to an earnings query — resolve or reject (Plan §63; §H12; §25.4). Transitions
 * `open|assigned → resolved|rejected` (state machine; a terminal query fails closed, so a replay
 * cannot duplicate anything). **Resolution NEVER mutates a ledger silently:** a monetary correction is
 * created ONLY through {@see RecordCompensationAdjustment::manual} (Phase 20G invariants) and linked via
 * `resolved_adjustment_id`. The query is row-locked. Audits `earnings_query.resolved` / `.rejected`.
 */
final class RespondToEarningsQuery
{
    public function __construct(
        private readonly EarningsQueryStateMachine $machine,
        private readonly RecordCompensationAdjustment $adjustments,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{amount_minor: int, currency: string, reason: string}|null  $correction  optional
     *                                                                                       monetary correction created as a compensation_adjustment (resolve only)
     */
    public function handle(
        EarningsQuery $query,
        User $responder,
        EarningsQueryStatus $decision,
        string $resolutionNote,
        ?array $correction = null,
    ): EarningsQuery {
        if ($decision !== EarningsQueryStatus::Resolved && $decision !== EarningsQueryStatus::Rejected) {
            throw CompensationValidationException::earningsQueryDecision();
        }

        return DB::transaction(function () use ($query, $responder, $decision, $resolutionNote, $correction): EarningsQuery {
            $locked = EarningsQuery::query()->whereKey($query->id)->lockForUpdate()->firstOrFail();
            $this->machine->ensure($locked->status, $decision);

            $adjustmentUlid = null;
            if ($decision === EarningsQueryStatus::Resolved && $correction !== null) {
                $staff = $locked->staffProfile()->firstOrFail();
                $adjustment = $this->adjustments->manual(
                    $staff,
                    (int) $locked->branch_id,
                    (int) $correction['amount_minor'],
                    (string) $correction['currency'],
                    (string) $correction['reason'],
                    $responder,
                    $responder,
                );
                $locked->resolved_adjustment_id = $adjustment->id;
                $adjustmentUlid = $adjustment->ulid;
            }

            $locked->status = $decision;
            $locked->resolution_note = $resolutionNote;
            $locked->responded_by = $responder->id;
            $locked->responded_at = Carbon::now();
            $locked->save();

            $this->audit->record(
                $decision === EarningsQueryStatus::Resolved ? AuditEvent::EarningsQueryResolved : AuditEvent::EarningsQueryRejected,
                $responder,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'earnings_query_id' => $locked->ulid,
                    'status' => $locked->status->value,
                    'resolved_adjustment_id' => $adjustmentUlid,
                ],
            );

            return $locked->refresh();
        });
    }
}
