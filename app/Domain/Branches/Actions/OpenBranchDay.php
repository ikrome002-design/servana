<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\MerchantBranch;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Open a branch's business day (Scope §3.3 Day Opening/Closing). Idempotent per
 * (branch, business_date): re-opening a closed day records a reopen.
 */
final class OpenBranchDay
{
    public function handle(MerchantBranch $branch, User $actor, ?string $businessDate = null): BranchDayRecord
    {
        $date = $businessDate ?? Carbon::now('Africa/Nairobi')->toDateString();

        $record = BranchDayRecord::query()->firstOrNew([
            'branch_id' => $branch->id,
            'business_date' => $date,
        ]);

        $reopening = $record->exists && $record->status === BranchDayStatus::Closed;

        $record->status = $reopening ? BranchDayStatus::Reopened : BranchDayStatus::Open;
        $record->opened_by = $actor->id;
        $record->opened_at = now();
        if ($reopening) {
            $record->reopened_reason = 'reopened';
        }
        $record->save();

        return $record->refresh();
    }
}
