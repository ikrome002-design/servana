<?php

declare(strict_types=1);

namespace App\Domain\Clients\Enums;

/**
 * Consent channel (Plan §13.7, §35). Mirrors client_consents.channel DB CHECK.
 *
 * Only SMS is captured in Phase 15A; no SMS delivery exists yet (Phase 21S).
 */
enum ConsentChannel: string
{
    case Sms = 'sms';
}
