<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Enums\CommissionCalculationType;
use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Exceptions\CompensationValidationException;

/**
 * Action-level shape guards for Phase 20F configuration (Plan §59; Phase 20F, F1/F4).
 *
 * The PostgreSQL CHECKs (`..._model_shape_check`, `..._value_shape_check`) remain the
 * AUTHORITATIVE guards — these checks run first only so a caller gets a typed, friendly 422 instead
 * of a constraint violation. They are deliberately the SAME rules, never a weaker subset: if these
 * ever disagree with the database, the database wins and the row is rejected anyway.
 */
final class CompensationShapeValidator
{
    /**
     * F1 model shape: commission_only ⇒ no salary + rule required; salary_only ⇒ salary required +
     * NO rule; salary_plus_commission ⇒ both.
     *
     * @throws CompensationValidationException
     */
    public function ensureCompensationModelShape(
        CompensationModel $model,
        ?int $salaryAmountMinor,
        ?string $salaryCurrency,
        ?string $salaryPeriod,
        ?int $salaryPayoutDay,
        ?int $commissionRuleId,
    ): void {
        $hasSalary = $salaryAmountMinor !== null || $salaryCurrency !== null || $salaryPeriod !== null;

        if ($model->requiresSalary()) {
            if ($salaryAmountMinor === null || $salaryCurrency === null || $salaryPeriod === null) {
                throw CompensationValidationException::compensationModelShape(
                    "A {$model->label()} plan requires a salary amount, currency and period.",
                );
            }

            if ($salaryAmountMinor <= 0) {
                throw CompensationValidationException::compensationModelShape(
                    'A configured salary amount must be greater than zero.',
                );
            }
        } elseif ($hasSalary || $salaryPayoutDay !== null) {
            throw CompensationValidationException::compensationModelShape(
                "A {$model->label()} plan cannot carry salary terms.",
            );
        }

        if ($model->requiresCommissionRule() && $commissionRuleId === null) {
            throw CompensationValidationException::compensationModelShape(
                "A {$model->label()} plan requires a commission rule.",
            );
        }

        if (! $model->requiresCommissionRule() && $commissionRuleId !== null) {
            // Plan §80 named invariant; Scope §12.5 — salary-only never earns commission.
            throw CompensationValidationException::compensationModelShape(
                'A salary only plan cannot reference a commission rule.',
            );
        }
    }

    /**
     * F4 value shape: percentage ⇒ basis points only; fixed ⇒ minor units + currency only.
     * Integer minor units and integer basis points — never float.
     *
     * @throws CompensationValidationException
     */
    public function ensureCommissionRuleShape(
        CommissionCalculationType $type,
        ?int $percentageBasisPoints,
        ?int $fixedAmountMinor,
        ?string $currency,
    ): void {
        if ($type === CommissionCalculationType::Percentage) {
            if ($percentageBasisPoints === null) {
                throw CompensationValidationException::commissionRuleShape(
                    'A percentage commission rule requires a rate in basis points.',
                );
            }

            if ($fixedAmountMinor !== null || $currency !== null) {
                throw CompensationValidationException::commissionRuleShape(
                    'A percentage commission rule cannot carry a fixed amount or currency.',
                );
            }

            if ($percentageBasisPoints < 0 || $percentageBasisPoints > 10000) {
                // F4 structural ceiling: 0..10000 bp (0-100%). No merchant/platform-maximum
                // settings substrate exists in the Plan/repository — see docs/proof/phase-20f.md §F4.
                throw CompensationValidationException::commissionRuleShape(
                    'A commission percentage must be between 0 and 100 percent.',
                );
            }

            return;
        }

        if ($fixedAmountMinor === null || $currency === null) {
            throw CompensationValidationException::commissionRuleShape(
                'A fixed commission rule requires an amount and a currency.',
            );
        }

        if ($percentageBasisPoints !== null) {
            throw CompensationValidationException::commissionRuleShape(
                'A fixed commission rule cannot carry a percentage rate.',
            );
        }

        if ($fixedAmountMinor < 0) {
            throw CompensationValidationException::commissionRuleShape(
                'A fixed commission amount cannot be negative.',
            );
        }
    }
}
