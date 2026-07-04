<?php

declare(strict_types=1);

namespace App\Http\Requests\FinanceDisputes;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Finance dispute creation body (Plan §44; Phase 18B). Links an invoice and/or a payment
 * record (at least one — enforced in the action) plus a mandatory reason and optional
 * private Phase 10F evidence file. All ULIDs; merchant/branch are derived server-side.
 */
final class CreateFinanceDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // FinanceDisputePolicy::create + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice' => ['sometimes', 'nullable', 'string', 'size:26'],
            'payment_record' => ['sometimes', 'nullable', 'string', 'size:26'],
            'evidence_file' => ['sometimes', 'nullable', 'string', 'size:26'],
            'reason' => ['required', 'string', 'min:3', 'max:480'],
        ];
    }
}
