<?php

declare(strict_types=1);

namespace App\Domain\Clients\Enums;

/**
 * SMS consent state (Plan §13.7, §35). Mirrors client_consents.state DB CHECK.
 */
enum ConsentState: string
{
    case OptedIn = 'opted_in';
    case OptedOut = 'opted_out';
}
