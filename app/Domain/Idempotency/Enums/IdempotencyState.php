<?php

declare(strict_types=1);

namespace App\Domain\Idempotency\Enums;

/**
 * Lifecycle of an idempotency_keys row (Plan §24.4; Phase R4).
 *
 *  - Processing: a claim holds the lock and the domain action is executing.
 *  - Completed:  a definitive response is stored and replayable (2xx or a stable,
 *                deterministic 4xx).
 *  - Failed:     a server-side failure; only a redacted error code is stored, and
 *                the key is retryable (a later same-request claim re-executes).
 *
 * Mirrors the `idempotency_keys.state` DB CHECK.
 */
enum IdempotencyState: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
