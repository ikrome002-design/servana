<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

/**
 * Per-item share of the invoice-level platform fee (Plan §51; ADR-005; Phase 20E). Integer minor units;
 * `clientShiftedMinor + absorbedMinor == grossMinor` for the item.
 */
final readonly class AllocatedPlatformFeeItem
{
    public function __construct(
        public string $key,
        public int $grossMinor,
        public int $clientShiftedMinor,
        public int $absorbedMinor,
    ) {}
}
