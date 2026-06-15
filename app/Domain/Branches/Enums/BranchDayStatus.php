<?php

declare(strict_types=1);

namespace App\Domain\Branches\Enums;

/** Branch business-day state (Plan §7.2, Scope §3.3). Mirrors the DB CHECK. */
enum BranchDayStatus: string
{
    case NotOpened = 'not_opened';
    case Open = 'open';
    case Paused = 'paused';
    case Closed = 'closed';
    case Reopened = 'reopened';

    /** A day that is open/paused/reopened is "live" and blocks branch closure. */
    public function isLive(): bool
    {
        return $this === self::Open || $this === self::Paused || $this === self::Reopened;
    }
}
