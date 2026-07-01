<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

/**
 * Durable duplicate-reference check outcomes (Plan §13.15, §41; Phase 18A). Mirrors
 * the payment_reference_checks.result DB CHECK.
 *
 * {@see Unique} — the first accepted reference; reserves the partial-unique slot.
 * {@see DuplicateSuspected} — a later attempt matching a prior reference; held for
 * Finance review (never silently accepted). {@see OverrideApproved} — a canonical
 * Finance override (permission + step-up + reason) that clears a suspected duplicate.
 */
enum PaymentReferenceCheckResult: string
{
    case Unique = 'unique';
    case DuplicateSuspected = 'duplicate_suspected';
    case OverrideApproved = 'override_approved';
}
