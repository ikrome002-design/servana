<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Enums\FinanceDisputeStatus;
use App\Domain\FinanceOps\Exceptions\FinanceDisputeException;
use App\Domain\FinanceOps\Models\FinanceDispute;
use App\Domain\FinanceOps\Services\FinanceDisputeStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolve a finance dispute under review (Plan §44; Phase 18B). Requires a resolution
 * note. Does not mutate the disputed source record.
 */
final class ResolveFinanceDispute
{
    public function __construct(
        private readonly FinanceDisputeStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(FinanceDispute $dispute, User $actor, string $resolutionNote): FinanceDispute
    {
        $resolutionNote = trim($resolutionNote);
        if ($resolutionNote === '') {
            throw FinanceDisputeException::resolutionNoteRequired();
        }

        return DB::transaction(function () use ($dispute, $actor, $resolutionNote): FinanceDispute {
            /** @var FinanceDispute $locked */
            $locked = FinanceDispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, FinanceDisputeStatus::Resolved);
            $locked->forceFill([
                'status' => FinanceDisputeStatus::Resolved->value,
                'resolution_note' => $resolutionNote,
                'resolved_by' => $actor->id,
            ])->save();

            $this->audit->record(AuditEvent::FinanceDisputeResolved, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
                'dispute_id' => $locked->ulid,
            ]);

            return $locked;
        });
    }
}
