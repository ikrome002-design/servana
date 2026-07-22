<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\EarningsQueryStatus;
use App\Domain\Compensation\Enums\EarningsQuerySubjectType;
use App\Domain\Compensation\Enums\EarningsQueryType;
use App\Domain\Compensation\Exceptions\CompensationScopeException;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\EarningsQuery;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Hr\Models\StaffProfile;
use Illuminate\Support\Facades\DB;

/**
 * Personnel raises an OWN-SCOPE earnings query (Plan §63; §H12). The subject is validated to belong to
 * the acting staff profile — a foreign or non-existent subject renders 404 (no existence leak). The
 * query type sets the triage `assigned_role`; the authoritative resolution permission stays
 * `earnings_query.respond` (Finance) at the API. Status starts `open`. Server owns status/assignment/
 * tenant fields — the caller supplies only subject + type + message. Audits `earnings_query.created`.
 */
final class CreateEarningsQuery
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(
        StaffProfile $staff,
        EarningsQuerySubjectType $subjectType,
        string $subjectUlid,
        EarningsQueryType $queryType,
        string $body,
    ): EarningsQuery {
        return DB::transaction(function () use ($staff, $subjectType, $subjectUlid, $queryType, $body): EarningsQuery {
            [$subjectId, $branchId] = $this->resolveSubject($staff, $subjectType, $subjectUlid);

            $query = EarningsQuery::create([
                'merchant_id' => $staff->merchant_id,
                'branch_id' => $branchId,
                'staff_profile_id' => $staff->id,
                'subject_type' => $subjectType->value,
                'subject_id' => $subjectId,
                'query_type' => $queryType->value,
                'body' => $body,
                'status' => EarningsQueryStatus::Open->value,
                'assigned_role' => $queryType->routedRole()->value,
            ]);

            $this->audit->record(
                AuditEvent::EarningsQueryCreated,
                $staff->merchantUser?->user,
                $staff->merchant_id,
                $branchId,
                $query,
                [
                    'earnings_query_id' => $query->ulid,
                    'subject_type' => $subjectType->value,
                    'query_type' => $queryType->value,
                    'assigned_role' => $query->assigned_role?->value,
                ],
            );

            return $query;
        });
    }

    /**
     * Resolve the subject ULID to an own-scope id + its branch. Foreign/missing → 404.
     *
     * @return array{0: int, 1: int}
     */
    private function resolveSubject(StaffProfile $staff, EarningsQuerySubjectType $subjectType, string $subjectUlid): array
    {
        $model = match ($subjectType) {
            EarningsQuerySubjectType::CommissionLedger => CommissionLedgerEntry::query()
                ->where('ulid', $subjectUlid)->where('staff_profile_id', $staff->id)->first(),
            EarningsQuerySubjectType::SalaryLedger => SalaryLedgerEntry::query()
                ->where('ulid', $subjectUlid)->where('staff_profile_id', $staff->id)->first(),
            EarningsQuerySubjectType::PayoutItem => PersonnelPayoutItem::query()
                ->where('ulid', $subjectUlid)->where('staff_profile_id', $staff->id)->first(),
        };

        if ($model === null) {
            throw CompensationScopeException::earningsQuerySubject();
        }

        return [(int) $model->id, (int) $model->branch_id];
    }
}
