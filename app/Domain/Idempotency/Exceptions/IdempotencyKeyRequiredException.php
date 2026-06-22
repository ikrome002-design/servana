<?php

declare(strict_types=1);

namespace App\Domain\Idempotency\Exceptions;

/**
 * A financial mutation was submitted without an `Idempotency-Key` header
 * (Plan §24.4 step 1). Stable validation-style outcome.
 */
final class IdempotencyKeyRequiredException extends IdempotencyException
{
    public function __construct()
    {
        parent::__construct('An Idempotency-Key header is required for this request.');
    }

    public function errorCode(): string
    {
        return 'idempotency_key_required';
    }

    public function statusCode(): int
    {
        return 422;
    }
}
