<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Clients\Models\Client;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Masked own-scope commission fact. Every money/calculation field is a frozen server fact. */
final class PersonnelCommissionLedgerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommissionLedgerEntry $entry */
        $entry = $this->resource;
        /** @var Invoice|null $invoice */
        $invoice = $entry->invoice;
        /** @var Client|null $client */
        $client = $invoice?->client;
        /** @var InvoiceItem|null $item */
        $item = $entry->invoiceItem;

        return [
            'id' => $entry->ulid,
            'entry_type' => $entry->entry_type->value,
            'status' => $entry->status->value,
            'amount_minor' => $entry->amount_minor,
            'currency' => $entry->currency,
            'calculation_basis_minor' => $entry->calculation_basis_minor,
            'rate_basis_points' => $entry->rate_basis_points,
            'fixed_rate_minor' => $entry->fixed_rate_minor,
            'reversal_reason' => $entry->reversal_reason?->value,
            'earned_at' => $entry->earned_at?->toIso8601String(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'invoice' => $invoice === null ? null : [
                'id' => $invoice->ulid,
                'number' => $invoice->invoice_number,
            ],
            'service' => $item?->service === null ? null : [
                'id' => $item->service->ulid,
                'name' => $item->service->name,
            ],
            'session_id' => $item?->serviceSession?->ulid,
            'client' => $client === null ? null : [
                'id' => $client->ulid,
                'full_name' => $client->full_name,
                'phone_masked' => $client->maskedPhone(),
            ],
        ];
    }
}
