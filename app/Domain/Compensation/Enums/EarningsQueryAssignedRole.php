<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Routing role a personnel earnings query is assigned to for triage (Plan §63; Phase 20H). Mirrors
 * the earnings_queries.assigned_role DB CHECK; parity guarded by Phase20HEnumParityTest. This is the
 * triage owner only — the authoritative resolution permission is always `earnings_query.respond`
 * (Finance) per the permission matrix (D-H12-1).
 */
enum EarningsQueryAssignedRole: string
{
    case Finance = 'finance';
    case Hr = 'hr';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
