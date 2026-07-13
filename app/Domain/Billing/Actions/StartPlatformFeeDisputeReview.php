<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PlatformFeeDisputeStatus;
use App\Domain\Billing\Models\PlatformFeeDispute;
use App\Domain\Billing\Services\PlatformFeeDisputeStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Start review of a platform-fee dispute (Plan §13.10 [Correction 3]; Phase 20E, Increment 5C;
 * `open → under_review`). Assigns the reviewer and moves the case into review; an invalid transition
 * (e.g. reviewing a terminal case) raises `422 invalid_state_transition`. No money changes here.
 */
final class StartPlatformFeeDisputeReview
{
    public function __construct(
        private readonly PlatformFeeDisputeStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PlatformFeeDispute $dispute, User $reviewer): PlatformFeeDispute
    {
        return DB::transaction(function () use ($dispute, $reviewer): PlatformFeeDispute {
            /** @var PlatformFeeDispute $locked */
            $locked = PlatformFeeDispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, PlatformFeeDisputeStatus::UnderReview);

            $locked->forceFill([
                'status' => PlatformFeeDisputeStatus::UnderReview->value,
                'assigned_reviewer' => $reviewer->id,
            ])->save();

            $this->audit->record(AuditEvent::PlatformFeeDisputeReviewStarted, $reviewer, $locked->merchant_id, $locked->branch_id, $locked, [
                'dispute_id' => $locked->ulid,
                'reviewed_at' => CarbonImmutable::now()->toIso8601String(),
            ]);

            return $locked;
        });
    }
}
