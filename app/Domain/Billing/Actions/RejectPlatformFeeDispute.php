<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PlatformFeeDisputeStatus;
use App\Domain\Billing\Exceptions\PlatformFeeDisputeException;
use App\Domain\Billing\Models\PlatformFeeDispute;
use App\Domain\Billing\Services\PlatformFeeDisputeStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reject a platform-fee dispute (Plan §13.10 [Correction 3]; Phase 20E, Increment 5C;
 * `{open, under_review} → rejected`). A mandatory rejection note is required and the creator may not
 * self-reject (maker/checker). No money changes. An invalid transition (e.g. rejecting a terminal case)
 * raises `422 invalid_state_transition`.
 */
final class RejectPlatformFeeDispute
{
    public function __construct(
        private readonly PlatformFeeDisputeStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PlatformFeeDispute $dispute, User $resolver, string $resolutionNote): PlatformFeeDispute
    {
        $resolutionNote = trim($resolutionNote);
        if ($resolutionNote === '') {
            throw PlatformFeeDisputeException::resolutionNoteRequired();
        }

        if ($dispute->created_by === $resolver->id) {
            throw PlatformFeeDisputeException::selfResolutionBlocked();
        }

        return DB::transaction(function () use ($dispute, $resolver, $resolutionNote): PlatformFeeDispute {
            /** @var PlatformFeeDispute $locked */
            $locked = PlatformFeeDispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, PlatformFeeDisputeStatus::Rejected);

            $locked->forceFill([
                'status' => PlatformFeeDisputeStatus::Rejected->value,
                'resolved_by' => $resolver->id,
                'resolved_at' => CarbonImmutable::now(),
                'resolution_note' => $resolutionNote,
            ])->save();

            $this->audit->record(AuditEvent::PlatformFeeDisputeRejected, $resolver, $locked->merchant_id, $locked->branch_id, $locked, [
                'dispute_id' => $locked->ulid,
            ]);

            return $locked;
        });
    }
}
