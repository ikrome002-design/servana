<?php

declare(strict_types=1);

namespace App\Domain\Idempotency\Exceptions;

/**
 * The `Idempotency-Key` header was malformed (outside the 16–255 length bound or
 * containing disallowed characters) (Plan §24.4 step 1).
 */
final class InvalidIdempotencyKeyException extends IdempotencyException
{
    public function __construct()
    {
        parent::__construct('The Idempotency-Key header is invalid.');
    }

    public function errorCode(): string
    {
        return 'invalid_idempotency_key';
    }

    public function statusCode(): int
    {
        return 422;
    }
}
