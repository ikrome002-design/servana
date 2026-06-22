<?php

declare(strict_types=1);

namespace App\Domain\Idempotency;

/**
 * Outcome of a provider-callback dedupe claim (Plan §24.1 provider webhook,
 * §24.4; Phase R4 seam). Phase 20D attaches this to provider-supported
 * correlation ids, the callback inbox, and receipt-number uniqueness.
 */
enum ProviderClaimResult
{
    /** First time this provider/environment/correlation was seen — process it. */
    case First;
    /** Already seen (same payload) or concurrently in-flight — drop/ack as duplicate. */
    case Duplicate;
    /** Same correlation id reused with a DIFFERENT payload — reject/flag. */
    case PayloadMismatch;
}
