<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Invoicing\Contracts\PreferredPersonnelFeeResolver;
use App\Domain\Invoicing\ValueObjects\PreferredPersonnelFeeResolution;
use App\Domain\Scheduling\Models\ServiceSession;

/**
 * Phase 17 preferred-personnel-fee resolver (Gate D). The `preferred_personnel_fee
 * _rules` table does not exist until Phase 20A, so the effective fee is the legacy
 * fixed `services.preferred_personnel_fee_minor`:
 *
 *   1. session.preferred_personnel_honored === null  → not requested → NO fee;
 *   2. session.preferred_personnel_honored === false → overridden    → NO fee;
 *   3. session.preferred_personnel_honored === true  → honoured       → legacy
 *      fixed service fee (which may itself be null = no configured fee).
 *
 * The resolved amount is snapshotted onto the invoice item/header and is permanent
 * — later changes to `services.preferred_personnel_fee_minor` never recalculate an
 * issued invoice. The fee is never taken from the commission preview.
 */
final class LegacyPreferredPersonnelFeeResolver implements PreferredPersonnelFeeResolver
{
    public function resolve(ServiceSession $session, Service $service): PreferredPersonnelFeeResolution
    {
        if ($session->preferred_personnel_honored === null) {
            return PreferredPersonnelFeeResolution::notRequested();
        }

        if ($session->preferred_personnel_honored === false) {
            return PreferredPersonnelFeeResolution::notHonoured();
        }

        return PreferredPersonnelFeeResolution::legacyServiceFixed($service->preferred_personnel_fee_minor);
    }
}
