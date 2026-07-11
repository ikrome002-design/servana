<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Enums\PreferredFeeCalculationType;
use App\Domain\Billing\Enums\PreferredFeeScope;
use App\Domain\Billing\Enums\PreferredPersonnelFeeRuleStatus;
use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Billing\ValueObjects\ResolvedPreferredFee;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves the effective preferred-personnel fee rule for a service on a date (Plan §13.10;
 * Phase 20A). Precedence: a `service`-scoped active rule (matching the service) wins over the
 * `platform_default` active rule; the effective rule is the `active` one whose half-open
 * effective range [effective_from, effective_to) contains the date. Percentage rules are
 * computed round-half-up to integer minor units (ADR-005) on the supplied item basis.
 *
 * This query performs no session honoured-gating (an Invoicing concern) and never mutates a
 * finalized invoice; it is a pure, read-only resolver.
 */
final class ResolveEffectivePreferredPersonnelFee
{
    public function resolve(?int $serviceId, CarbonInterface $onDate, int $basisMinor): ResolvedPreferredFee
    {
        $rule = $this->effectiveRule($serviceId, $onDate);

        if ($rule === null) {
            return ResolvedPreferredFee::none();
        }

        return match ($rule->calculation_type) {
            PreferredFeeCalculationType::FixedAmount => ResolvedPreferredFee::fixed((int) $rule->fixed_amount_minor),
            PreferredFeeCalculationType::Percentage => ResolvedPreferredFee::percentage(
                $this->roundHalfUp($basisMinor, (int) $rule->percentage_basis_points),
            ),
        };
    }

    /**
     * The effective rule MODEL (service-scope preferred over platform_default) on a date, or null.
     * Used by the branch read to surface the applicable rule's public-safe terms.
     */
    public function rule(?int $serviceId, CarbonInterface $onDate): ?PreferredPersonnelFeeRule
    {
        return $this->effectiveRule($serviceId, $onDate);
    }

    private function effectiveRule(?int $serviceId, CarbonInterface $onDate): ?PreferredPersonnelFeeRule
    {
        $date = $onDate->toDateString();

        if ($serviceId !== null) {
            $serviceRule = $this->activeRuleQuery($date)
                ->where('scope', PreferredFeeScope::Service->value)
                ->where('service_id', $serviceId)
                ->first();

            if ($serviceRule !== null) {
                return $serviceRule;
            }
        }

        return $this->activeRuleQuery($date)
            ->where('scope', PreferredFeeScope::PlatformDefault->value)
            ->first();
    }

    /**
     * @return Builder<PreferredPersonnelFeeRule>
     */
    private function activeRuleQuery(string $date): Builder
    {
        return PreferredPersonnelFeeRule::query()
            ->where('status', PreferredPersonnelFeeRuleStatus::Active->value)
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $date);
            })
            ->orderByDesc('effective_from');
    }

    /** Round-half-up of basis * basisPoints / 10000 to integer minor units (ADR-005; basis >= 0). */
    private function roundHalfUp(int $basisMinor, int $basisPoints): int
    {
        return intdiv($basisMinor * $basisPoints + 5000, 10000);
    }
}
