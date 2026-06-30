<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Queue configuration validation (Plan §37; Phase 16B). Branch Manager only
 * (authorized upstream). All fields are optional (partial update); `queue_capacity`
 * may be null (no cap) or a positive integer; the default mode is next_available or
 * manual (preferred_personnel is a per-client request, never a branch default).
 * Capacity-below-active is rejected in the action. `branch_id` disambiguates a
 * multi-branch operator.
 */
final class UpdateQueueConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // QueueConfigurationPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'queue_is_open' => ['sometimes', 'boolean'],
            'queue_capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'queue_default_assignment_mode' => ['sometimes', 'string', 'in:next_available,manual'],
        ];
    }
}
