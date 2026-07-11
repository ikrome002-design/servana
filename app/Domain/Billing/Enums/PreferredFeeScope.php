<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Preferred-personnel fee rule scope (Plan §13.10; Phase 20A). Mirrors the DB CHECK on
 * `preferred_personnel_fee_rules.scope`. `platform_default` requires `service_id` null;
 * `service` requires `service_id` — the DB scope CHECK is authoritative. Resolution
 * prefers a `service` rule over the `platform_default`.
 */
enum PreferredFeeScope: string
{
    case PlatformDefault = 'platform_default';
    case Service = 'service';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
