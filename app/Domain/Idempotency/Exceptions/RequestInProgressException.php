<?php

declare(strict_types=1);

namespace App\Domain\Idempotency\Exceptions;

/**
 * The same key+request is still executing under an active lock (Plan §24.3,
 * §24.4 step 4) → 409 with a Retry-After hint. No second effect is created.
 */
final class RequestInProgressException extends IdempotencyException
{
    public function __construct(private readonly int $retryAfterSeconds)
    {
        parent::__construct('A request with this Idempotency-Key is already in progress.');
    }

    public function errorCode(): string
    {
        return 'request_in_progress';
    }

    public function statusCode(): int
    {
        return 409;
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return ['retry_after' => $this->retryAfterSeconds];
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return ['Retry-After' => (string) $this->retryAfterSeconds];
    }
}
