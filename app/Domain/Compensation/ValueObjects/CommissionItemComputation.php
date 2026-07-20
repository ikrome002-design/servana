<?php

declare(strict_types=1);

namespace App\Domain\Compensation\ValueObjects;

use App\Domain\Compensation\Enums\CommissionCalculationBasis;
use App\Domain\Compensation\Enums\CommissionCalculationType;

/**
 * The resolved, snapshotted commission for one eligible invoice item under one validation event
 * (Plan §61; Phase 20G). Every value is server-derived from immutable facts; the earning action
 * persists it verbatim so a historical amount never depends on later mutable configuration.
 */
final readonly class CommissionItemComputation
{
    public function __construct(
        public int $invoiceItemId,
        public string $invoiceItemUlid,
        public int $invoiceId,
        public ?int $serviceSessionId,
        public int $staffProfileId,
        public string $staffProfileUlid,
        public int $compensationPlanId,
        public string $compensationPlanUlid,
        public int $commissionRuleId,
        public string $commissionRuleUlid,
        public CommissionCalculationType $calculationType,
        public CommissionCalculationBasis $calculationBasis,
        public int $calculationBasisMinor,
        public ?int $rateBasisPoints,
        public ?int $fixedRateMinor,
        public int $eligibleValidatedAllocationMinor,
        public int $amountMinor,
        public string $currency,
    ) {}
}
