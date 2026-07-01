<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\CreateInvoiceDraft;
use App\Domain\Invoicing\Actions\UpdateInvoiceDraft;
use App\Domain\Invoicing\Contracts\PreferredPersonnelFeeResolver;
use App\Domain\Invoicing\Exceptions\InvoiceSourceException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Enums\Currency;

/**
 * Shared draft-composition logic for {@see CreateInvoiceDraft}
 * and {@see UpdateInvoiceDraft} (Phase 17). The single
 * place that validates completed-session sources (Gate A), derives each line's
 * price / personnel / preferred-personnel fee from the LOCKED authoritative
 * session+service (never the browser), persists the `invoice_items`, and recomputes
 * the draft header totals in integer minor units. MUST run inside the action's
 * transaction. No invoice number and no finalized timestamp are written here.
 */
final class InvoiceDraftComposer
{
    public function __construct(
        private readonly InvoiceTotalsCalculator $totals,
        private readonly PreferredPersonnelFeeResolver $preferredFee,
    ) {}

    /**
     * Validate the source sessions, (re)write the draft's items, and recompute its
     * header totals + currency. The caller must have already cleared any existing
     * items it is replacing.
     *
     * @param  list<ServiceSession>  $sessions
     */
    public function compose(Invoice $invoice, Client $client, array $sessions): void
    {
        if ($sessions === []) {
            throw InvoiceSourceException::noSources();
        }

        $currency = Currency::KES;
        $currencyResolved = false;
        $lineTotals = [];

        foreach ($sessions as $session) {
            /** @var ServiceSession $locked */
            $locked = ServiceSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ServiceSessionStatus::Completed) {
                throw InvoiceSourceException::invalidSessionState();
            }
            if (
                $locked->merchant_id !== $client->merchant_id
                || $locked->branch_id !== $client->branch_id
                || $locked->client_id !== $client->id
            ) {
                throw InvoiceSourceException::inconsistentSources();
            }
            // Already committed to another (or a still-present) invoice item.
            if (InvoiceItem::query()->where('service_session_id', $locked->id)->exists()) {
                throw InvoiceSourceException::alreadyInvoiced();
            }

            /** @var Service $service */
            $service = Service::query()->whereKey($locked->service_id)->firstOrFail();
            $serviceCurrency = Currency::from($service->currency);

            if (! $currencyResolved) {
                $currency = $serviceCurrency;
                $currencyResolved = true;
            } elseif ($serviceCurrency !== $currency) {
                throw InvoiceSourceException::inconsistentSources();
            }

            $fee = $this->preferredFee->resolve($locked, $service);
            $unitPrice = (int) $service->price_minor;

            $item = new InvoiceItem([
                'merchant_id' => $invoice->merchant_id,
                'branch_id' => $invoice->branch_id,
                'invoice_id' => $invoice->id,
                'service_session_id' => $locked->id,
                'service_id' => $service->id,
                'staff_profile_id' => $locked->staff_profile_id,
                'description' => $service->name,
                'quantity' => 1,
                'unit_price_minor' => $unitPrice,
                'line_total_minor' => $unitPrice,
                'preferred_personnel_fee_minor' => $fee->amountMinor,
                'eligible_for_commission' => true,
                'currency' => $currency->value,
            ]);
            $item->save();

            $lineTotals[] = ['line_total_minor' => $unitPrice, 'preferred_personnel_fee_minor' => $fee->amountMinor];
        }

        $computed = $this->totals->compute($lineTotals, $invoice->tax_minor, $invoice->discount_minor, $currency);

        $invoice->currency = $currency->value;
        $invoice->subtotal_minor = $computed->subtotalMinor;
        $invoice->preferred_personnel_fee_snapshot_minor = $computed->preferredFeeTotalMinor;
        $invoice->total_minor = $computed->totalMinor;
        $invoice->save();
    }
}
