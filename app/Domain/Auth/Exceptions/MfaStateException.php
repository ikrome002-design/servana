<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

/**
 * An MFA flow step was attempted from an incompatible state — e.g. confirming
 * with no pending enrollment, starting enrollment while already enrolled, or
 * challenging with no confirmed credential (Plan §18). Uniform 422.
 */
final class MfaStateException extends MfaException
{
    public static function alreadyEnrolled(): self
    {
        return new self('Multi-factor authentication is already set up for this account.');
    }

    public static function noPendingEnrollment(): self
    {
        return new self('There is no pending multi-factor setup to confirm. Start setup first.');
    }

    public static function notEnrolled(): self
    {
        return new self('Multi-factor authentication is not set up for this account.');
    }

    public function errorCode(): string
    {
        return 'mfa_invalid_state';
    }

    public function statusCode(): int
    {
        return 422;
    }
}
