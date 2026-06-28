<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Enums;

/**
 * Service lifecycle (Plan §13.7, §39). Mirrors the services.status DB CHECK.
 *
 * `active → archived` only, via the ArchiveService domain action. Archived
 * services are excluded from active-selection queries.
 */
enum ServiceStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
