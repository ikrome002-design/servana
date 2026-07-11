<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

use App\Domain\Billing\Enums\PreferredFeeCalculationType;

/**
 * Outcome of resolving the effective preferred-personnel fee rule for a service on a date
 * (Plan §13.10; Phase 20A). Immutable. `type` is null when no effective rule applies (no fee).
 * `amountMinor` is the resolved integer minor amount (round-half-up already applied for
 * percentage rules, ADR-005); null when no rule applies.
 */
final readonly class ResolvedPreferredFee
{
    public function __construct(
        public ?int $amountMinor,
        public ?PreferredFeeCalculationType $type,
    ) {}

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function fixed(int $amountMinor): self
    {
        return new self($amountMinor, PreferredFeeCalculationType::FixedAmount);
    }

    public static function percentage(int $amountMinor): self
    {
        return new self($amountMinor, PreferredFeeCalculationType::Percentage);
    }

    public function found(): bool
    {
        return $this->type !== null;
    }
}
