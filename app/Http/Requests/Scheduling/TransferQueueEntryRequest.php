<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Queue-entry transfer validation (Plan §37; Phase 16B). A non-empty reason is
 * always required. Either a target personnel ULID (→ assigned) or an explicit
 * `return_to_waiting` flag (→ waiting) must be supplied; the target is revalidated
 * by the shared scheduling services in the action.
 */
final class TransferQueueEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // QueueEntryPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'personnel' => ['required_without:return_to_waiting', 'nullable', 'string', 'size:26'],
            'return_to_waiting' => ['required_without:personnel', 'nullable', 'boolean'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
