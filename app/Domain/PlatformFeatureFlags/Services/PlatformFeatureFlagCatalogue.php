<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Services;

use App\Domain\PlatformFeatureFlags\Support\PlatformFeatureFlagDefinition;

/**
 * The code-reviewed flag catalogue (COR-UI08-001 §12.1; Phase UI-08).
 *
 * THE CATALOGUE IS CODE, NOT DATA. It is read from `config/platform-feature-flags.php`, so the API
 * can only ever act on a key that already exists there — an operator cannot mint a flag at runtime
 * and no flag can exist that was never reviewed. **An unknown key fails closed**, everywhere:
 * `definition()` returns null and every caller treats that as deny.
 *
 * AN EMPTY CATALOGUE IS A TRUTHFUL STATE, and this class says so plainly rather than papering over
 * it. Servana ships with no platform feature flag defined because none has been authorized; the
 * page renders an honest empty state. Seeding a fabricated flag to populate a screen would be
 * exactly the production mock data the UI/UX plan §15.2 forbids.
 */
final class PlatformFeatureFlagCatalogue
{
    /** @return array<string, PlatformFeatureFlagDefinition> keyed by flag key */
    public function all(): array
    {
        /** @var array<string, array<string, mixed>> $configured */
        $configured = config('platform-feature-flags.flags', []);

        $definitions = [];

        foreach ($configured as $key => $definition) {
            $definitions[(string) $key] = PlatformFeatureFlagDefinition::fromArray((string) $key, $definition);
        }

        ksort($definitions);

        return $definitions;
    }

    /** Null for any key the code allowlist does not contain — the first fail-closed gate. */
    public function definition(string $flagKey): ?PlatformFeatureFlagDefinition
    {
        return $this->all()[$flagKey] ?? null;
    }

    public function has(string $flagKey): bool
    {
        return $this->definition($flagKey) !== null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** True when no flag has been authorized — a truthful state, not an error. */
    public function isEmpty(): bool
    {
        return $this->all() === [];
    }
}
