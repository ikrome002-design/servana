<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Branches\Exceptions\BranchClosureBlockedException;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Branches\Services\BranchClosureGuard;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Close a branch's business day (Scope §3.3). The day-close PDF/summary job is a
 * later phase; Phase 7 records the close state only. Same-day active appointments
 * block the close (Plan §25.2 — the appointment day-close guard flipped on by
 * Phase 16A); the close is audited (Plan §70).
 */
final class CloseBranchDay
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly BranchClosureGuard $guard,
    ) {}

    public function handle(MerchantBranch $branch, User $actor, ?string $businessDate = null): BranchDayRecord
    {
        $date = $businessDate ?? Carbon::now('Africa/Nairobi')->toDateString();

        $blockers = $this->guard->dayCloseBlockers($branch, $date);
        if ($blockers !== []) {
            throw BranchClosureBlockedException::because($blockers);
        }

        return DB::transaction(function () use ($branch, $actor, $date): BranchDayRecord {
            $record = BranchDayRecord::query()->firstOrNew([
                'branch_id' => $branch->id,
                'business_date' => $date,
            ]);
            $record->merchant_id = $branch->merchant_id; // R5: ownership from the branch

            $record->status = BranchDayStatus::Closed;
            $record->closed_by = $actor->id;
            $record->closed_at = now();
            $record->save();

            $this->audit->record(
                AuditEvent::BranchDayClosed,
                $actor,
                $branch->merchant_id,
                $branch->id,
                $record,
                ['business_date' => $date],
            );

            return $record->refresh();
        });
    }
}
