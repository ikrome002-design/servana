<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancel-appointment validation (Plan §36, §25.2). A reason is optional before
 * check-in and REQUIRED after check-in (`cancelled_with_reason`) — the after-
 * check-in requirement is enforced in the action against the locked current state
 * (the request cannot see it), where a missing reason returns 422. The reason is
 * sanitised before it reaches the audit record.
 */
final class CancelAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AppointmentPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
