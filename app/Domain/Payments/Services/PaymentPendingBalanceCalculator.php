<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Models\PaymentRecordingGroup;

/**
 * Available-to-record capacity, in integer minor units (Plan §41; Phase 18A).
 *
 *   validated_balance   = invoice.total_minor − invoice.validated_paid_minor
 *   active_pending_total= Σ totals of the invoice's non-terminal, not-yet-validated
 *                         groups (status ∈ {recorded, pending_validation})
 *   available_to_record = validated_balance − active_pending_total
 *
 * MUST be called while the invoice row is locked FOR UPDATE (the invoice lock is the
 * concurrency authority — two concurrent recordings cannot collectively exceed the
 * balance). An optional group id is excluded (self) when re-checking an existing
 * group. Never reads a Phase-18B validated-payment table.
 */
final class PaymentPendingBalanceCalculator
{
    public function validatedBalanceMinor(Invoice $invoice): int
    {
        return $invoice->total_minor - $invoice->validated_paid_minor;
    }

    public function activePendingTotalMinor(Invoice $invoice, ?int $excludeGroupId = null): int
    {
        $query = PaymentRecordingGroup::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', PaymentRecordingGroupStatus::values(
                PaymentRecordingGroupStatus::activePendingStatuses(),
            ));

        if ($excludeGroupId !== null) {
            $query->whereKeyNot($excludeGroupId);
        }

        return (int) $query->sum('total_amount_minor');
    }

    public function availableToRecordMinor(Invoice $invoice, ?int $excludeGroupId = null): int
    {
        return $this->validatedBalanceMinor($invoice) - $this->activePendingTotalMinor($invoice, $excludeGroupId);
    }
}
