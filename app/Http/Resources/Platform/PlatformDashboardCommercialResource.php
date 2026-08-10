<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commercial/billing section of the Super Administrator dashboard (Phase UI-08 §5.4.1).
 *
 * `open_invoice_balance_minor` is summed from the STORED invoice snapshots in integer minor units.
 * Nothing here recalculates an invoice: an issued invoice is immutable, and a dashboard that
 * recomputed one could disagree with the invoice the merchant was actually sent.
 *
 * See PlatformDashboardLifecycleResource for why each section is its own resource.
 *
 * @property-read array<string,mixed> $resource
 */
final class PlatformDashboardCommercialResource extends JsonResource
{
    /**
     * @return array{
     *     availability:string, gate:string|null, as_of:string,
     *     invoices_by_status:array<string,int>, issued_invoices:int,
     *     open_invoice_balance_minor:int, definitions:array<string,string>,
     *     time_range:string, drill_through:string
     * }
     */
    public function toArray(Request $request): array
    {
        $section = $this->resource;

        return [
            'availability' => (string) $section['availability'],
            'gate' => $section['gate'] === null ? null : (string) $section['gate'],
            'as_of' => (string) $section['as_of'],
            'invoices_by_status' => (array) $section['invoices_by_status'],
            'issued_invoices' => (int) $section['issued_invoices'],
            'open_invoice_balance_minor' => (int) $section['open_invoice_balance_minor'],
            'definitions' => (array) $section['definitions'],
            'time_range' => (string) $section['time_range'],
            'drill_through' => (string) $section['drill_through'],
        ];
    }
}
