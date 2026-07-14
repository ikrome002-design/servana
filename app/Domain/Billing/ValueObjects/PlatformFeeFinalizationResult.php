<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Enums\PlatformFeeBasisType;
use App\Domain\Billing\Services\RecordPlatformFeeAtFinalization;

/**
 * Immutable outcome of {@see RecordPlatformFeeAtFinalization} (Plan §51;
 * Phase 20E). Either inactive (fixed-only — an explicit no-op, not nullable ambiguity) or active with the
 * full finalization snapshot. `clientShiftedDeltaMinor` is the amount the caller adds to the
 * merchant-client invoice total; the merchant liability is always the full gross fee.
 */
final readonly class PlatformFeeFinalizationResult
{
    /**
     * @param  int|null  $configurationId  internal id of the effective configuration (persisted as FK)
     */
    private function __construct(
        public bool $isActive,
        public ?int $configurationId = null,
        public ?BillingMode $billingMode = null,
        public ?CanonicalPlatformFeeTier $tier = null,
        public ?PlatformFeeBasisType $basisType = null,
        public ?int $basisAmountMinor = null,
        public ?int $rateBasisPoints = null,
        public ?int $sharedSplitBasisPoints = null,
        public ?int $grossMinor = null,
        public ?int $clientShiftedMinor = null,
        public ?int $merchantAbsorbedMinor = null,
        public ?int $merchantLiabilityMinor = null,
        public ?string $currency = null,
        public ?string $resolvedAtIso = null,
    ) {}

    public static function inactive(): self
    {
        return new self(isActive: false);
    }

    public static function active(
        int $configurationId,
        BillingMode $billingMode,
        CanonicalPlatformFeeTier $tier,
        PlatformFeeBasisType $basisType,
        int $basisAmountMinor,
        int $rateBasisPoints,
        ?int $sharedSplitBasisPoints,
        CalculatedPlatformFee $fee,
        string $resolvedAtIso,
    ): self {
        return new self(
            isActive: true,
            configurationId: $configurationId,
            billingMode: $billingMode,
            tier: $tier,
            basisType: $basisType,
            basisAmountMinor: $basisAmountMinor,
            rateBasisPoints: $rateBasisPoints,
            sharedSplitBasisPoints: $sharedSplitBasisPoints,
            grossMinor: $fee->grossMinor,
            clientShiftedMinor: $fee->clientShiftedMinor,
            merchantAbsorbedMinor: $fee->merchantAbsorbedMinor,
            merchantLiabilityMinor: $fee->merchantLiabilityMinor,
            currency: $fee->currency,
            resolvedAtIso: $resolvedAtIso,
        );
    }

    /** The amount added to the merchant-client invoice total (0 unless the tier shifts to the client). */
    public function clientShiftedDeltaMinor(): int
    {
        return $this->clientShiftedMinor ?? 0;
    }

    /**
     * The `invoices` header snapshot columns (all null when inactive — preserves the pre-Phase-20E
     * fixed-only behaviour and satisfies the snapshot-coherence CHECK).
     *
     * @return array<string, int|string|null>
     */
    public function headerSnapshot(): array
    {
        if (! $this->isActive) {
            return [
                'platform_fee_configuration_id' => null,
                'platform_fee_billing_mode_snapshot' => null,
                'platform_fee_rate_bps_snapshot' => null,
                'platform_fee_tier_snapshot' => null,
                'platform_fee_basis_type_snapshot' => null,
                'platform_fee_shared_split_snapshot' => null,
                'platform_fee_currency' => null,
                'platform_fee_gross_minor' => null,
                'platform_fee_client_shifted_minor' => null,
                'platform_fee_resolved_at' => null,
            ];
        }

        return [
            'platform_fee_configuration_id' => $this->configurationId,
            'platform_fee_billing_mode_snapshot' => $this->billingMode?->value,
            'platform_fee_rate_bps_snapshot' => $this->rateBasisPoints,
            'platform_fee_tier_snapshot' => $this->tier?->value,
            'platform_fee_basis_type_snapshot' => $this->basisType?->value,
            'platform_fee_shared_split_snapshot' => $this->sharedSplitBasisPoints,
            'platform_fee_currency' => $this->currency,
            'platform_fee_gross_minor' => $this->grossMinor,
            'platform_fee_client_shifted_minor' => $this->clientShiftedMinor,
            'platform_fee_resolved_at' => $this->resolvedAtIso,
        ];
    }

    /**
     * Safe audit context (no client PII).
     *
     * @return array<string, int|string|bool|null>
     */
    public function auditContext(): array
    {
        return [
            'platform_fee_active' => $this->isActive,
            'platform_fee_tier' => $this->tier?->value,
            'platform_fee_basis_type' => $this->basisType?->value,
            'platform_fee_rate_bps' => $this->rateBasisPoints,
            'platform_fee_gross_minor' => $this->grossMinor,
            'platform_fee_client_shifted_minor' => $this->clientShiftedMinor,
            'platform_fee_currency' => $this->currency,
        ];
    }
}
