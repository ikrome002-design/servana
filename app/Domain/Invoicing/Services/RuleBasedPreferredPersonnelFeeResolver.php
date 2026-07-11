<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Billing\Enums\PreferredFeeCalculationType;
use App\Domain\Billing\Queries\ResolveEffectivePreferredPersonnelFee;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Invoicing\Contracts\PreferredPersonnelFeeResolver;
use App\Domain\Invoicing\ValueObjects\PreferredPersonnelFeeResolution;
use App\Domain\Scheduling\Models\ServiceSession;
use Carbon\CarbonImmutable;

/**
 * Phase 20A preferred-personnel-fee resolver (Gate D seam replacement). Resolves the effective
 * fee from `preferred_personnel_fee_rules` instead of the legacy fixed
 * `services.preferred_personnel_fee_minor`, WITHOUT changing finalization semantics: the fee
 * is charged only when the completed session actually honoured a preferred request, and is
 * never derived from the non-payable commission preview.
 *
 *   1. session.preferred_personnel_honored === null  → not requested → NO fee;
 *   2. session.preferred_personnel_honored === false → overridden    → NO fee;
 *   3. session.preferred_personnel_honored === true  → resolve the effective rule
 *      (service-scoped preferred over platform_default) for the finalization business date;
 *      fixed → amount; percentage → round-half-up on the service-item net basis; none → no fee.
 *
 * The resolved amount is snapshotted at finalization and is permanent — a later rule change
 * NEVER recalculates an issued invoice. Replaces {@see LegacyPreferredPersonnelFeeResolver}.
 */
final class RuleBasedPreferredPersonnelFeeResolver implements PreferredPersonnelFeeResolver
{
    public function __construct(
        private readonly ResolveEffectivePreferredPersonnelFee $resolveEffective,
    ) {}

    public function resolve(ServiceSession $session, Service $service): PreferredPersonnelFeeResolution
    {
        if ($session->preferred_personnel_honored === null) {
            return PreferredPersonnelFeeResolution::notRequested();
        }

        if ($session->preferred_personnel_honored === false) {
            return PreferredPersonnelFeeResolution::notHonoured();
        }

        // Net service-item basis: quantity is 1 with no per-item tax/discount at the current
        // invoice-item model, so net == gross == the service price.
        $basisMinor = (int) $service->price_minor;

        $resolved = $this->resolveEffective->resolve(
            (int) $service->id,
            CarbonImmutable::now('Africa/Nairobi'),
            $basisMinor,
        );

        return match ($resolved->type) {
            PreferredFeeCalculationType::FixedAmount => PreferredPersonnelFeeResolution::ruleFixed((int) $resolved->amountMinor),
            PreferredFeeCalculationType::Percentage => PreferredPersonnelFeeResolution::rulePercentage((int) $resolved->amountMinor),
            null => PreferredPersonnelFeeResolution::ruleNone(),
        };
    }
}
