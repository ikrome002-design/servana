<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Models\PlatformBillingSettings;
use Carbon\CarbonImmutable;

/**
 * Resolve the currently-effective platform billing settings (Plan §13.9, §47; Phase 20A/20B).
 * The current version is the greatest `effective_from <= now()`. Platform-owned (no tenant scope).
 * Used to snapshot `default_trial_days` at trial creation (Gate B1) and `grace_days` for escalation.
 */
final class ResolveEffectivePlatformBillingSettings
{
    public function current(?CarbonImmutable $asOf = null): ?PlatformBillingSettings
    {
        $asOf ??= CarbonImmutable::now();

        return PlatformBillingSettings::query()
            ->where('effective_from', '<=', $asOf)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
