<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

/**
 * Group validation decision (Plan §42; Phase 18B). Mirrors the
 * payment_validation_events.decision DB CHECK. A whole-group decision only (Gate B);
 * there is no partial group validation.
 */
enum PaymentValidationDecision: string
{
    case Validated = 'validated';
    case Rejected = 'rejected';
    case CorrectionRequired = 'correction_required';
}
