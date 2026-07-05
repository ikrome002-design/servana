<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Flag an audit event body (Plan §13.2; Phase 19). Identifies the branch-scoped audit
 * row (public ULID) and an optional initial note. Policy + EnsurePermission are the
 * authorization boundary.
 */
final class FlagAuditEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'audit_log' => ['required', 'string', 'size:26'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
