<?php

declare(strict_types=1);

namespace App\Http\Requests\Invoicing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Invoice draft creation validation (Plan §40; Phase 17). Accepts ONLY the client
 * ULID and one-or-more completed service-session ULIDs. All authoritative values
 * (merchant/branch/status/invoice_number/totals/prices/personnel/currency/preferred
 * fee/finalized_at/created_by/validated_paid) are derived server-side from the locked
 * sources and are NEVER accepted from the body.
 */
final class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // InvoicePolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'string', 'size:26'],
            'service_session_ids' => ['required', 'array', 'min:1', 'max:100'],
            'service_session_ids.*' => ['required', 'string', 'size:26', 'distinct'],
        ];
    }
}
