<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Enums;

/**
 * The CLOSED set of things a rollout may be scoped to (COR-UI08-001 §12; Phase UI-08).
 *
 * Closed on purpose: together with a scalar `target_value`, it means there is nowhere to persist an
 * executable predicate, so no stored targeting value can ever be evaluated as code.
 */
enum PlatformFeatureFlagTargetType: string
{
    case Merchant = 'merchant';
    case Plan = 'plan';
    case Cohort = 'cohort';

    /** @return list<string> the exact vocabulary, for schema-contract assertions */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
