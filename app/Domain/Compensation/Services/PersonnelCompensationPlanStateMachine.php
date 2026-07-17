<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;

/**
 * Compensation-plan status-machine guard (Plan §59, §80; Scope §12.9; Phase 20F). The single place
 * that authorizes a `personnel_compensation_plans.status` transition; the inventory lives on
 * {@see CompensationPlanStatus::allowedTransitions()}. There is NO generic status route and NO
 * generic status action — every transition has a named action and runs through here. An unlisted
 * transition raises {@see CompensationStateException} → `422 invalid_state_transition`.
 *
 * `status` is never assigned directly. See
 * docs/architecture/state-machines/personnel-compensation-plan.md.
 */
final class PersonnelCompensationPlanStateMachine
{
    public function canTransition(CompensationPlanStatus $from, CompensationPlanStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws CompensationStateException
     */
    public function ensure(CompensationPlanStatus $from, CompensationPlanStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw CompensationStateException::invalidTransition('compensation plan', $from->value, $to->value);
        }
    }
}
