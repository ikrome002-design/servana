<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Enums;

/**
 * Queue assignment modes (Plan §37; Phase 16B). Mirrors the DB CHECK on
 * `walk_ins.assignment_mode` and `queue_entries.assignment_mode`.
 *
 *   - next_available      — the deterministic NextAvailablePersonnelSelector picks
 *                           the lowest-load eligible+available personnel member.
 *   - manual              — Front Office supplies an explicit target personnel ULID.
 *   - preferred_personnel — a per-client preferred request (needs
 *                           preferred_personnel.select + an explicit preferred ULID;
 *                           never bypasses HR eligibility/availability; may stay
 *                           waiting if the preferred person is unavailable).
 *
 * The Branch Day default mode is only `next_available` or `manual`
 * (preferred_personnel is a per-client request, never a branch-wide default).
 */
enum QueueAssignmentMode: string
{
    case NextAvailable = 'next_available';
    case Manual = 'manual';
    case PreferredPersonnel = 'preferred_personnel';

    /**
     * Modes valid as a Branch Day default (preferred is per-client only).
     *
     * @return list<self>
     */
    public static function defaultableModes(): array
    {
        return [self::NextAvailable, self::Manual];
    }

    public function isDefaultable(): bool
    {
        return in_array($this, self::defaultableModes(), true);
    }
}
