<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\BranchCalendarException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Remove a branch calendar exception (REM-SCR-002B).
 *
 * A calendar exception is forward-looking scheduling CONFIGURATION, not a financial or
 * operational ledger fact, so a hard delete is correct — the table is not append-only and
 * carries no money. History is preserved where it matters: the removal emits
 * `branch.calendar_exception_removed` into the append-only, hash-chained audit trail with the
 * date and type, so the change is always reconstructible.
 */
final class DeleteBranchCalendarException
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(BranchCalendarException $exception, User $actor): void
    {
        DB::transaction(function () use ($exception, $actor): void {
            $context = [
                'date' => $exception->date->toDateString(),
                'type' => $exception->type->value,
            ];
            $merchantId = $exception->merchant_id;
            $branchId = $exception->branch_id;

            // Audit BEFORE the delete so the subject still exists for the auditable reference.
            $this->audit->record(
                AuditEvent::BranchCalendarExceptionRemoved,
                $actor,
                $merchantId,
                $branchId,
                $exception,
                $context,
            );

            $exception->delete();
        });
    }
}
