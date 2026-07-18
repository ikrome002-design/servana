<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Compensation\Actions\ResolveEffectiveCommissionRule;
use App\Domain\Compensation\Actions\ResolveEffectiveCompensationPlan;
use App\Domain\Compensation\Enums\CommissionAppliesTo;
use App\Domain\Compensation\Enums\CommissionCalculationBasis;
use App\Domain\Compensation\Enums\CommissionCalculationType;
use App\Domain\Compensation\Exceptions\CompensationLedgerException;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CommissionRuleService;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\ValueObjects\CommissionItemComputation;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Support\LargestRemainderAllocator;

/**
 * Resolves the commission earned for every eligible item of an invoice at a Finance validation
 * (Plan §61; G4/G5; Phase 20G). Pure resolution + integer calculation — it reads immutable facts
 * and writes nothing; the earning action persists the results.
 *
 * The validation event's validated amount is allocated across ALL invoice items by immutable
 * `line_total_minor` weight (largest-remainder, tie-break invoice-item ULID). Each item's share is
 * the eligible validated allocation — it is both the `paid_amount` basis and the per-item CAP (§8.3,
 * G5), so Σ commission ≤ the validated amount. Commission is earned only on items marked
 * `eligible_for_commission` with valid staff attribution, a non-salary_only effective plan, an
 * effective rule, and a matching applicability; a corrupt/ambiguous configuration fails CLOSED via
 * {@see CompensationLedgerException} (never silently ineligible). The effective plan/rule are
 * resolved as-of the invoice's immutable `finalized_at` business date.
 *
 * Basis (excludes the preferred-personnel fee unless the rule includes it):
 *   service_price       = unit_price_minor × quantity
 *   invoice_item_total  = line_total_minor
 *   net_after_discount  = line_total_minor − item share of invoice discount_minor
 *   paid_amount         = the item's validated allocation
 * `applies_to_preferred_personnel_fee` adds `preferred_personnel_fee_minor` exactly once (it is a
 * separate invoice-item column, never already in line_total, so no double count).
 */
final class CommissionEarningResolver
{
    public function __construct(
        private readonly ResolveEffectiveCompensationPlan $resolvePlan,
        private readonly ResolveEffectiveCommissionRule $resolveRule,
    ) {}

    /**
     * @return list<CommissionItemComputation>
     *
     * @throws CompensationLedgerException on a configuration invariant (fail closed, retryable)
     */
    public function resolve(PaymentValidationEvent $event): array
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->whereKey($event->invoice_id)->firstOrFail();

        if ($invoice->merchant_id !== $event->merchant_id || $invoice->branch_id !== $event->branch_id) {
            throw CompensationLedgerException::configurationInvariant('Validation event and invoice tenancy disagree.');
        }

        /** @var list<InvoiceItem> $items */
        $items = InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('ulid')->get()->all();
        if ($items === []) {
            return [];
        }

        $effectiveDate = $invoice->finalized_at ?? $invoice->created_at;

        // Weight EVERY item by its immutable line total so ineligible items keep their true share
        // (never redistributed to eligible items). Both the validated amount and the discount are
        // allocated on the same weights.
        $weights = [];
        foreach ($items as $item) {
            $weights[$item->ulid] = max(0, (int) $item->line_total_minor);
        }
        $validatedByItem = LargestRemainderAllocator::allocate((int) $event->validated_amount_minor, $weights);
        $discountByItem = LargestRemainderAllocator::allocate((int) $invoice->discount_minor, $weights);

        // Cache services (for service_category applicability) to avoid N+1.
        $serviceIds = array_values(array_unique(array_map(static fn (InvoiceItem $i): int => (int) $i->service_id, $items)));
        $serviceCategoryById = Service::query()->whereIn('id', $serviceIds)->pluck('category_id', 'id')->all();

