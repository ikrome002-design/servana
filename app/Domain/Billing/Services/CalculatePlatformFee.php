<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Exceptions\PlatformFeeException;
use App\Domain\Billing\ValueObjects\CalculatedPlatformFee;

/**
 * Computes the percentage platform fee for one basis amount (Plan §51; ADR-005; Phase 20E). Server-side,
 * integer arithmetic only — never floating-point money.
 *
 *   gross = round_half_up(basis_minor * rate_basis_points / 10000)
 *
 * Tier split (the merchant liability is ALWAYS the full gross fee):
 *
 *   customer_centric  → shifted 0,                                 absorbed = gross
 *   business_centric  → shifted = gross,                           absorbed = 0
 *   shared            → shifted = round_half_up(gross * split/10000), absorbed = gross - shifted
 *
 * The result is a {@see CalculatedPlatformFee} whose constructor re-asserts the split and liability
 * invariants. This service is pure: it resolves nothing and mutates nothing.
 */
final class CalculatePlatformFee
{
    public function calculate(
        int $basisMinor,
        int $rateBasisPoints,
        CanonicalPlatformFeeTier $tier,
        ?int $sharedSplitBasisPoints,
        string $currency,
    ): CalculatedPlatformFee {
        $gross = $this->roundHalfUp(max(0, $basisMinor), $rateBasisPoints);

        return $this->splitByTier($gross, $tier, $sharedSplitBasisPoints, $currency);
    }

    /**
     * Split an already-computed gross fee across the tier (no rate applied). Used when a proportional
     * earned portion is derived from a finalization snapshot and must be split by the snapshotted tier.
     */
    public function splitByTier(int $grossMinor, CanonicalPlatformFeeTier $tier, ?int $sharedSplitBasisPoints, string $currency): CalculatedPlatformFee
    {
        $gross = max(0, $grossMinor);

        [$shifted, $absorbed] = match ($tier) {
            CanonicalPlatformFeeTier::CustomerCentric => [0, $gross],
            CanonicalPlatformFeeTier::BusinessCentric => [$gross, 0],
            CanonicalPlatformFeeTier::Shared => $this->sharedSplit($gross, $sharedSplitBasisPoints),
        };

        return new CalculatedPlatformFee(
            grossMinor: $gross,
            clientShiftedMinor: $shifted,
            merchantAbsorbedMinor: $absorbed,
            merchantLiabilityMinor: $gross,
            tier: $tier,
            currency: strtoupper($currency),
        );
    }

    /**
     * @return array{0:int,1:int} [clientShifted, merchantAbsorbed]
     */
    private function sharedSplit(int $gross, ?int $sharedSplitBasisPoints): array
    {
        if ($sharedSplitBasisPoints === null) {
            throw PlatformFeeException::sharedSplitMissing();
        }

        $shifted = $this->roundHalfUp($gross, $sharedSplitBasisPoints);

        // The residual lands on the merchant, so shifted + absorbed == gross exactly.
        return [$shifted, $gross - $shifted];
    }

    /** Round-half-up of basis * basisPoints / 10000 to integer minor units (ADR-005; basis >= 0). */
    private function roundHalfUp(int $basisMinor, int $basisPoints): int
    {
        return intdiv($basisMinor * $basisPoints + 5000, 10000);
    }
}
