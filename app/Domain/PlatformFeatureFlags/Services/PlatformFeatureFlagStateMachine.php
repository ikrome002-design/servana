<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Services;

use App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagState;
use App\Domain\PlatformFeatureFlags\Exceptions\PlatformFeatureFlagException;

/**
 * Legal flag transitions (COR-UI08-001 section 12; Phase UI-08). Lifecycle:
 * docs/architecture/state-machines/platform-feature-flag.md.
 *
 * `retired` is terminal. Every transition except the clock and the emergency pause requires an
 * APPROVED change request, so there is no unaudited instant-enable path anywhere.
 */
final class PlatformFeatureFlagStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'inactive' => ['scheduled', 'active', 'retired'],
        'scheduled' => ['active', 'paused', 'inactive', 'retired'],
        'active' => ['paused', 'inactive', 'retired'],
        'paused' => ['active', 'inactive', 'retired'],
        'retired' => [],
    ];

    public function assertCanTransition(PlatformFeatureFlagState $from, PlatformFeatureFlagState $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw PlatformFeatureFlagException::invalidTransition($from, $to);
        }
    }

    public function canTransition(PlatformFeatureFlagState $from, PlatformFeatureFlagState $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value], true);
    }

    /**
     * Pause is the ONE single-actor path, permitted only because it moves a flag towards deny and
     * never away from it. Turning a flag back on always goes through maker/checker.
     */
    public function canPause(PlatformFeatureFlagState $from): bool
    {
        return $this->canTransition($from, PlatformFeatureFlagState::Paused);
    }

    /** @return list<string> the exact transition map, for contract assertions */
    public function allowedFrom(PlatformFeatureFlagState $from): array
    {
        return self::TRANSITIONS[$from->value];
    }
}
