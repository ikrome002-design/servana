<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Subject a personnel earnings query is raised against (Plan §63; Phase 20H). Mirrors the
 * earnings_queries.subject_type DB CHECK; parity guarded by Phase20HEnumParityTest. The subject is
 * always one of the querying personnel's own facts (validated in-scope by the create action —
 * arbitrary ids are rejected).
 */
enum EarningsQuerySubjectType: string
{
    case CommissionLedger = 'commission_ledger';
    case SalaryLedger = 'salary_ledger';
    case PayoutItem = 'payout_item';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
