<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Enums\PlatformFeeBasisType;
use App\Domain\Invoicing\ValueObjects\InvoiceTotals;

/**
 * Resolves the server-owned invoice-level fee-basis amount (minor units) from the locked Phase 17
 * finalization totals (Plan §51, Scope §6.3.2; Phase 20E). Never browser-supplied.
 *
 *   merchant_client_invoice_service_subtotal = Σ service line nets (subtotal)
 *   merchant_client_invoice_total            = the PRE-platform-fee invoice total (subtotal + tax
 *                                              − discount + preferred fee) — before the Phase 20E
 *                                              client-shifted amount is added (non-circular, §4.1)
 *   net_after_discount                       = subtotal − discount
 *   invoice_item_subtotal                    = Σ item line totals (= subtotal at invoice level; item
 *                                              provenance is allocated per line total)
 *   validated_paid_amount                    = projected on the invoice total at finalization
 *                                              (customer_centric only; actual accrual is per validation)
 */
final class ResolvePlatformFeeBasis
{
    public function invoiceLevelAmount(PlatformFeeBasisType $basis, InvoiceTotals $totals): int
    {
        return match ($basis) {
            PlatformFeeBasisType::MerchantClientInvoiceServiceSubtotal => $totals->subtotalMinor,
            PlatformFeeBasisType::MerchantClientInvoiceTotal => $totals->totalMinor,
            PlatformFeeBasisType::NetAfterDiscount => $totals->subtotalMinor - $totals->discountMinor,
            PlatformFeeBasisType::InvoiceItemSubtotal => $totals->subtotalMinor,
            PlatformFeeBasisType::ValidatedPaidAmount => $totals->totalMinor,
        };
    }
}
