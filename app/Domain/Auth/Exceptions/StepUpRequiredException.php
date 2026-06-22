<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

/**
 * A designated sensitive action was attempted without a *fresh* MFA assertion
 * (Plan §18, §9.4 step 13). The SPA re-challenges, then retries the action.
 */
final class StepUpRequiredException extends MfaException
{
    public function __construct()
    {
        parent::__construct('This action requires recent multi-factor verification. Please re-verify and try again.');
    }

    public function errorCode(): string
    {
        return 'step_up_required';
    }

    public function statusCode(): int
    {
        return 403;
    }
}
