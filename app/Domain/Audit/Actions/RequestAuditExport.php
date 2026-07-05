<?php

declare(strict_types=1);

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditExportStatus;
use App\Domain\Audit\Jobs\GenerateAuditExport;
use App\Domain\Audit\Models\AuditExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Request a reason-gated, branch-scoped, masked Audit export (Plan §13.5, §80; Phase 19;
 * ADR-010). Audit-owned (`audit.export`, fresh step-up on the route). Creates a `queued`
 * row that stores the validated filter SNAPSHOT in `scope_json` and returns immediately
 * with its ULID; generation runs async on `reports-exports`. Never exports merchant-level
 * (`branch_id` null) audit rows. Audits `audit_export.requested` (never export contents).
 */
final class RequestAuditExport
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $scope  validated filter snapshot (branch/date/domains/severities)
     */
    public function handle(int $merchantId, int $branchId, array $scope, string $reason, User $requester): AuditExport
    {
        return DB::transaction(function () use ($merchantId, $branchId, $scope, $reason, $requester): AuditExport {
            $export = AuditExport::query()->create([
                'merchant_id' => $merchantId,
                'branch_id' => $branchId,
                'requested_by_user_id' => $requester->id,
                'reason' => $reason,
                'scope_json' => $scope,
                'status' => AuditExportStatus::Queued->value,
                'download_count' => 0,
                'requested_at' => now(),
            ]);

            $this->audit->record(AuditEvent::AuditExportRequested, $requester, $export->merchant_id, $export->branch_id, $export, [
                'export_id' => $export->ulid,
                'scope' => $this->safeScopeSummary($scope),
            ]);

            GenerateAuditExport::dispatch($export->id, $export->merchant_id, $export->branch_id)
                ->afterCommit()
                ->onQueue('reports-exports');

            return $export;
        });
    }

    /**
     * A safe, non-sensitive summary of the export scope for the audit context (counts +
     * allowlisted classifications only — never raw client input).
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function safeScopeSummary(array $scope): array
    {
        return [
            'has_date_from' => isset($scope['date_from']),
            'has_date_to' => isset($scope['date_to']),
            'domain_count' => is_array($scope['domains'] ?? null) ? count($scope['domains']) : 0,
            'severity_count' => is_array($scope['severities'] ?? null) ? count($scope['severities']) : 0,
        ];
    }
}
