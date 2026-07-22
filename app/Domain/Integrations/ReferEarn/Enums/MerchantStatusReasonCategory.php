<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Enums;

/**
 * Reason **category** carried by `merchant.status_changed` (Plan §58B.1; Phase 21R-A).
 *
 * Plan §58B.1 is explicit: the event carries "reason **category** only …  never free-text reasons",
 * and §58B.2 lists free-text reasons among the fields forbidden in any payload. Servana's own
 * `merchants.suspension_reason` free text stays inside Servana; only this bounded category crosses
 * the boundary.
 *
 * Classification is deliberately conservative: anything Servana cannot positively classify from its
 * own bounded vocabulary is `manual`, because leaking an operator's prose is the failure this enum
 * exists to prevent.
 */
enum MerchantStatusReasonCategory: string
{
    case Fraud = 'fraud';
    case Security = 'security';
    case Legal = 'legal';
    case Compliance = 'compliance';
    case Manual = 'manual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
