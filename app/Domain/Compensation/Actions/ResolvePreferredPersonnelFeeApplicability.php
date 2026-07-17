<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Compensation\Models\CommissionRule;

/**
 * Resolve whether the Phase 20A preferred-personnel fee is included in the FUTURE commission basis
 * (Plan §59; Scope §969; Phase 20F, F6).
 *
 * ```text
 * true  → the preferred-personnel fee IS INCLUDED in the future commission basis
 * false → it is EXCLUDED (the default)
 * ```
 *
 * It is a **basis-inclusion flag only** — not a separate commission basis, not a rate modifier,
 * not a payout trigger, not an earned-commission row. A `salary_only` plan resolves no rule, so
 * applicability is `false` (nothing to include).
 *
 * **Configuration only.** Phase 20G consumes the flag when earning commission against the Phase 20A
 * `preferred_personnel_fee_rules` substrate; Phase 20F never applies it to money.
 */
final class ResolvePreferredPersonnelFeeApplicability
{
    public function handle(?CommissionRule $rule): bool
    {
        // No rule (a salary_only plan) ⇒ nothing to include in a basis that will never exist.
        if (! $rule instanceof CommissionRule) {
            return false;
        }

        return $rule->applies_to_preferred_personnel_fee;
    }
}
