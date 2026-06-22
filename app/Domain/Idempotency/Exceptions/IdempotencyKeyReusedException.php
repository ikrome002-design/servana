<?php

declare(strict_types=1);

namespace App\Domain\Idempotency\Exceptions;

/**
 * The same `Idempotency-Key` was reused with a materially different request
 * (Plan §24.3, §24.4 step 4) → 409.
 */
final class IdempotencyKeyReusedException extends IdempotencyException
{
    public function __construct()
    {
        parent::__construct('This Idempotency-Key was already used for a different request.');
    }

    public function errorCode(): string
    {
        return 'idempotency_key_reused_with_different_request';
    }

    public function statusCode(): int
    {
        return 409;
    }
}
