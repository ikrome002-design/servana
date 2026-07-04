<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Enums\CommissionHandoffKind;
use App\Domain\Compensation\Models\CommissionHandoffEvent;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Refunds\Models\Refund;
use Carbon\CarbonInterface;

/**
 * Writes the durable per-component commission handoff seam for Phase 20G (Gate C/E;
 * Plan §5.3 REM-COMP-001 seam only). Called INSIDE the validation / refund-finalization
 * transaction. It records one immutable, idempotent row per component — carrying NO
 * commission rate, earned row, or payable liability. This is NOT a commission ledger.
 * The partial unique indexes make each (source, component) row idempotent, so a retry
 * cannot double-write.
 */
final class CommissionHandoffWriter
{
    /**
     * One `validated_allocation` row per validated component, at validation time.
     */
    public function recordValidatedAllocation(PaymentValidationEvent $event, PaymentRecord $component, CarbonInterface $effectiveAt): CommissionHandoffEvent
    {
        return CommissionHandoffEvent::create([
            'merchant_id' => $component->merchant_id,
            'branch_id' => $component->branch_id,
            'kind' => CommissionHandoffKind::ValidatedAllocation,
            'payment_validation_event_id' => $event->id,
            'refund_id' => null,
            'payment_record_id' => $component->id,
            'invoice_id' => $component->invoice_id,
            // 18A allocations are invoice-level; item/service/personnel attribution is
            // resolved by Phase 20G where item-level allocation exists. Null here means
            // "attribute across the invoice", never an invented mapping.
            'invoice_item_id' => null,
            'service_id' => null,
            'staff_profile_id' => null,
            'amount_minor' => (int) ($component->validated_amount_minor ?? $component->amount_minor),
            'currency' => $component->currency,
            'effective_at' => $effectiveAt,
        ]);
    }

    /**
     * One `reversal` row per reversed component, at refund-finalization time
     * (proportional amount computed by the caller via largest-remainder allocation).
     */
    public function recordReversal(Refund $refund, PaymentRecord $component, int $reversedAmountMinor, CarbonInterface $effectiveAt): CommissionHandoffEvent
    {
        return CommissionHandoffEvent::create([
            'merchant_id' => $component->merchant_id,
            'branch_id' => $component->branch_id,
            'kind' => CommissionHandoffKind::Reversal,
            'payment_validation_event_id' => null,
            'refund_id' => $refund->id,
            'payment_record_id' => $component->id,
            'invoice_id' => $component->invoice_id,
            'invoice_item_id' => null,
            'service_id' => null,
            'staff_profile_id' => null,
            'amount_minor' => $reversedAmountMinor,
            'currency' => $component->currency,
            'effective_at' => $effectiveAt,
        ]);
    }
}
