<?php

declare(strict_types=1);

namespace App\Domain\Idempotency;

/**
 * Outcome of an idempotency claim (Plan §24.4 step 4; Phase R4).
 */
enum ClaimResult
{
    /** We own the lock — execute the domain action (first run or retry of a failure). */
    case Claimed;
    /** A completed response (2xx or stable 4xx) is stored — replay it. */
    case Replay;
    /** Same key, different request — 409 conflict. */
    case ConflictDifferent;
    /** Same key+request still processing under an active lock — 409 + Retry-After. */
    case InProgress;
}
