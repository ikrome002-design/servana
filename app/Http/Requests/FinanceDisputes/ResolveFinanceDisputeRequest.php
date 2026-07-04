<?php

declare(strict_types=1);

namespace App\Http\Requests\FinanceDisputes;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Finance dispute resolve/reject body (Plan §44; Phase 18B). A resolution note is
 * mandatory for both resolve and reject.
 */
final class ResolveFinanceDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // FinanceDisputePolicy::transition + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
