<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Contracts;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Invoicing\Services\LegacyPreferredPersonnelFeeResolver;
use App\Domain\Invoicing\ValueObjects\PreferredPersonnelFeeResolution;
use App\Domain\Scheduling\Models\ServiceSession;

/**
 * Resolves the preferred-personnel fee to snapshot at invoice finalization
 * (Gate D, Phase 17). The single seam between invoice finalization and the
 * preferred-fee source. Phase 17 binds
 * {@see LegacyPreferredPersonnelFeeResolver}
 * (legacy fixed `services.preferred_personnel_fee_minor`); Phase 20A replaces the
 * binding with the `preferred_personnel_fee_rules`-backed resolver WITHOUT changing
 * finalization semantics. The fee is charged only when the completed session
 * actually honoured a preferred request, and is never derived from the non-payable
 * commission preview.
 */
interface PreferredPersonnelFeeResolver
{
    public function resolve(ServiceSession $session, Service $service): PreferredPersonnelFeeResolution;
}
