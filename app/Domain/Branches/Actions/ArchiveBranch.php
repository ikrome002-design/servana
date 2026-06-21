<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Enums\BranchStatus;
use App\Domain\Branches\Exceptions\BranchClosureBlockedException;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Branches\Services\BranchClosureGuard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Archive (close) a branch (Scope §3.3 Branch Closure and Archival Protection).
 *
 * Runs through BranchClosureGuard — a branch must not be archived while live
 * operational records exist. Blockers raise a structured exception listing them;
 * the guard checks are explicit (never silently skipped).
 */
final class ArchiveBranch
{
    public function __construct(
        private readonly BranchClosureGuard $guard,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(MerchantBranch $branch, User $actor, ?string $reason = null): MerchantBranch
    {
        $blockers = $this->guard->blockers($branch);

        if ($blockers !== []) {
            throw BranchClosureBlockedException::because($blockers);
        }

        return DB::transaction(function () use ($branch, $actor, $reason): MerchantBranch {
            $from = $branch->status->value;
            $branch->status = BranchStatus::Archived;
            $branch->status_reason = $reason;
            $branch->archived_at = now();
            $branch->updated_by = $actor->id;
            $branch->save();

            $this->audit->record(
                AuditEvent::BranchArchived,
                $actor,
                $branch->merchant_id,
                $branch->id,
                $branch,
                array_filter([
                    'old_values' => ['status' => $from],
                    'new_values' => ['status' => BranchStatus::Archived->value],
                    'reason' => $reason,
                ], static fn ($v): bool => $v !== null),
            );

            return $branch->refresh();
        });
    }
}
