<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

use App\Domain\Billing\Models\PlatformSmsBillingRule;

/**
 * The lifecycle state of a platform SMS billing rule (COR-UI08-001 §9; Phase UI-08).
 * Lifecycle: docs/architecture/state-machines/platform-sms-billing-rule.md.
 *
 * DERIVED, NEVER STORED. There is no `status` column, deliberately: a stored status would be a
 * second authority that could disagree with the dates. The state at an instant is a pure function
 * of the row plus the series, computed by {@see PlatformSmsBillingRule::stateAt()}.
 */
enum PlatformSmsBillingRuleState: string
{
    /** Scheduled for a future instant. Withdrawable until it takes effect. */
    case Pending = 'pending';

    /** The rule that applies right now: the greatest uncancelled `effective_from <= T`. */
    case Effective = 'effective';

    /** Was effective; a later uncancelled rule has since taken over. Permanent history. */
    case Superseded = 'superseded';

    /** Withdrawn before it ever took effect. Terminal. */
    case Cancelled = 'cancelled';

    /** @return list<string> the exact vocabulary, for contract assertions */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
