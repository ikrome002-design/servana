<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Type of a personnel earnings query (Plan §63; Phase 20H). Mirrors the earnings_queries.query_type
 * DB CHECK; parity guarded by Phase20HEnumParityTest. The type drives the **routing role**
 * ({@see self::routedRole()}) that receives the query for triage; the authoritative resolution
 * permission is always `earnings_query.respond` (Finance) per the permission matrix (D-H12-1).
 */
enum EarningsQueryType: string
{
    case CommissionDisagreement = 'commission_disagreement';
    case SalaryDisagreement = 'salary_disagreement';
    case PayoutMissing = 'payout_missing';
    case PayoutAmount = 'payout_amount';
    case StatementRequest = 'statement_request';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /**
     * Routing role that receives the query for triage. Monetary/ledger disagreements route to
     * Finance; compensation-terms/statement questions route to HR. The respond permission stays
     * Finance regardless (D-H12-1).
     */
    public function routedRole(): EarningsQueryAssignedRole
    {
        return match ($this) {
            self::StatementRequest => EarningsQueryAssignedRole::Hr,
            default => EarningsQueryAssignedRole::Finance,
        };
    }
}
