<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

/**
 * A mandatory-MFA user reached a privileged route without a confirmed
 * credential (Plan §18). The SPA routes them to enrollment.
 */
final class MfaEnrollmentRequiredException extends MfaException
{
    public function __construct()
    {
        parent::__construct('Multi-factor authentication setup is required before continuing.');
    }

    public function errorCode(): string
    {
        return 'mfa_enrollment_required';
    }

    public function statusCode(): int
    {
        return 403;
    }
}
