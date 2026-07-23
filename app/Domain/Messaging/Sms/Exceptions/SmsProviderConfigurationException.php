<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Exceptions;

use App\Domain\Messaging\Sms\Clients\FakeSmsProviderClient;
use RuntimeException;

/**
 * The real SMS transport was asked to send without a complete contract (Plan §81 rule 23 "unpinned
 * contract details fail closed"; ADR-015 posture applied to the SMS adapter; Phase 21S).
 *
 * FAIL CLOSED, NEVER GUESS. There is no default base URL, API key, sender id or contract version
 * anywhere in the codebase: an unconfigured environment binds {@see FakeSmsProviderClient},
 * and a PARTLY configured environment that somehow reaches the HTTP client throws this instead of
 * sending an unauthenticated request to a guessed endpoint.
 *
 * The message names the missing configuration key only — never its value.
 */
final class SmsProviderConfigurationException extends RuntimeException
{
    public static function missing(string $configKey): self
    {
        return new self("The SMS provider contract is incomplete: `{$configKey}` is not configured. Refusing to send (REM-SMS-002).");
    }
}
