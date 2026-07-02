<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Finance duplicate-reference override validation (Plan §41, Gate C; Phase 18A). A
 * non-empty reason is mandatory and is sanitized + length-capped in the action. The
 * override actor is the authenticated Finance user (server-derived); the target
 * check is the route-bound {paymentReferenceCheck}. Permission + MFA + fresh step-up
 * are enforced by the route middleware, not the body.
 */
final class ApproveDuplicateReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PaymentRecordingGroupPolicy + EnsurePermission + RequireFreshMfa are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:480'],
        ];
    }
}
