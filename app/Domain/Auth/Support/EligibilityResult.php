<?php

declare(strict_types=1);

namespace App\Domain\Auth\Support;

/**
 * Outcome of LoginEligibilityService (Plan §9.1 / Scope §2.3).
 *
 * `deniedReason` is a machine code for audit only — it is NEVER surfaced to the
 * client (the API response is uniform to prevent account enumeration).
 */
final class EligibilityResult
{
    private function __construct(
        public readonly bool $eligible,
        public readonly ?string $deniedReason = null,
    ) {}

    public static function eligible(): self
    {
        return new self(true);
    }

    public static function denied(string $reason): self
    {
        return new self(false, $reason);
    }
}
