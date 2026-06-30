<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Service-notes update validation (Plan §25.2; Phase 16C). Notes are free-text
 * operational context — never client contact. An empty/absent value clears the notes.
 * Authoritative values are never accepted from the body.
 */
final class UpdateServiceNotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ServiceSessionPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notes' => ['present', 'nullable', 'string', 'max:2000'],
        ];
    }
}
