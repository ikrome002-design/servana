<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Enums;

/**
 * Platform feature-flag rollout state (COR-UI08-001 §12; Phase UI-08). Lifecycle:
 * docs/architecture/state-machines/platform-feature-flag.md. Mirrors the
 * `platform_feature_flags.state` CHECK exactly.
 */
enum PlatformFeatureFlagState: string
{
    /** Known to the catalogue, never switched on in this environment. */
    case Inactive = 'inactive';

    /** An applied change set a FUTURE effective_from. The flag is not on yet. */
    case Scheduled = 'scheduled';

    /** Within its effective window, subject to targets and rollout. */
    case Active = 'active';

    /** Emergency stop. Evaluates exactly like inactive — deny — while preserving the configuration. */
    case Paused = 'paused';

    /** Permanently withdrawn. Terminal; evaluates deny forever. */
    case Retired = 'retired';

    /** Whether this state can ever yield an allow, before dates, targets and rollout are considered. */
    public function canAllow(): bool
    {
        return $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return $this === self::Retired;
    }

    /** @return list<string> the exact vocabulary, for schema-contract assertions */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
