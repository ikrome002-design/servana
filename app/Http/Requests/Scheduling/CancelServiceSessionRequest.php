<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Service-session cancellation validation (Plan §25.2; Phase 16C). A non-empty reason
 * is required. Authoritative values (merchant/branch/status/timestamps/actor) are
 * never accepted from the body.
 */
final class CancelServiceSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ServiceSessionPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
