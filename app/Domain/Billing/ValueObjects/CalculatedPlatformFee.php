<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Services\CalculatePlatformFee;
use InvalidArgumentException;

/**
 * Immutable result of {@see CalculatePlatformFee} (Plan §51; ADR-005;
 * Phase 20E). Integer minor units only. Invariants (asserted at construction):
 *
 *   client_shifted + merchant_absorbed = gross
 *   merchant_liability = gross              (the merchant always owes the full fee, regardless of tier)
 *   all amounts >= 0
 *
 * `client_shifted` is the portion the tier adds to the merchant-client invoice price (collected by the
 * merchant off-platform); it never means Servana collected client funds.
 */
final readonly class CalculatedPlatformFee
{
    public function __construct(
        public int $grossMinor,
        public int $clientShiftedMinor,
        public int $merchantAbsorbedMinor,
        public int $merchantLiabilityMinor,
        public CanonicalPlatformFeeTier $tier,
        public string $currency,
    ) {
        if ($grossMinor < 0 || $clientShiftedMinor < 0 || $merchantAbsorbedMinor < 0) {
            throw new InvalidArgumentException('Platform-fee amounts must be non-negative.');
        }

        if ($clientShiftedMinor + $merchantAbsorbedMinor !== $grossMinor) {
            throw new InvalidArgumentException('Platform-fee split must sum to the gross fee.');
        }

        if ($merchantLiabilityMinor !== $grossMinor) {
            throw new InvalidArgumentException('Merchant liability must equal the full gross platform fee.');
        }
    }

    /** True when the tier shifts a fee amount onto the merchant-client invoice presentation. */
    public function shiftsToClient(): bool
    {
        return $this->clientShiftedMinor > 0;
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'gross_platform_fee_minor' => $this->grossMinor,
            'client_shifted_amount_minor' => $this->clientShiftedMinor,
            'merchant_absorbed_amount_minor' => $this->merchantAbsorbedMinor,
            'merchant_liability_minor' => $this->merchantLiabilityMinor,
            'service_fee_tier_snapshot' => $this->tier->value,
            'currency' => $this->currency,
        ];
    }
}
