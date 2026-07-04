<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Enums\FinanceExportStatus;
use App\Domain\FinanceOps\Enums\FinanceExportType;
use App\Domain\FinanceOps\Exceptions\FinanceExportException;
use App\Domain\FinanceOps\Jobs\GenerateFinanceExport;
use App\Domain\FinanceOps\Models\FinanceExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Request a scoped, masked finance export (Plan §65, §67; Gate I; Phase 18B).
 * Finance-owned (`finance_export.create`, fresh step-up on the route). Only the
 * currently-supported types (invoices/payments/receipts/cash_up/refunds/disputes) may
 * be requested — compensation/payouts/billing are rejected with `422
 * unsupported_export_type`. Creates a `queued` export and dispatches
 * {@see GenerateFinanceExport} on the `reports-exports` queue. `finance_export.*` is
 * `PL n/a` — no period-lock gate. Audits `finance_export.requested` (no export contents).
 */
final class RequestFinanceExport
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $scope
     */
    public function handle(FinanceExportType $type, ?int $branchId, array $scope, string $reason, User $requester): FinanceExport
    {
        if (! $type->isCurrentlySupported()) {
            throw FinanceExportException::unsupportedType();
        }

        return DB::transaction(function () use ($type, $branchId, $scope, $reason, $requester): FinanceExport {
            $export = FinanceExport::query()->create([
                'branch_id' => $branchId,
                'requested_by' => $requester->id,
                'export_type' => $type->value,
                'scope_json' => $scope,
                'reason' => $reason,
                'status' => FinanceExportStatus::Queued->value,
                'download_count' => 0,
            ]);

            $this->audit->record(AuditEvent::FinanceExportRequested, $requester, $export->merchant_id, $export->branch_id, $export, [
                'export_id' => $export->ulid,
                'export_type' => $type->value,
                'branch_scope' => $branchId === null ? 'merchant' : 'branch',
            ]);

            GenerateFinanceExport::dispatch($export->id, $export->merchant_id, $export->branch_id)
                ->afterCommit()
                ->onQueue('reports-exports');

            return $export;
        });
    }
}
