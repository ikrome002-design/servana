<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Models\PlatformSmsBillingRule;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Resolve the SMS pricing rule in force at an instant (COR-UI08-001 §9; Phase UI-08).
 *
 * The rule is resolved at the USAGE EVENT'S effective time and snapshotted once into
 * `sms_billing_entries`. That is what makes a later pricing change incapable of re-pricing a
 * charged row — and `sms_billing_entries_guard` makes it structurally impossible in any case.
 *
 * Cancelled rules are excluded: a rule withdrawn before it took effect never applied to anything.
 */
final class ResolveEffectiveSmsBillingRule
{
    /** The rule in force at `$asOf`, or null when the series does not reach back that far. */
    public function at(?CarbonImmutable $asOf = null): ?PlatformSmsBillingRule
    {
        $asOf ??= CarbonImmutable::now();

        return PlatformSmsBillingRule::query()
            ->live()
            ->where('effective_from', '<=', $asOf)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The rule in force now, failing CLOSED when none exists.
     *
     * A missing rule is a configuration fault, not a licence to bill at zero or to fall back to a
     * value nobody scheduled. Callers on the charging path use this.
     */
    public function requireCurrent(?CarbonImmutable $asOf = null): PlatformSmsBillingRule
    {
        $rule = $this->at($asOf);

        if ($rule === null) {
            throw new RuntimeException(
                'No effective SMS billing rule exists. SMS cannot be priced until a platform '
                .'administrator schedules one (COR-UI08-001 §9).'
            );
        }

        return $rule;
    }

    /** The next scheduled rule after `$asOf`, or null when nothing is queued. */
    public function next(?CarbonImmutable $asOf = null): ?PlatformSmsBillingRule
    {
        $asOf ??= CarbonImmutable::now();

        return PlatformSmsBillingRule::query()
            ->live()
            ->where('effective_from', '>', $asOf)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->first();
    }
}
