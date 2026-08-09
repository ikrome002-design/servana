<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Services;

use App\Domain\PlatformAccess\Enums\PlatformAccessStatus;
use App\Domain\PlatformAccess\Exceptions\PlatformAccessException;

/**
 * Legal transitions for a platform-access membership (COR-UI08-001 §11; Phase UI-08). Lifecycle:
 * docs/architecture/state-machines/platform-access-membership.md.
 *
 * The application guard; `platform_access_memberships_status_check` plus the four
 * timestamp-consistency CHECKs are the database backstop. An invalid transition raises
 * `422 invalid_state_transition` rather than writing a state nobody can explain.
 *
 * `deactivated` is TERMINAL. Re-admitting a person requires a new invitation, which produces a new
 * audit trail instead of silently resurrecting an old grant.
 */
final class PlatformAccessStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'invited' => ['active', 'deactivated'],
        'active' => ['suspended', 'deactivated'],
        'suspended' => ['active', 'deactivated'],
        'deactivated' => [],
    ];

    public function assertCanTransition(PlatformAccessStatus $from, PlatformAccessStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw PlatformAccessException::invalidTransition($from, $to);
        }
    }

    public function canTransition(PlatformAccessStatus $from, PlatformAccessStatus $to): bool
    {
        // The map is total over PlatformAccessStatus, so there is no missing-key case to guard.
        return in_array($to->value, self::TRANSITIONS[$from->value], true);
    }

    /** @return list<string> the exact transition map, for contract assertions */
    public function allowedFrom(PlatformAccessStatus $from): array
    {
        return self::TRANSITIONS[$from->value];
    }
}
