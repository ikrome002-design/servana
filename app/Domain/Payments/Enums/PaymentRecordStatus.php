<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

/**
 * Component payment_records lifecycle states (Plan §13.8, §41; Phase 18A). Mirrors
 * the payment_records.status DB CHECK.
 *
 * Phase 18A always creates a component at {@see PendingValidation} (the duplicate
 * hold is a GROUP-level state). {@see Validated}/{@see Rejected}/
 * {@see CorrectionRequired}/{@see Reversed}/{@see Adjusted} are Phase-18B-driven.
 */
enum PaymentRecordStatus: string
{
    case PendingValidation = 'pending_validation';
    case Validated = 'validated';
    case Rejected = 'rejected';
    case CorrectionRequired = 'correction_required';
    case Reversed = 'reversed';
    case Adjusted = 'adjusted';
}
