<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

/**
 * A submitted TOTP or recovery code was invalid, expired, or replayed (Plan
 * §18). The message is uniform so a caller cannot distinguish the cases.
 */
final class InvalidMfaCodeException extends MfaException
{
    public function __construct()
    {
        parent::__construct('That code is invalid or has expired. Please try again.');
    }

    public function errorCode(): string
    {
        return 'mfa_invalid_code';
    }

    public function statusCode(): int
    {
        return 422;
    }
}
