<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;

/**
 * Commission-rule status-machine guard (Plan §59, §80; Scope §12.7, §18.3; Phase 20F). The single
 * place that authorizes a `commission_rules.status` transition; the inventory lives on
 * {@see CommissionRuleStatus::allowedTransitions()}. An unlisted transition raises
 * {@see CompensationStateException} → `422 invalid_state_transition`.
 *
 * A rule has NO independent lifecycle action: every non-draft transition is driven by the
 * referencing compensation plan's action, inside that plan's transaction (see the linkage table in
 * docs/architecture/state-machines/commission-rule.md). There is no DELETE — a previously active
 * rule is ENDED (`active → superseded`), never deleted (Scope §12.7 Step 3C).
 */
final class CommissionRuleStateMachine
{
    public function canTransition(CommissionRuleStatus $from, CommissionRuleStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws CompensationStateException
     */
    public function ensure(CommissionRuleStatus $from, CommissionRuleStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw CompensationStateException::invalidTransition('commission rule', $from->value, $to->value);
        }
    }
}
