<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Commission handoff seam kind (Gate C/E; Phase 18B). Mirrors the
 * commission_handoff_events.kind DB CHECK. A durable per-component seam for Phase 20G
 * consumption — NOT a commission ledger; it carries no rate/earned/payable.
 */
enum CommissionHandoffKind: string
{
    case ValidatedAllocation = 'validated_allocation';
    case Reversal = 'reversal';
}
