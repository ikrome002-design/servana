<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

/**
 * A mandatory-MFA user with a confirmed credential reached a privileged route
 * without asserting MFA in this session (Plan §18). The SPA routes them to the
 * challenge screen.
 */
final class MfaChallengeRequiredException extends MfaException
{
    public function __construct()
    {
        parent::__construct('Multi-factor authentication is required for this session.');
    }

    public function errorCode(): string
    {
        return 'mfa_challenge_required';
    }

    public function statusCode(): int
    {
        return 403;
    }
}