        $computations = [];
        foreach ($items as $item) {
            if (! $item->eligible_for_commission || $item->staff_profile_id === null) {
                continue;
            }

            /** @var StaffProfile|null $staff */
            $staff = StaffProfile::query()
                ->whereKey($item->staff_profile_id)
                ->where('merchant_id', $event->merchant_id)
                ->first();
            if ($staff === null) {
                throw CompensationLedgerException::configurationInvariant('Invoice item references a staff profile outside the merchant.');
            }

            $plan = $this->resolvePlan->handle($staff, (int) $item->branch_id, $effectiveDate);
            if (! $plan instanceof PersonnelCompensationPlan) {
                continue; // no effective plan → this staff simply earns no commission here.
            }
            if (! $plan->compensation_model->requiresCommissionRule()) {
                continue; // salary_only → never a commission row.
            }

            // A commission-bearing plan MUST resolve an effective rule; a missing one fails closed
            // (CompensationResolutionException) rather than degrading to "no commission".
            $rule = $this->resolveRule->handle($plan, $effectiveDate);
            if (! $rule instanceof CommissionRule) {
                continue;
            }

            if (! $this->applicabilityMatches($rule, $item, $serviceCategoryById)) {
                continue;
            }

            $allocation = (int) ($validatedByItem[$item->ulid] ?? 0);
            if ($allocation <= 0) {
                continue; // nothing validated for this item yet (e.g. partial payment elsewhere).
            }

            $basisMinor = $this->basisMinor($rule, $item, (int) ($discountByItem[$item->ulid] ?? 0), $allocation);
            $currency = (string) $item->currency;

            $amount = $this->calculate($rule, $basisMinor, $allocation, $currency);
            if ($amount <= 0) {
                continue; // zero-basis / zero commission → no row.
            }

            $computations[] = new CommissionItemComputation(
                invoiceItemId: (int) $item->id,
                invoiceItemUlid: $item->ulid,
                invoiceId: (int) $invoice->id,
                serviceSessionId: (int) $item->service_session_id, // non-nullable on invoice_items
                staffProfileId: (int) $staff->id,
                staffProfileUlid: $staff->ulid,
                compensationPlanId: (int) $plan->id,
                compensationPlanUlid: $plan->ulid,
                commissionRuleId: (int) $rule->id,
                commissionRuleUlid: $rule->ulid,
                calculationType: $rule->calculation_type,
                calculationBasis: $rule->calculation_basis,
                calculationBasisMinor: $basisMinor,
                rateBasisPoints: $rule->calculation_type === CommissionCalculationType::Percentage ? (int) $rule->percentage_basis_points : null,
                fixedRateMinor: $rule->calculation_type === CommissionCalculationType::FixedAmount ? (int) $rule->fixed_amount_minor : null,
                eligibleValidatedAllocationMinor: $allocation,
                amountMinor: $amount,
                currency: $currency,
            );
        }

        return $computations;
    }

    /**
     * @param  array<int,int|null>  $serviceCategoryById
     */
    private function applicabilityMatches(CommissionRule $rule, InvoiceItem $item, array $serviceCategoryById): bool
    {
        return match ($rule->applies_to) {
            CommissionAppliesTo::AllServices => true,
            CommissionAppliesTo::ServiceCategory => ($serviceCategoryById[(int) $item->service_id] ?? null) === $rule->service_category_id,
            CommissionAppliesTo::SelectedServices => CommissionRuleService::query()
                ->where('commission_rule_id', $rule->id)
                ->where('service_id', $item->service_id)
                ->exists(),
        };
    }

    private function basisMinor(CommissionRule $rule, InvoiceItem $item, int $itemDiscountShare, int $allocation): int
    {
        $base = match ($rule->calculation_basis) {
            CommissionCalculationBasis::ServicePrice => (int) $item->unit_price_minor * (int) $item->quantity,
            CommissionCalculationBasis::InvoiceItemTotal => (int) $item->line_total_minor,
            CommissionCalculationBasis::NetAfterDiscount => max(0, (int) $item->line_total_minor - $itemDiscountShare),
            CommissionCalculationBasis::PaidAmount => $allocation,
        };

        if ($rule->applies_to_preferred_personnel_fee) {
            $base += (int) ($item->preferred_personnel_fee_minor ?? 0);
        }

        return $base;
    }

    private function calculate(CommissionRule $rule, int $basisMinor, int $allocation, string $currency): int
    {
        $amount = match ($rule->calculation_type) {
            CommissionCalculationType::Percentage => LargestRemainderAllocator::roundHalfUp(max(0, $basisMinor), (int) $rule->percentage_basis_points),
            CommissionCalculationType::FixedAmount => $this->fixedAmount($rule, $currency, $allocation),
        };

        // Per-item cap: earned commission never exceeds the item's eligible validated allocation.
        return min($amount, $allocation);
    }

    private function fixedAmount(CommissionRule $rule, string $currency, int $allocation): int
    {
        if ($rule->currency !== null && $rule->currency !== $currency) {
            throw CompensationLedgerException::configurationInvariant('Fixed commission currency does not match the invoice item currency.');
        }

        return min((int) $rule->fixed_amount_minor, $allocation);
    }
}
