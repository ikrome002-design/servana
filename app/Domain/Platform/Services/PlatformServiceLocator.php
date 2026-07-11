<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Catalogue\Models\Service;

/**
 * Platform-scoped service reference lookup (Plan §8.2; Phase 20A). Super-Admin platform governance
 * legitimately references services across every merchant (e.g. a service-scoped preferred-personnel
 * fee rule), so this is one of the few places allowed to cross tenant isolation via
 * `withoutGlobalScopes()`. Read-only ULID↔internal-id translation only — never bulk tenant data.
 */
final class PlatformServiceLocator
{
    public function idForUlid(string $ulid): ?int
    {
        $id = Service::withoutGlobalScopes()->where('ulid', $ulid)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function ulidForId(int $id): ?string
    {
        $ulid = Service::withoutGlobalScopes()->whereKey($id)->value('ulid');

        return $ulid === null ? null : (string) $ulid;
    }
}
