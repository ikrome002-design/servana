<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Component reference-correction body (Plan §42; Phase 18B). The new reference is
 * required; method-aware validation + normalization + the durable duplicate re-check
 * happen in the action. The full/normalized reference is never echoed back. The target
 * component is the route-bound {paymentRecord}; permission + branch scope + idempotency
 * are the route boundary; maker != checker is enforced in the action.
 */
final class CorrectPaymentReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PaymentRecordingGroupPolicy::correctReference + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'min:1', 'max:190'],
        ];
    }
}
