<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Services;

use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Models\Refund;

/**
 * Remaining-refundable calculation for a validated payment component (Plan §44; Gate E;
 * Phase 18B). The remaining refundable amount is the component's validated amount minus
 * every finalized AND in-flight (requested/approved) refund allocated to it, so a
 * component can never be over-refunded across concurrent requests. Integer minor units.
 */
final class RefundableBalanceCalculator
{
    /** Sum of finalized + in-flight refunds already allocated to the component. */
    public function committedMinor(PaymentRecord $component, ?int $excludeRefundId = null): int
    {
        return (int) Refund::query()
            ->where('payment_record_id', $component->id)
            ->whereIn('status', [RefundStatus::Requested->value, RefundStatus::Approved->value, RefundStatus::Finalized->value])
            ->when($excludeRefundId !== null, fn ($q) => $q->where('id', '!=', $excludeRefundId))
            ->sum('amount_minor');
    }

    /** Remaining refundable validated amount for the component. */
    public function remainingMinor(PaymentRecord $component, ?int $excludeRefundId = null): int
    {
        $validated = (int) ($component->validated_amount_minor ?? 0);

        return $validated - $this->committedMinor($component, $excludeRefundId);
    }
}
