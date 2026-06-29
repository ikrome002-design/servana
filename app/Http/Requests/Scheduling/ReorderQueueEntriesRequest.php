<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Queue reorder validation (Plan §37; Phase 16B). `order` is the COMPLETE ordered
 * list of active waiting queue-entry ULIDs for one branch. Completeness, duplicates,
 * foreign/terminal entries, and stale snapshots are enforced in the action /
 * QueuePositionService (deterministic 409 on a stale set). `branch_id` is an
 * optional branch ULID used only to disambiguate a multi-branch operator.
 */
final class ReorderQueueEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // QueueEntryPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'string', 'size:26'],
        ];
    }
}
