<?php

declare(strict_types=1);

namespace App\Http\Requests\Invoicing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Invoice draft update validation (Plan §40; Phase 17). Accepts ONLY the replacement
 * set of completed service-session ULIDs (the client is fixed by the draft). All
 * authoritative money/price/personnel/currency/status values are derived server-side
 * from the locked sources and are NEVER accepted from the body.
 */
final class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // InvoicePolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'service_session_ids' => ['required', 'array', 'min:1', 'max:100'],
            'service_session_ids.*' => ['required', 'string', 'size:26', 'distinct'],
        ];
    }
}
