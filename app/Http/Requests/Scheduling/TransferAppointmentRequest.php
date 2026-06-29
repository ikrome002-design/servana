<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Transfer-appointment validation (Plan §36). The target personnel is a public
 * staff ULID (must differ from the current assignee — enforced in the action) and
 * an optional reason is sanitised before it reaches the audit record. Front Office
 * only; Branch Manager is rejected by the policy.
 */
final class TransferAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AppointmentPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'personnel' => ['required', 'string', 'size:26'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
