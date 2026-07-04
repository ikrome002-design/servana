<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reject / request-correction decision body (Plan §42; Phase 18B). A non-empty reason
 * is mandatory (sanitized + length-capped in the action). The checker is the
 * authenticated Finance user (server-derived); the target is the route-bound
 * {paymentRecordingGroup}. Permission + branch scope + idempotency are the route-level
 * boundary; maker != checker is enforced in the action.
 */
final class PaymentGroupDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PaymentRecordingGroupPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:480'],
        ];
    }
}
