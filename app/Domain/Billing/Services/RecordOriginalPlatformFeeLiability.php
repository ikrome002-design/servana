<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Enums\PlatformFeeBasisType;
use App\Domain\Billing\Enums\PlatformFeeEntryType;
use App\Domain\Billing\Enums\PlatformFeeLedgerStatus;
use App\Domain\Billing\Exceptions\PlatformFeeException;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Actions\ValidatePaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentValidationEvent;
use Carbon\CarbonImmutable;

/**
 * Creates the original `earned`/`pending` percentage platform-fee liability at Finance validation
 * (Plan §51; Phase 20E). Called INSIDE the {@see ValidatePaymentRecordingGroup}
 * transaction, after the invoice `validated_paid_minor` projection is updated and the
 * {@see PaymentValidationEvent} exists. The invoice must already be locked by the caller.
 *
 * Billability rule (using the immutable finalization snapshot on the invoice — never a re-resolved
 * configuration):
 *   - `validated_paid_amount` (customer_centric only): fee = round_half_up(event.validated_amount × rate);
 *   - finalization-snapshot bases: proportional release —
 *       cumulative_target = round_half_up(snapshot_gross × invoice.validated_paid_minor / invoice.total_minor);
 *       new_earned = cumulative_target − Σ prior earned gross (this invoice). The final validation captures
 *       the residual so cumulative earned == snapshot_gross.
 * A zero new-earned amount creates no monetary row. Fixed-only invoices (no snapshot) create nothing.
 */
final class RecordOriginalPlatformFeeLiability
{
    public function __construct(private readonly CalculatePlatformFee $calculator) {}

    public function record(Invoice $invoice, PaymentValidationEvent $event, CarbonImmutable $now): ?PlatformFeeLedgerEntry
    {
        // Fixed-only / no percentage fee snapshotted at finalization → nothing to earn.
        if ($invoice->platform_fee_configuration_id === null) {
            return null;
        }

        $tier = CanonicalPlatformFeeTier::from((string) $invoice->platform_fee_tier_snapshot);
        $basis = PlatformFeeBasisType::from((string) $invoice->platform_fee_basis_type_snapshot);
        $rate = (int) $invoice->platform_fee_rate_bps_snapshot;
        $split = $invoice->platform_fee_shared_split_snapshot === null ? null : (int) $invoice->platform_fee_shared_split_snapshot;
        $currency = (string) $invoice->platform_fee_currency;
        $snapshotGross = (int) $invoice->platform_fee_gross_minor;

        if ($basis === PlatformFeeBasisType::ValidatedPaidAmount) {
            // Gate 4.2 guarantees customer_centric here; the fee follows the newly validated amount.
            $fee = $this->calculator->calculate((int) $event->validated_amount_minor, $rate, $tier, $split, $currency);
            $newEarned = $fee->grossMinor;
        } else {
            $invoiceTotal = (int) $invoice->total_minor;
            if ($invoiceTotal <= 0) {
                if ($snapshotGross > 0) {
                    // A non-zero fee against a zero-total invoice would divide by zero / fabricate liability.
                    throw PlatformFeeException::missingConfiguration('proportional_release', $currency, $now->toDateString());
                }

                return null;
            }

            $cumulativeTarget = $this->roundHalfUp($snapshotGross * (int) $invoice->validated_paid_minor, $invoiceTotal);
            $previouslyEarned = (int) PlatformFeeLedgerEntry::query()
                ->where('source_invoice_id', $invoice->id)
                ->where('entry_type', PlatformFeeEntryType::Earned->value)
                ->sum('gross_platform_fee_minor');

            $newEarned = max(0, $cumulativeTarget - $previouslyEarned);
            $fee = $this->calculator->splitByTier($newEarned, $tier, $split, $currency);
        }

        // A zero-value earned portion writes no monetary ledger row.
        if ($newEarned <= 0) {
            return null;
        }

        return PlatformFeeLedgerEntry::create([
            'merchant_id' => $invoice->merchant_id,
            'branch_id' => $invoice->branch_id,
            'source_invoice_id' => $invoice->id,
            'source_invoice_item_id' => null, // invoice-level (group validation recognises the invoice amount)
            'entry_type' => PlatformFeeEntryType::Earned->value,
            'status' => PlatformFeeLedgerStatus::Pending->value,
            'billing_mode_snapshot' => (string) ($invoice->platform_fee_billing_mode_snapshot ?? BillingMode::PercentageOnMerchantClientInvoice->value),
            'service_fee_tier_snapshot' => $tier->value,
            'fee_basis_type' => $basis->value,
            'fee_basis_amount_minor' => $basis === PlatformFeeBasisType::ValidatedPaidAmount
                ? (int) $event->validated_amount_minor
                : $newEarned,
            'percentage_rate_snapshot' => $rate,
            'shared_split_snapshot' => $split,
            'gross_platform_fee_minor' => $fee->grossMinor,
            'client_shifted_amount_minor' => $fee->clientShiftedMinor,
            'merchant_absorbed_amount_minor' => $fee->merchantAbsorbedMinor,
            'merchant_liability_minor' => $fee->merchantLiabilityMinor,
            'currency' => $currency,
            'effective_configuration_id' => $invoice->platform_fee_configuration_id,
            'source_validation_event_id' => $event->id,
            'idempotency_key' => 'earned:'.$invoice->id.':'.$event->id,
            'billable_at' => $now,
        ]);
    }

    /** Round-half-up of numerator / denominator to integer minor units (ADR-005; denominator > 0). */
    private function roundHalfUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }
}
