<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Queue-entry cancellation validation (Plan §37; Phase 16B). A non-empty reason is
 * required (distinct from a no-show, which carries none).
 */
final class CancelQueueEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // QueueEntryPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
