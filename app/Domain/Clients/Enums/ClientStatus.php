<?php

declare(strict_types=1);

namespace App\Domain\Clients\Enums;

/**
 * Client record lifecycle (Plan §13.7, §35). Mirrors the clients.status DB CHECK.
 *
 * `active`/`archived`; there is NO hard delete. Phase 15A does not expose an
 * archive mutation route (no authoritative permission/workflow yet) — clients
 * are created `active`.
 */
enum ClientStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
