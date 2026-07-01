<?php

declare(strict_types=1);

namespace App\Http\Requests\Invoicing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared mandatory-reason validation for the Finance void-request and adjustment
 * actions (Plan §40, Scope §4.5.2; Phase 17). A non-empty sanitised reason is
 * required; no authoritative invoice value is ever accepted from the body.
 */
final class InvoiceReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // InvoicePolicy + EnsurePermission + RequireFreshMfa are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
