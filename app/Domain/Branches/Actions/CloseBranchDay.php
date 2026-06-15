<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\MerchantBranch;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Close a branch's business day (Scope §3.3). The day-close PDF/summary job is a
 * later phase; Phase 7 records the close state only.
 */
final class CloseBranchDay
{
    public function handle(MerchantBranch $branch, User $actor, ?string $businessDate = null): BranchDayRecord
    {
        $date = $businessDate ?? Carbon::now('Africa/Nairobi')->toDateString();

        $record = BranchDayRecord::query()->firstOrNew([
            'branch_id' => $branch->id,
            'business_date' => $date,
        ]);

        $record->status = BranchDayStatus::Closed;
        $record->closed_by = $actor->id;
        $record->closed_at = now();
        $record->save();

        return $record->refresh();
    }
}
