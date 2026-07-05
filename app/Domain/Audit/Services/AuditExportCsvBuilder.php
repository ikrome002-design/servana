<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Enums\AuditDomain;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Support\AuditValueMasker;
use App\Domain\Branches\Models\MerchantBranch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the MASKED, branch-scoped CSV for an Audit export (Plan §13.5, §74, §80;
 * Phase 19; ADR-010). Scope is applied IN the query — merchant_id + the snapshotted
 * branch_id, `branch_id NOT NULL` (merchant-level rows are never exported), plus the
 * validated date/domain/severity filters. Every value passes through
 * {@see AuditValueMasker}; internal ids, hashes, ip, and raw context never appear.
 * Rows are streamed in bounded chunks so a large branch trail cannot exhaust memory.
 */
class AuditExportCsvBuilder
{
    private const CHUNK = 500;

    private const HEADERS = ['id', 'action', 'severity', 'actor', 'branch', 'subject_type', 'correlation_id', 'created_at', 'context'];

    /**
     * @return array{0: string, 1: int} [csv, rowCount]
     */
    public function build(AuditExport $export): array
    {
        $masker = app(AuditValueMasker::class);
        $scope = $export->scope_json;

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open export buffer.');
        }
        fputcsv($handle, self::HEADERS);

        $rowCount = 0;

        $this->scopedQuery($export, $scope)
            ->with('branch')
            ->orderBy('created_at')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use ($handle, $masker, &$rowCount): void {
                foreach ($rows as $row) {
                    /** @var AuditLog $row */
                    $branch = $row->branch;
                    fputcsv($handle, [
                        $row->ulid,
                        $row->action,
                        $row->severity->value,
                        $row->actor_label !== null ? AuditValueMasker::maskEmail($row->actor_label) : '',
                        $branch instanceof MerchantBranch ? $branch->ulid : '',
                        $row->auditable_type !== null ? class_basename($row->auditable_type) : '',
                        $row->correlation_id ?? '',
                        $row->created_at?->toIso8601String() ?? '',
                        json_encode($masker->mask($row->context ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]);
                    $rowCount++;
                }
            });

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return [$csv, $rowCount];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return Builder<AuditLog>
     */
    private function scopedQuery(AuditExport $export, array $scope): Builder
    {
        $query = AuditLog::query()
            ->where('merchant_id', $export->merchant_id)
            ->where('branch_id', $export->branch_id)   // branch-null rows never exported
            ->whereNotNull('branch_id');

        // Domain segment filter (default: general only, matching audit.branch_events.view).
        $domains = is_array($scope['domains'] ?? null) && $scope['domains'] !== []
            ? $scope['domains']
            : [AuditDomain::General->value];

        $actions = [];
        foreach ($domains as $domain) {
            $enum = AuditDomain::tryFrom((string) $domain);
            if ($enum !== null) {
                $actions = array_merge($actions, AuditEvent::actionsIn($enum));
            }
        }
        $query->whereIn('action', $actions === [] ? ['__none__'] : $actions);

        if (is_array($scope['severities'] ?? null) && $scope['severities'] !== []) {
            $query->whereIn('severity', $scope['severities']);
        }

        if (isset($scope['date_from'])) {
            $query->where('created_at', '>=', $scope['date_from']);
        }
        if (isset($scope['date_to'])) {
            $query->where('created_at', '<=', $scope['date_to']);
        }

        return $query;
    }
}
